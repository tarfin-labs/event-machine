# Machine Lifecycle

Understanding the complete lifecycle of an EventMachine helps you build correct and predictable state machines.

## Overview

```
┌─────────────────────────────────────────────────────────────┐
│                     MACHINE LIFECYCLE                        │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  1. CREATE           2. START            3. SEND EVENTS     │
│  ─────────          ─────────           ──────────────      │
│  Machine::create()   Initial state       $machine->send()   │
│                      Entry actions                          │
│                                                              │
│                                          ┌──────────────┐   │
│                                          │ Transition   │   │
│                                          │ Execution    │   │
│                                          │ (see below)  │   │
│                                          └──────────────┘   │
│                                                              │
│  4. PERSIST          5. RESTORE          6. FINAL STATE    │
│  ──────────          ──────────          ─────────────     │
│  Auto-saved          create(state: id)   Machine done      │
│  to database                             Result computed    │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## 1. Creation

When you create a machine:

<!-- doctest-attr: ignore -->
```php
$machine = OrderMachine::create();
```

What happens:
1. Machine definition is loaded
2. Initial context is set up
3. Machine is NOT started yet (no entry actions run)

With existing state:

<!-- doctest-attr: ignore -->
```php
$machine = OrderMachine::create(state: $rootEventId);
```

What happens:
1. Events are loaded from database
2. State is replayed to rebuild current position
3. Context is reconstructed from event history

## 2. Start / Initial State

The first time you interact with the machine, it enters the initial state:

<!-- doctest-attr: ignore -->
```php
$state = $machine->state;  // Or $machine->send(...)
```

What happens:
1. `machine.start` internal event fires
2. Initial state's entry actions run
3. First event is persisted (if persistence enabled)

<!-- doctest-attr: ignore -->
```php
'initial' => 'pending',
'states' => [
    'pending' => [
        'entry' => ['logOrderCreated', 'notifyCustomer'],
        // These run when machine starts
    ],
]
```

## 3. Sending Events

<!-- doctest-attr: ignore -->
```php
$state = $machine->send(['type' => 'PAY', 'amount' => 99.99]);
```

This triggers the **Transition Execution** process (detailed below).

## 4. Transition Execution

When an event is sent, here's exactly what happens:

```
┌─────────────────────────────────────────────────────────────┐
│               TRANSITION EXECUTION ORDER                     │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  1. CALCULATORS                                              │
│     └─► Prepare context values needed by guards             │
│                                                              │
│  2. GUARDS                                                   │
│     └─► Check conditions (first matching branch wins)       │
│     └─► If all guards fail, transition is blocked           │
│                                                              │
│  3. EXIT ACTIONS                                             │
│     └─► Run current state's exit actions                    │
│                                                              │
│  4. TRANSITION ACTIONS                                       │
│     └─► Run actions defined on the transition               │
│                                                              │
│  5. ENTRY ACTIONS                                            │
│     └─► Run new state's entry actions                       │
│                                                              │
│  6. ALWAYS TRANSITIONS                                       │
│     └─► Check for @always transitions                       │
│     └─► If found, repeat from step 1                        │
│                                                              │
│  7. RAISED EVENTS                                            │
│     └─► Process any events raised during actions            │
│     └─► Each raised event goes through steps 1-7            │
│                                                              │
│  8. PERSIST                                                  │
│     └─► Save event and context to database                  │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### Example Walkthrough

<!-- doctest-attr: ignore -->
```php
// Machine definition
'states' => [
    'pending' => [
        'exit' => ['logLeavingPending'],
        'on' => [
            'PAY' => [
                'target' => 'paid',
                'guards' => 'hasValidAmount',
                'actions' => ['processPayment', 'generateReceipt'],
            ],
        ],
    ],
    'paid' => [
        'entry' => ['sendConfirmation', 'notifyWarehouse'],
        'on' => [
            '@always' => [
                'target' => 'processing',
                'guards' => 'autoProcessEnabled',
            ],
        ],
    ],
    'processing' => [
        'entry' => ['startProcessing'],
    ],
],
'behavior' => [
    'calculators' => [
        'calculateTax' => CalculateTaxCalculator::class,
    ],
]
```

When `PAY` event is sent:

1. **Calculators**: `calculateTax` runs, sets `tax` in context
2. **Guards**: `hasValidAmount` checks if amount > 0
3. **Exit**: `logLeavingPending` runs
4. **Transition**: `processPayment`, `generateReceipt` run
5. **Entry**: `sendConfirmation`, `notifyWarehouse` run
6. **Always**: `autoProcessEnabled` guard checks
7. **If always matched**: Jump to `processing`, run `startProcessing`
8. **Persist**: Event saved to database

