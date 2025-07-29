# Your First State Machine

In this tutorial, we'll build a complete state machine step by step. We'll create a **Document Workflow** machine that handles the lifecycle of a document from creation to publication.

## Planning Our Machine

Before writing code, let's plan our document workflow:

**States:**
- `draft` - Document is being written
- `review` - Document is under review
- `revision` - Document needs changes
- `approved` - Document is approved
- `published` - Document is live

**Events:**
- `SUBMIT_FOR_REVIEW` - Move from draft to review
- `APPROVE` - Approve the document
- `REQUEST_REVISION` - Request changes
- `REVISE` - Submit revisions
- `PUBLISH` - Make document public

## Step 1: Basic Machine Structure

Let's start with a simple structure:

```php
<?php

namespace App\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class DocumentWorkflowMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'draft',
                'states' => [
                    'draft' => [
                        'on' => [
                            'SUBMIT_FOR_REVIEW' => 'review'
                        ]
                    ],
                    'review' => [
                        'on' => [
                            'APPROVE' => 'approved',
                            'REQUEST_REVISION' => 'revision'
                        ]
                    ],
                    'revision' => [
                        'on' => [
                            'REVISE' => 'review'
                        ]
                    ],
                    'approved' => [
                        'on' => [
                            'PUBLISH' => 'published'
                        ]
                    ],
                    'published' => [
                        // Final state - no outgoing transitions
                    ]
                ]
            ]
        );
    }
}
```

### Testing the Basic Flow

```php
// Create a new document workflow
$workflow = DocumentWorkflowMachine::create();
echo $workflow->state->value; // 'draft'

// Submit for review
$workflow = $workflow->send('SUBMIT_FOR_REVIEW');
echo $workflow->state->value; // 'review'

// Request revision
$workflow = $workflow->send('REQUEST_REVISION');
echo $workflow->state->value; // 'revision'

// Submit revision
$workflow = $workflow->send('REVISE');
echo $workflow->state->value; // 'review'

// Approve and publish
$workflow = $workflow->send('APPROVE');
$workflow = $workflow->send('PUBLISH');
echo $workflow->state->value; // 'published'
```

## Step 2: Adding Context

Now let's add data that travels with our machine:

```php
<?php

namespace App\Contexts;

use Tarfinlabs\EventMachine\ContextManager;
use Spatie\LaravelData\Optional;

class DocumentContext extends ContextManager
{
    public function __construct(
        public string|Optional $title,
        public string|Optional $content,
        public string|Optional $author,
        public string|Optional $reviewer,
        public array|Optional $revisionNotes,
        public int|Optional $revisionCount
    ) {
        parent::__construct();
        
        // Set defaults for Optional values
        if ($this->title instanceof Optional) {
            $this->title = '';
        }
        if ($this->content instanceof Optional) {
            $this->content = '';
        }
        if ($this->revisionNotes instanceof Optional) {
            $this->revisionNotes = [];
        }
        if ($this->revisionCount instanceof Optional) {
            $this->revisionCount = 0;
        }
    }
}
```

Update the machine to use context:

```php
return MachineDefinition::define(
    config: [
        'initial' => 'draft',
        'context' => DocumentContext::class,
        'states' => [
            // ... same states as before
        ]
    ]
);
```

## Step 3: Adding Actions

Actions are side effects that occur during transitions. Let's create some actions:

```php
<?php

namespace App\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use App\Contexts\DocumentContext;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

class AssignReviewerAction extends ActionBehavior
{
    public function __invoke(DocumentContext $context, EventDefinition $event): void
    {
        $context->reviewer = $event->payload['reviewer'] ?? 'default-reviewer';
    }
}

class AddRevisionNoteAction extends ActionBehavior
{
    public function __invoke(DocumentContext $context, EventDefinition $event): void
    {
        $context->revisionNotes[] = [
            'note' => $event->payload['note'],
            'timestamp' => now()->toISOString(),
            'reviewer' => $context->reviewer
        ];
        $context->revisionCount++;
    }
}

class PublishDocumentAction extends ActionBehavior
{
    public function __invoke(DocumentContext $context): void
    {
        // Log publication
        logger()->info('Document published', [
            'title' => $context->title,
            'author' => $context->author,
            'revision_count' => $context->revisionCount
        ]);
    }
}
```

Update the machine with actions:

```php
return MachineDefinition::define(
    config: [
        'initial' => 'draft',
        'context' => DocumentContext::class,
        'states' => [
            'draft' => [
                'on' => [
                    'SUBMIT_FOR_REVIEW' => [
                        'target' => 'review',
                        'actions' => AssignReviewerAction::class
                    ]
                ]
            ],
            'review' => [
                'on' => [
                    'APPROVE' => 'approved',
                    'REQUEST_REVISION' => [
                        'target' => 'revision',
                        'actions' => AddRevisionNoteAction::class
                    ]
                ]
            ],
            'revision' => [
                'on' => [
                    'REVISE' => 'review'
                ]
            ],
            'approved' => [
                'on' => [
                    'PUBLISH' => [
                        'target' => 'published',
                        'actions' => PublishDocumentAction::class
                    ]
                ]
            ],
            'published' => []
        ]
    ]
);
```

## Step 4: Adding Guards

Guards are conditions that must be met for transitions to occur:

```php
<?php

namespace App\Guards;

use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use App\Contexts\DocumentContext;

class HasContentGuard extends GuardBehavior
{
    public function __invoke(DocumentContext $context): bool
    {
        return !empty($context->title) && !empty($context->content);
    }
}

class HasAuthorityToApproveGuard extends GuardBehavior
{
    public function __invoke(DocumentContext $context, $event): bool
    {
        $approver = $event->payload['approver'] ?? '';
        return $approver === $context->reviewer;
    }
}
```

