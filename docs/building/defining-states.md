# Defining States

This guide shows you how to define states in your machine configuration.

## Basic State Definition

Every state is defined as a key in the `states` array:

```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]

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

```php ignore
'pending' => [
    'on' => [
        'SUBMIT' => 'processing',
    ],
],
```

### Compound States

States containing nested child states:

```php ignore
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

```php ignore
'completed' => [
    'type' => 'final',
    'result' => 'calculateResultResult',  // Optional result behavior
],
```

Final states cannot have outgoing transitions:

```php ignore
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

```php ignore
'processing' => [
    'entry' => 'startProcessingAction',           // Single action
    'exit' => ['cleanupAction', 'logCompletionAction'], // Multiple actions
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

## Machine-Level Entry and Exit

Define `entry`/`exit` at the root config level for actions that run once during the machine lifecycle:

```php ignore
MachineDefinition::define(
    config: [
        'id'      => 'order',
        'initial' => 'pending',
        'entry'   => 'initializeTrackingAction',  // Runs once — when machine starts
        'exit'    => 'finalCleanupAction',         // Runs once — when machine reaches a final state
        'states'  => [
            'pending' => [
                'entry' => 'sendNotificationAction',  // Runs each time 'pending' is entered
                'on'    => ['SUBMIT' => 'completed'],
            ],
            'completed' => ['type' => 'final'],
        ],
    ],
);
```

Execution order on initialization:

```
MACHINE_START
  → Root entry actions (once)
    → Initial state entry actions
```

Execution order when reaching a final state:

```
Current state exit actions
  → Root exit actions (once)
    → MACHINE_FINISH
```

::: info Root vs State entry/exit
- **Root `entry`**: Runs **once** when the machine starts — before any state entry
- **Root `exit`**: Runs **once** when the machine reaches a final state — after the last state's exit
- **State `entry`/`exit`**: Runs **every time** that specific state is entered or left

Root entry does NOT run on every state change. For that, see the upcoming _state change hooks_ feature.
:::

## State Metadata

Attach custom data to states:

```php ignore
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

```php no_run
$state->currentStateDefinition->meta['timeout']; // 86400
```

## State Descriptions

Add human-readable descriptions:

```php ignore
'awaiting_payment' => [
    'description' => 'Order is waiting for customer payment',
    'on' => [
        'PAY' => 'paid',
        'CANCEL' => 'cancelled',
    ],
],
```

Access via:

```php no_run
$state->currentStateDefinition->description;
```

## State Hierarchy and IDs

States are identified by their path from the root:

```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]

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

```php ignore
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
                'entry' => 'initializeDraftAction',
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
                        'entry' => 'notifyReviewersAction',
                        'on' => [
                            'APPROVE' => 'approved',
                            'REJECT' => 'rejected',
                        ],
                    ],
                    'approved' => [
                        'exit' => 'logApprovalAction',
                    ],
                    'rejected' => [
                        'exit' => 'logRejectionAction',
                    ],
                ],
                'on' => [
                    'PUBLISH' => [
                        'target' => 'published',
                        'guards' => 'isApprovedGuard',
                    ],
                    'REVISE' => 'draft',
                ],
            ],
            'published' => [
                'type' => 'final',
                'entry' => 'notifyPublishedAction',
                'result' => 'getPublishedDocumentResult',
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
            'initializeDraftAction' => InitializeDraftAction::class,
            'notifyReviewersAction' => NotifyReviewersAction::class,
            'notifyPublishedAction' => NotifyPublishedAction::class,
            'logApprovalAction' => LogApprovalAction::class,
            'logRejectionAction' => LogRejectionAction::class,
        ],
        'guards' => [
            'isApprovedGuard' => IsApprovedGuard::class,
        ],
        'results' => [
            'getPublishedDocumentResult' => GetPublishedDocumentResult::class,
        ],
    ],
);
```

## State Definition Reference

```php ignore
'stateName' => [
    // Transitions (see Writing Transitions)
    'on' => [
        'EVENT' => 'targetState',
    ],

    // Lifecycle actions
    'entry' => 'actionNameAction',              // or ['action1Action', 'action2Action']
    'exit' => 'actionNameAction',               // or ['action1Action', 'action2Action']

    // State type
    'type' => 'final',                    // Only for terminal states

    // Final state result
    'result' => 'resultBehaviorNameResult',

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

## Testing State Definitions

<!-- doctest-attr: ignore -->
```php
OrderMachine::test()
    ->assertState('idle')
    ->send('SUBMIT')
    ->assertState('submitted')
    ->send('PAY')
    ->assertState('paid')
    ->assertFinished();  // verify final state
```

::: tip Full Testing Guide
See [Transitions and Paths](/testing/transitions-and-paths) for more examples.
:::