## 5. Persistence

Every state change is automatically persisted:

<!-- doctest-attr: ignore -->
```php
// Sends PAY event
$machine->send(['type' => 'PAY', 'amount' => 99.99]);

// Creates record in machine_events:
// {
//   type: 'PAY',
//   payload: {amount: 99.99},
//   context: {paid_amount: 99.99, ...},
//   machine_value: ['order.paid'],
//   root_event_id: 'xxx',
//   sequence_number: 2
// }
```

### Disabling Persistence

For testing or calculations:

```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
MachineDefinition::define(
    config: [
        'should_persist' => false,
        // ...
    ],
);
```

## 6. Restoration

Restore a machine from any point in its history:

<!-- doctest-attr: ignore -->
```php
// Get the root event ID
$rootEventId = $machine->state->history->first()->root_event_id;

// Later: restore
$restored = OrderMachine::create(state: $rootEventId);
```

What happens:
1. All events with this `root_event_id` are loaded
2. Events are replayed in sequence order
3. Final context is reconstructed
4. Machine is at the exact state it was

### Restoration vs Re-execution

Restoration does NOT re-run actions. It only rebuilds state:

<!-- doctest-attr: ignore -->
```php
// Original: runs sendEmail action
$machine->send(['type' => 'CONFIRM']);

// Restored: does NOT re-run sendEmail
$restored = OrderMachine::create(state: $rootEventId);
// Just rebuilds state from stored data
```

## 7. Final States

When a machine reaches a final state:

<!-- doctest-attr: ignore -->
```php
'delivered' => [
    'type' => 'final',
    'result' => 'computeDeliveryResult',
]
```

What happens:
1. Entry actions run (if any)
2. Result behavior computes final output
3. Machine is "done" - no more transitions possible
4. `machine.finish` internal event fires

Check if done:

<!-- doctest-attr: ignore -->
```php
$state = $machine->state;
$state->currentStateDefinition->type === StateDefinitionType::FINAL;
```

## Concurrent Execution Safety

EventMachine prevents concurrent modifications:

<!-- doctest-attr: ignore -->
```php
// Process A
$machine->send(['type' => 'PAY']);

// Process B (same time, same machine)
$machine->send(['type' => 'CANCEL']);
// Throws MachineAlreadyRunningException
```

Implemented via cache locks:

<!-- doctest-attr: ignore -->
```php
// Lock key format: "machine:{root_event_id}"
// Lock duration: configurable, default 30 seconds
```

## Lifecycle Hooks

### Entry/Exit Actions

<!-- doctest-attr: ignore -->
```php
'states' => [
    'active' => [
        'entry' => ['onEnterActive'],   // When entering
        'exit' => ['onExitActive'],     // When leaving
    ],
]
```

### Internal Events

| Event | When |
|-------|------|
| `machine.start` | Machine initialized |
| `machine.finish` | Reached final state |

## State History Access

<!-- doctest-attr: ignore -->
```php
$state = $machine->state;

// All events that led to current state
$history = $state->history;

// First event (root)
$first = $history->first();
$first->root_event_id;  // Unique identifier
$first->type;           // Event type
$first->payload;        // Event data

// Latest event
$latest = $history->last();

// Iterate all events
foreach ($history as $event) {
    echo "{$event->type} at {$event->created_at}";
}
```

## Best Practices

### Keep Transitions Fast

Actions should be quick. For slow operations:

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
class ProcessPaymentAction extends ActionBehavior
{
    public function __invoke($context, $event): void
    {
        // Quick: record intent
        $context->set('payment_pending', true);

        // Dispatch slow work to queue
        ProcessPaymentJob::dispatch($event->payload);
    }
}
```

### Idempotent Actions

Design actions to be safe to run multiple times:

<!-- doctest-attr: ignore -->
```php
// Bad: not idempotent
$context->set('count', $context->get('count') + 1);

// Good: idempotent
$context->set('processed_at', now());
$context->set('processed', true);
```

### Handle Failures Gracefully

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
class SendEmailAction extends ActionBehavior
{
    public function __invoke($context, $event): void
    {
        try {
            Mail::send(...);
            $context->set('email_sent', true);
        } catch (Exception $e) {
            $context->set('email_sent', false);
            $context->set('email_error', $e->getMessage());

            // Raise event for retry handling
            $this->raise(['type' => 'EMAIL_FAILED']);
        }
    }
}
```
