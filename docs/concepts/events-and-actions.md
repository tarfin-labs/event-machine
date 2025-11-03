# Events and Actions

Events trigger state transitions, while actions perform side effects during those transitions. Together, they form the dynamic behavior of your state machine.

## Events

**Events** are signals that can trigger state transitions. They carry information (payload) and tell the machine "something happened."

### Event Types

#### 1. String Events

The simplest form - just a string identifier:

```php
'on' => [
    'START' => 'running',
    'STOP' => 'stopped',
    'RESET' => 'idle'
]
```

#### 2. Event Classes

More sophisticated events with validation and structure:

```php
<?php

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class UserLoginEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'USER_LOGIN';
    }

    public function validatePayload(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|min:8'
        ];
    }
}
```

Register the event in your machine:

```php
behavior: [
    'events' => [
        'USER_LOGIN' => UserLoginEvent::class
    ]
]
```

#### 3. Internal Events

Special events that are handled internally:

- `@always` - Fires immediately when entering a state
- `@entry` - Alias for entry actions
- `@exit` - Alias for exit actions

### Sending Events

#### Basic Event Sending

```php
$machine = $machine->send('START');
```

#### Events with Payload

```php
$machine = $machine->send('UPDATE_PROFILE', [
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);
```

#### Multiple Events

```php
$machine = $machine->send(['SAVE', 'NOTIFY']);
```

### Event Validation

EventMachine automatically validates event payloads when using event classes:

```php
class CreateOrderEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'CREATE_ORDER';
    }

    public function validatePayload(): array
    {
        return [
            'customer_id' => 'required|integer|exists:customers,id',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'total' => 'required|numeric|min:0'
        ];
    }

    public function getErrorMessage(): string
    {
        return 'Invalid order data provided';
    }
}
```

If validation fails, a `MachineEventValidationException` is thrown.

## Actions

**Actions** are side effects that occur during state transitions. They can modify context, call external services, or perform any other operations.

### Action Types

#### 1. Inline Functions

Define actions directly in the machine definition:

```php
behavior: [
    'actions' => [
        'incrementCounter' => function (ContextManager $context): void {
            $context->count++;
        },
        'logTransition' => function (ContextManager $context, EventDefinition $event): void {
            Log::info('State transition', [
                'event' => $event->type,
                'payload' => $event->payload
            ]);
        }
    ]
]
```

#### 2. Action Classes

Create dedicated action classes for complex logic:

```php
<?php

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class SendWelcomeEmailAction extends ActionBehavior
{
    public function __invoke(UserContext $context, EventDefinition $event): void
    {
        Mail::to($context->email)->send(new WelcomeEmail($context->name));
        
        $context->welcomeEmailSent = true;
        $context->welcomeEmailSentAt = now();
    }
}
```

#### 3. Service Container Integration

Actions can use Laravel's service container for dependency injection:

```php
class ProcessPaymentAction extends ActionBehavior
{
    public function __construct(
        private PaymentService $paymentService,
        private NotificationService $notificationService
    ) {}

    public function __invoke(OrderContext $context, EventDefinition $event): void
    {
        $result = $this->paymentService->charge(
            $context->paymentMethod,
            $context->total
        );

        if ($result->successful()) {
            $context->paymentId = $result->id;
            $context->paidAt = now();
            
            $this->notificationService->send(
                $context->customerEmail,
                'Payment Successful'
            );
        } else {
            throw new PaymentFailedException($result->error);
        }
    }
}
```

### Action Execution Context

Actions receive different parameters based on their needs:

```php
// Just context
function (ContextManager $context): void { }

// Context and event
function (ContextManager $context, EventDefinition $event): void { }

// Context and state
function (ContextManager $context, StateDefinition $state): void { }

// All three
function (ContextManager $context, EventDefinition $event, StateDefinition $state): void { }

// Custom context type
function (CustomContext $context, EventDefinition $event): void { }
```

### Multiple Actions

Execute multiple actions in sequence:

```php
'on' => [
    'PLACE_ORDER' => [
        'target' => 'processing',
        'actions' => [
            'validateOrder',
            'reserveInventory',
            'processPayment',
            'sendConfirmation'
        ]
    ]
]
```

Actions execute in the order specified. If any action throws an exception, the transition is rolled back.

### Entry and Exit Actions

Actions that run when entering or leaving states:

```php
'states' => [
    'processing' => [
        'entry' => [
            'startProcessing',
            'notifyWarehouse'
        ],
        'exit' => [
            'cleanup',
            'logCompletion'
        ]
    ]
]
```

## Advanced Event Patterns

### Event Aliases

Create shortcuts for commonly used events:

```php
behavior: [
    'events' => [
        'START' => StartProcessingEvent::class,
        'BEGIN' => StartProcessingEvent::class, // Alias
        'INIT' => StartProcessingEvent::class   // Another alias
    ]
]
```

### Conditional Events

Events that only fire under certain conditions:

```php
'on' => [
    'PROCESS' => [
        [
            'target' => 'premium_processing',
            'guards' => 'isPremiumCustomer',
            'actions' => 'processPremium'
        ],
        [
            'target' => 'standard_processing',
            'actions' => 'processStandard'
        ]
    ]
]
```

### Delayed Events

Schedule events to fire later using Laravel's queue system:

```php
class ScheduleReminderAction extends ActionBehavior
{
    public function __invoke(AppointmentContext $context): void
    {
        // Schedule a job using Laravel's queue (not internal event queue)
        dispatch(new SendReminderJob($context->appointmentId))
            ->delay($context->reminderTime);
    }
}

// In the job, you can send an event to the machine
class SendReminderJob implements ShouldQueue
{
    public function handle(): void
    {
        // Find the machine and send an event
        $machine = AppointmentMachine::find($this->appointmentId);
        $machine->send('REMINDER_SENT');
    }
}
```

> **Note:** Delayed processing requires Laravel's queue system. The internal event queue only handles immediate, in-memory events during the current execution.

## Advanced Action Patterns

### Action Chains

Chain actions together with shared data:

```php
class ProcessOrderAction extends ActionBehavior
{
    public function __invoke(OrderContext $context, EventDefinition $event): void
    {
        // Process the order
        $orderId = $this->createOrder($context);
        
        // Make order ID available to subsequent actions
        $event->payload['orderId'] = $orderId;
        $context->orderId = $orderId;
    }
}

class SendConfirmationAction extends ActionBehavior
{
    public function __invoke(OrderContext $context, EventDefinition $event): void
    {
        // Use the order ID from previous action
        $this->sendConfirmation($context->orderId);
    }
}
```

### Conditional Actions

Actions that only execute under certain conditions:

```php
behavior: [
    'actions' => [
        'conditionalNotify' => function (UserContext $context): void {
            if ($context->emailNotificationsEnabled) {
                Mail::to($context->email)->send(new UpdateNotification());
            }
        }
    ]
]
```

### Async Actions

Actions can dispatch jobs to Laravel's queue system for background processing:

```php
class ProcessLargeFileAction extends ActionBehavior
{
    public function __invoke(FileContext $context): void
    {
        // Dispatch to Laravel's queue (Redis/database)
        // This is DIFFERENT from EventMachine's internal event queue
        ProcessFileJob::dispatch($context->fileId)
            ->onQueue('file-processing');

        // The action completes immediately
        // The job will be processed later by a queue worker
        $context->processingStarted = true;
        $context->processingStartedAt = now();
    }
}
```

> **Note:** Laravel's job queue is for long-running or background tasks. For immediate state transitions within the same execution, use `raise()` to add events to the internal event queue.

### Error Handling in Actions

Handle errors gracefully:

```php
class ReliableApiCallAction extends ActionBehavior
{
    public function __invoke(ApiContext $context, EventDefinition $event): void
    {
        try {
            $response = Http::timeout(30)->post('/api/endpoint', $event->payload);
            
            if ($response->successful()) {
                $context->apiResponse = $response->json();
                $context->lastApiCall = now();
            } else {
                throw new ApiException('API call failed: ' . $response->status());
            }
        } catch (Exception $e) {
            Log::error('API call failed', [
                'error' => $e->getMessage(),
                'payload' => $event->payload
            ]);
            
            // Re-throw to trigger error handling
            throw $e;
        }
    }
}
```