Add guards to the machine:

```php
'states' => [
    'draft' => [
        'on' => [
            'SUBMIT_FOR_REVIEW' => [
                'target' => 'review',
                'guards' => HasContentGuard::class,
                'actions' => AssignReviewerAction::class
            ]
        ]
    ],
    'review' => [
        'on' => [
            'APPROVE' => [
                'target' => 'approved',
                'guards' => HasAuthorityToApproveGuard::class
            ],
            'REQUEST_REVISION' => [
                'target' => 'revision',
                'actions' => AddRevisionNoteAction::class
            ]
        ]
    ],
    // ... rest of states
]
```

## Step 5: Using with Eloquent Models

Let's integrate with a Laravel model:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Casts\MachineCast;
use App\Machines\DocumentWorkflowMachine;

class Document extends Model
{
    protected $fillable = [
        'title',
        'content',
        'author_id',
        'workflow_state'
    ];

    protected $casts = [
        'workflow_state' => MachineCast::class.':'.DocumentWorkflowMachine::class
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    // Helper methods
    public function submitForReview(string $reviewer): self
    {
        $this->workflow_state = $this->workflow_state->send('SUBMIT_FOR_REVIEW', [
            'reviewer' => $reviewer
        ]);
        $this->save();
        
        return $this;
    }

    public function approve(string $approver): self
    {
        $this->workflow_state = $this->workflow_state->send('APPROVE', [
            'approver' => $approver
        ]);
        $this->save();
        
        return $this;
    }

    public function requestRevision(string $note): self
    {
        $this->workflow_state = $this->workflow_state->send('REQUEST_REVISION', [
            'note' => $note
        ]);
        $this->save();
        
        return $this;
    }
}
```

## Step 6: Creating and Using Documents

```php
// Create a new document
$document = Document::create([
    'title' => 'My First Article',
    'content' => 'This is the content of my article...',
    'author_id' => auth()->id(),
    'workflow_state' => DocumentWorkflowMachine::create([
        'title' => 'My First Article',
        'content' => 'This is the content...',
        'author' => auth()->user()->name
    ])
]);

// Check current state
echo $document->workflow_state->state->value; // 'draft'

// Submit for review
$document->submitForReview('reviewer@example.com');
echo $document->workflow_state->state->value; // 'review'

// Request revision
$document->requestRevision('Please add more details in section 2.');
echo $document->workflow_state->state->value; // 'revision'

// Check revision notes
$context = $document->workflow_state->state->context;
echo count($context['revisionNotes']); // 1
```

## Step 7: Advanced Features

### Multiple Actions
Execute multiple actions in sequence:

```php
'SUBMIT_FOR_REVIEW' => [
    'target' => 'review',
    'guards' => HasContentGuard::class,
    'actions' => [
        AssignReviewerAction::class,
        'notifyReviewer',
        'logSubmission'
    ]
]
```

### Inline Actions
Define simple actions inline:

```php
'actions' => [
    'notifyReviewer' => function (DocumentContext $context): void {
        // Send notification to reviewer
        Mail::to($context->reviewer)->send(new ReviewRequestMail($context));
    },
    'logSubmission' => function (DocumentContext $context): void {
        Log::info('Document submitted for review', ['title' => $context->title]);
    }
]
```

### Event Validation
Create custom event classes with validation:

```php
<?php

namespace App\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class SubmitForReviewEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'SUBMIT_FOR_REVIEW';
    }

    public function validatePayload(): array
    {
        return [
            'reviewer' => 'required|email'
        ];
    }
}
```

Register the event in your machine:

```php
behavior: [
    'events' => [
        'SUBMIT_FOR_REVIEW' => SubmitForReviewEvent::class
    ],
    'actions' => [
        // ... your actions
    ]
]
```

## Testing Your Machine

```php
<?php

use Tests\TestCase;
use App\Machines\DocumentWorkflowMachine;

class DocumentWorkflowTest extends TestCase
{
    public function test_document_workflow_happy_path()
    {
        $machine = DocumentWorkflowMachine::create([
            'title' => 'Test Document',
            'content' => 'Test content',
            'author' => 'Test Author'
        ]);

        // Should start in draft
        $this->assertEquals('draft', $machine->state->value);

        // Submit for review
        $machine = $machine->send('SUBMIT_FOR_REVIEW', ['reviewer' => 'reviewer@test.com']);
        $this->assertEquals('review', $machine->state->value);
        $this->assertEquals('reviewer@test.com', $machine->state->context['reviewer']);

        // Approve
        $machine = $machine->send('APPROVE', ['approver' => 'reviewer@test.com']);
        $this->assertEquals('approved', $machine->state->value);

        // Publish
        $machine = $machine->send('PUBLISH');
        $this->assertEquals('published', $machine->state->value);
    }

    public function test_cannot_submit_without_content()
    {
        $machine = DocumentWorkflowMachine::create(['title' => '', 'content' => '']);

        $this->expectException(MachineValidationException::class);
        $machine->send('SUBMIT_FOR_REVIEW', ['reviewer' => 'reviewer@test.com']);
    }
}
```

## Next Steps

Congratulations! You've built a complete state machine. Now explore:

- [States and Transitions](../concepts/states-and-transitions.md) - Deep dive into state concepts
- [Context Management](../concepts/context.md) - Advanced context patterns
- [Testing](../testing/) - Comprehensive testing strategies
- [Examples](../examples/) - More real-world examples