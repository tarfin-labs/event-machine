# Defining States

This guide shows you how to define states in your machine configuration.

## Basic State Definition

Every state is defined as a key in the `states` array:

```php
MachineDefinition::define(
    config: [
        'initial' => 'pending',
        'states' => [
            'pending' => [],
            'processing' => [],
            'completed' => [],
        ],
    ],
);
```

## State Properties

Each state supports these properties:

| Property | Type | Description |
|----------|------|-------------|
| `on` | array | Event-to-transition mappings |
| `entry` | string\|array | Actions to run when entering |
| `exit` | string\|array | Actions to run when leaving |
| `type` | string | State type (`'final'` for terminal states) |
| `result` | string | Result behavior for final states |
| `initial` | string | Initial child state (for compound states) |
| `states` | array | Child state definitions (for compound states) |
| `meta` | array | Custom metadata |
| `description` | string | Human-readable description |

## State Types

EventMachine supports three state types:

### Atomic States

Simple states with no children. Most states are atomic:

```php
'pending' => [
    'on' => [
        'SUBMIT' => 'processing',
    ],
],
```

### Compound States

States containing nested child states:

```php
'active' => [
    'initial' => 'idle',
    'states' => [
        'idle' => [
            'on' => ['START' => 'running'],
        ],
        'running' => [
            'on' => ['PAUSE' => 'paused'],
        ],
        'paused' => [
            'on' => ['RESUME' => 'running'],
        ],
    ],
],
```

When entering a compound state, the machine automatically enters its initial child state.

### Final States

Terminal states that end the machine's execution:

```php
'completed' => [
    'type' => 'final',
    'result' => 'calculateResult',  // Optional result behavior
],
```

Final states cannot have outgoing transitions:

```php
// This will throw InvalidFinalStateDefinitionException
'done' => [
    'type' => 'final',
    'on' => [
        'RESTART' => 'initial',  // Not allowed!
    ],
],
```

## Entry and Exit Actions

Execute code when entering or leaving a state:

```php
'processing' => [
    'entry' => 'startProcessing',           // Single action
    'exit' => ['cleanup', 'logCompletion'], // Multiple actions
    'on' => [
        'COMPLETE' => 'done',
    ],
],
```

Actions execute in this order during transitions:

```
Source State Exit Actions
    ↓
Transition Actions
    ↓
Target State Entry Actions
```

## State Metadata

Attach custom data to states:

```php
'pending_approval' => [
    'meta' => [
        'description' => 'Waiting for manager approval',
        'timeout' => 86400,  // 24 hours
        'notify' => ['manager@example.com'],
    ],
    'on' => [
        'APPROVE' => 'approved',
        'REJECT' => 'rejected',
    ],
],
```

Access metadata from state:

```php
$state->currentStateDefinition->meta['timeout']; // 86400
```

## State Descriptions

Add human-readable descriptions:

```php
'awaiting_payment' => [
    'description' => 'Order is waiting for customer payment',
    'on' => [
        'PAY' => 'paid',
        'CANCEL' => 'cancelled',
    ],
],
```

Access via:

```php
$state->currentStateDefinition->description;
```

## State Hierarchy and IDs

States are identified by their path from the root:

```php
MachineDefinition::define(
    config: [
        'id' => 'order',
        'initial' => 'checkout',
        'states' => [
            'checkout' => [
                'initial' => 'cart',
                'states' => [
                    'cart' => [],
                    'shipping' => [],
                    'payment' => [],
                ],
            ],
        ],
    ],
);
```

State IDs follow the pattern `{machine_id}.{path}`:

| State | Full ID |
|-------|---------|
| checkout | `order.checkout` |
| cart | `order.checkout.cart` |
| shipping | `order.checkout.shipping` |

You can customize the delimiter:

```php
'delimiter' => '/',  // Results in: order/checkout/cart
```

## Complete Example

```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

MachineDefinition::define(
    config: [
        'id' => 'document',
        'initial' => 'draft',
        'states' => [
            'draft' => [
                'description' => 'Document is being edited',
                'entry' => 'initializeDraft',
                'on' => [
                    'SUBMIT' => 'review',
                    'DELETE' => 'deleted',
                ],
            ],
            'review' => [
                'description' => 'Document under review',
                'initial' => 'pending',
                'states' => [
                    'pending' => [
                        'entry' => 'notifyReviewers',
                        'on' => [
                            'APPROVE' => 'approved',
                            'REJECT' => 'rejected',
                        ],
                    ],
                    'approved' => [
                        'exit' => 'logApproval',
                    ],
                    'rejected' => [
                        'exit' => 'logRejection',
                    ],
                ],
                'on' => [
                    'PUBLISH' => [
                        'target' => 'published',
                        'guards' => 'isApproved',
                    ],
                    'REVISE' => 'draft',
                ],
            ],
            'published' => [
                'type' => 'final',
                'entry' => 'notifyPublished',
                'result' => 'getPublishedDocument',
                'meta' => [
                    'public' => true,
                ],
            ],
            'deleted' => [
                'type' => 'final',
            ],
        ],
    ],
    behavior: [
        'actions' => [
            'initializeDraft' => InitializeDraftAction::class,
            'notifyReviewers' => NotifyReviewersAction::class,
            'notifyPublished' => NotifyPublishedAction::class,
            'logApproval' => LogApprovalAction::class,
            'logRejection' => LogRejectionAction::class,
        ],
        'guards' => [
            'isApproved' => IsApprovedGuard::class,
        ],
        'results' => [
            'getPublishedDocument' => GetPublishedDocumentResult::class,
        ],
    ],
);
```

## State Definition Reference

```php
'stateName' => [
    // Transitions (see Writing Transitions)
    'on' => [
        'EVENT' => 'targetState',
    ],

    // Lifecycle actions
    'entry' => 'actionName',              // or ['action1', 'action2']
    'exit' => 'actionName',               // or ['action1', 'action2']

    // State type
    'type' => 'final',                    // Only for terminal states

    // Final state result
    'result' => 'resultBehaviorName',

    // Hierarchy
    'initial' => 'childStateName',        // Initial child state
    'states' => [                         // Child states
        'childState' => [...],
    ],

    // Metadata
    'meta' => [
        'key' => 'value',
    ],
    'description' => 'Human readable text',
],
```