## Event Processing Order

Understanding how EventMachine processes events and executes actions is crucial for building predictable state machines. EventMachine follows **run-to-completion semantics**, a foundational principle in state machine theory.

### Run-to-Completion Semantics

When a transition occurs, EventMachine processes it completely before handling any other events. This ensures deterministic behavior and prevents race conditions.

#### Execution Flow

When a transition is triggered, actions execute in this specific order:

1. **Transition Actions** - Actions defined on the transition itself
2. **Exit Actions** - Actions from the source state
3. **Entry Actions** - Actions from the target state
4. **Raised Events** - Any events raised during steps 1-3 are processed

```php
'states' => [
    'loading' => [
        'on' => [
            'SUCCESS' => [
                'target' => 'ready',
                'actions' => 'logTransition'  // 1. Runs FIRST
            ]
        ],
        'exit' => 'cleanup'  // 2. Runs second
    ],
    'ready' => [
        'entry' => [
            'initializeData',  // 3. Runs third
            'raiseCompleted'   // 4. Queues COMPLETED event
        ],
        'on' => [
            'COMPLETED' => 'finished'  // 5. Processed after entry completes
        ]
    ],
    'finished' => [
        'type' => 'final'
    ]
]
```

### Why Entry Actions Execute Before Raised Events

This behavior is **intentional and follows industry standards**. Entry actions complete entirely before any raised events are processed. This is defined by:

- **SCXML (W3C Standard)**: "The event will not be processed until the current block of executable content has completed"
- **UML State Machines**: Entry behaviors must complete before processing new events
- **Harel Statecharts**: Run-to-completion model prevents processing events mid-transition

### Example: Raised Events Wait for Entry Actions

```php
$machine = MachineDefinition::define(
    config: [
        'id' => 'example',
        'initial' => 'idle',
        'states' => [
            'idle' => [
                'on' => [
                    'START' => 'loading'
                ]
            ],
            'loading' => [
                'entry' => function (ContextManager $context) {
                    Log::info('Loading started');
                    // This event is queued, not immediately processed
                    return $this->raise('LOADED');
                },
                'on' => [
                    'LOADED' => 'complete'
                ]
            ],
            'complete' => [
                'entry' => function () {
                    Log::info('Loading complete');
                }
            ]
        ]
    ]
);

$machine->send('START');

// Output:
// "Loading started"
// "Loading complete"  ← Entry action finishes first
// Then LOADED event is processed
```

### Internal Event Queue

EventMachine maintains an **internal, in-memory event queue** for raised events within a single state machine execution. This is completely separate from Laravel's queue system.

> **Important:** EventMachine's internal queue is NOT related to Laravel's queue system (Redis, database, etc.). It's an in-memory queue that only exists during the current state machine execution cycle.

These internal events:

- Are added to the queue when `raise()` is called during a transition
- Wait until all entry actions complete
- Process in FIFO (first-in, first-out) order
- Follow the same run-to-completion semantics
- Live only in memory during the current request/execution
- Are NOT persisted to Redis, database, or any external queue system

```php
'entry' => [
    function () {
        // These are added to the INTERNAL event queue (in-memory)
        // NOT Laravel's job queue (Redis/database)
        $this->raise('FIRST');   // Queued first in memory
        $this->raise('SECOND');  // Queued second in memory
        Log::info('Entry done'); // Completes before events process
    }
]

// Execution order (all happens in the same request):
// 1. Entry action runs
// 2. Log output: "Entry done"
// 3. FIRST event processes (from internal queue)
// 4. SECOND event processes (from internal queue)
```

#### Internal Queue vs Laravel Queue

| Feature | Internal Event Queue | Laravel Queue |
|---------|---------------------|---------------|
| **Location** | In-memory (current execution) | External (Redis/DB/SQS) |
| **Lifetime** | Single request/execution cycle | Persistent until processed |
| **Purpose** | State machine event ordering | Background job processing |
| **Processing** | Synchronous (immediate) | Asynchronous (workers) |
| **Persistence** | No persistence | Persisted |
| **Use Case** | Raised events within machine | Long-running tasks, emails, etc. |

Example showing both:

```php
'processing' => [
    'entry' => function (ContextManager $context) {
        // Internal queue - processes immediately in this execution
        $this->raise('VALIDATE');

        // Laravel queue - processes later by a worker
        ProcessLargeFileJob::dispatch($context->fileId)
            ->onQueue('file-processing');
    },
    'on' => [
        'VALIDATE' => [
            'target' => 'validated',
            'actions' => 'runValidation'  // Runs immediately
        ]
    ]
]
```

### Benefits of Run-to-Completion

This execution model provides several critical advantages:

#### 1. Deterministic Behavior
The same sequence of events always produces the same result, making debugging and testing straightforward.

#### 2. State Consistency
The state machine is always in a valid, complete state. No partial transitions or intermediate states exist.

#### 3. Predictable Execution
You can trace exactly when each action executes, making the system easier to understand and maintain.

#### 4. No Race Conditions
Events cannot interrupt transitions, preventing concurrent state modifications.

#### 5. Standards Compliance
Compatible with SCXML, UML, Harel Statecharts, and other major state machine implementations.

### Common Pitfalls

#### Expecting Immediate Event Processing

```php
// ❌ Common Misconception
'entry' => function (ContextManager $context) {
    $this->raise('NEXT');
    // Next state changes do NOT happen here
    // The entry action must complete first
}

// ✅ Correct Understanding
'entry' => function (ContextManager $context) {
    $this->raise('NEXT');
    // Set up state for when NEXT processes
    $context->readyForNext = true;
    // Entry completes, THEN NEXT is processed
}
```

#### Debugging Raised Events

When debugging, remember that raised events appear "delayed" because entry actions execute first:

```php
'loading' => [
    'entry' => function () {
        Log::debug('1. Entry started');
        $this->raise('DONE');
        Log::debug('2. Entry done, event queued');
    }
],
'complete' => [
    'on' => [
        'DONE' => [
            'actions' => function () {
                Log::debug('3. DONE event processing');
            }
        ]
    ]
]

// Output:
// "1. Entry started"
// "2. Entry done, event queued"
// "3. DONE event processing"
```

### Alternative Patterns

If you need different behavior, consider these patterns:

#### Pattern 1: Manual Event After Transition

```php
// Let the transition complete, then send event manually
$machine = $machine->send('START');
// Transition fully completes here
$machine = $machine->send('NEXT');
```

#### Pattern 2: Always Transitions

Use `@always` transitions that evaluate after entry actions:

```php
'ready' => [
    'entry' => function (ContextManager $context) {
        $context->shouldProceed = true;
    },
    'on' => [
        '@always' => [
            [
                'target' => 'next',
                'guards' => fn($ctx) => $ctx->shouldProceed
            ]
        ]
    ]
]
```

### Testing Event Processing Order

Test that actions execute in the correct order:

```php
public function test_entry_actions_execute_before_raised_events()
{
    $executionOrder = [];

    $machine = MachineDefinition::define([
        'states' => [
            'A' => [
                'on' => [
                    'GO' => [
                        'target' => 'B',
                        'actions' => function ($ctx) use (&$executionOrder) {
                            $executionOrder[] = 'transition_action';
                            return $this->raise('NEXT');
                        }
                    ]
                ]
            ],
            'B' => [
                'entry' => fn($ctx) => $executionOrder[] = 'B_entry',
                'on' => [
                    'NEXT' => [
                        'target' => 'C',
                        'actions' => fn($ctx) => $executionOrder[] = 'next_transition'
                    ]
                ]
            ],
            'C' => [
                'entry' => fn($ctx) => $executionOrder[] = 'C_entry'
            ]
        ]
    ]);

    $machine->send('GO');

    // Verify execution order
    expect($executionOrder)->toBe([
        'transition_action',  // 1. Transition action (FIRST!)
        'B_entry',           // 2. Target state entry (before raised event)
        'next_transition',   // 3. Raised event transition
        'C_entry'           // 4. Final state entry
    ]);
}
```

### Standards Comparison

EventMachine's behavior aligns with major state machine standards:

| Framework/Standard | Entry Actions First | Run-to-Completion | Internal Events Priority |
|-------------------|-------------------|------------------|------------------------|
| **SCXML (W3C)** | ✅ Yes | ✅ Yes | ✅ Yes |
| **UML 2.5** | ✅ Yes | ✅ Yes | ⚠️ Partially specified |
| **Harel/STATEMATE** | ✅ Yes | ✅ Yes | ✅ Yes |
| **EventMachine** | ✅ Yes | ✅ Yes | ✅ Yes (FIFO) |

This consistency ensures that patterns and knowledge transfer across different state machine implementations.

## Event Sourcing

EventMachine automatically persists all events, providing event sourcing capabilities out of the box:

```php
// All events are automatically stored
$machine = OrderMachine::create(['customerId' => 123]);
$machine = $machine->send('ADD_ITEM', ['sku' => 'ABC123', 'quantity' => 2]);
$machine = $machine->send('APPLY_DISCOUNT', ['code' => 'SAVE10']);
$machine = $machine->send('PLACE_ORDER');

// Restore machine from any point in time
$restoredMachine = OrderMachine::find($machine->id);

// Get event history
$events = MachineEvent::where('machine_id', $machine->id)->get();
foreach ($events as $event) {
    echo "Event: {$event->type} at {$event->created_at}\n";
}
```

## Testing Events and Actions

### Testing Events

```php
public function test_user_can_login_with_valid_credentials()
{
    $machine = AuthMachine::create();
    
    $machine = $machine->send('USER_LOGIN', [
        'email' => 'user@example.com',
        'password' => 'password123'
    ]);
    
    $this->assertEquals('authenticated', $machine->state->value);
}

public function test_invalid_login_payload_throws_validation_error()
{
    $machine = AuthMachine::create();
    
    $this->expectException(MachineEventValidationException::class);
    
    $machine->send('USER_LOGIN', [
        'email' => 'invalid-email',  // Invalid email format
        'password' => '123'          // Too short
    ]);
}
```

### Testing Actions

```php
public function test_welcome_email_is_sent_on_registration()
{
    Mail::fake();
    
    $machine = UserMachine::create();
    $machine = $machine->send('REGISTER', [
        'email' => 'user@example.com',
        'name' => 'John Doe'
    ]);
    
    Mail::assertSent(WelcomeEmail::class, function ($mail) {
        return $mail->hasTo('user@example.com');
    });
}
```

### Faking Actions

Use EventMachine's built-in faking for testing:

```php
public function test_order_processing_without_side_effects()
{
    // Fake all actions
    ProcessPaymentAction::fake();
    SendEmailAction::fake();
    
    $machine = OrderMachine::create();
    $machine = $machine->send('PLACE_ORDER', ['total' => 99.99]);
    
    $this->assertEquals('processing', $machine->state->value);
    
    // Assert actions were called
    ProcessPaymentAction::assertInvoked();
    SendEmailAction::assertInvoked();
}
```

## Best Practices

### 1. **Keep Actions Pure**
Actions should be predictable and testable. Avoid actions that depend on global state.

### 2. **Use Event Classes for Complex Events**
When events need validation or carry complex data, use event classes.

### 3. **Handle Errors Gracefully**
Always consider error cases in actions and handle them appropriately.

### 4. **Separate Concerns**
Use separate actions for different concerns (validation, persistence, notifications).

### 5. **Document Side Effects**
Clearly document what side effects each action produces.

### 6. **Use Dependency Injection**
Leverage Laravel's container for testable, modular actions.

## Next Steps

- [Context Management](./context.md) - Managing data across state changes
- [Guards and Conditions](./guards.md) - Controlling when transitions occur
- [Testing](../testing/) - Comprehensive testing strategies