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
│  to database                             Output computed    │
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

## 1b. Input Validation (Delegation Only)

When a machine is created as a child via the `machine` key with a typed `input`, the `MachineInput` is validated before the machine starts:

```
Parent enters delegation state
  → Resolve input (MachineInput class, closure, or array)
  → If MachineInput class: construct from parent context
  → Validate required parameters
  → If validation fails: MachineInputValidationException → @fail on parent
  → If valid: merge input properties into child context
  → Proceed to child start
```

In async mode, this validation happens inside `ChildMachineJob`. A validation failure dispatches `ChildMachineCompletionJob` with an error, routing `@fail` on the parent.

## 2. Start / Initial State

The first time you interact with the machine, it enters the initial state:

<!-- doctest-attr: ignore -->
```php
$state = $machine->state;  // Or $machine->send(...)
```

What happens:
1. `{machine}.start` internal event fires
2. Root-level entry actions run (if defined) — `{machine}.entry.start` / `{machine}.entry.finish`
3. Initial state's entry actions run — `{machine}.state.{state}.entry.start` / `.finish`
4. First event is persisted (if persistence enabled)

<!-- doctest-attr: ignore -->
```php
'initial' => 'pending',
'entry' => 'initializeTrackingAction',  // Root entry — runs once on start
'states' => [
    'pending' => [
        'entry' => ['logOrderCreated', 'notifyCustomer'],
        // These run after root entry
    ],
]
```

When the machine reaches a final state, root exit actions run before `{machine}.finish`:

```
State exit actions
  → {machine}.exit.start
    → Root exit actions
  → {machine}.exit.finish
    → {machine}.finish
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
│  3. LISTEN EXIT                                              │
│     └─► Run listen.exit on source (if non-transient)        │
│                                                              │
│  4. EXIT ACTIONS                                             │
│     └─► Run current state's exit actions                    │
│                                                              │
│  5. TRANSITION ACTIONS                                       │
│     └─► Run actions defined on the transition               │
│                                                              │
│  6. ENTRY ACTIONS                                            │
│     └─► Run new state's entry actions                       │
│                                                              │
│  7. LISTEN ENTRY                                             │
│     └─► Run listen.entry on target (if non-transient)       │
│                                                              │
│  8. LISTEN TRANSITION                                        │
│     └─► Run listen.transition (always, unless transient)    │
│                                                              │
│  9. ALWAYS TRANSITIONS                                       │
│     └─► Check for @always transitions                       │
│     └─► If found, repeat from step 1                        │
│     └─► Original event preserved for behaviors (v8+)        │
│                                                              │
│  10. RAISED EVENTS                                           │
│      └─► Process any events raised during actions           │
│      └─► Each raised event goes through steps 1-10          │
│                                                              │
│  11. PERSIST                                                 │
│      └─► Save event and context to database                 │
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
6. **Always**: `autoProcessEnabled` guard checks — receives original `PAY` event (v8+)
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
    'output' => 'computeDeliveryOutput',
]
```

What happens:
1. Entry actions run (if any)
2. Output behavior computes final output
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
| `{machine}.start` | Machine initialized |
| `{machine}.entry.start` | Root entry actions starting (if defined) |
| `{machine}.entry.finish` | Root entry actions completed |
| `{machine}.exit.start` | Root exit actions starting (if defined, on final state) |
| `{machine}.exit.finish` | Root exit actions completed |
| `{machine}.listen.entry.start/finish` | Listener entry actions |
| `{machine}.listen.exit.start/finish` | Listener exit actions |
| `{machine}.listen.transition.start/finish` | Listener transition actions |
| `{machine}.listen.queue.{action}.dispatched` | Queued listener dispatched |
| `{machine}.listen.queue.{action}.started` | Worker picked up queued listener |
| `{machine}.listen.queue.{action}.completed` | Worker finished queued listener |
| `{machine}.finish` | Reached final state |

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

::: tip Detailed Guide
For comprehensive design guidelines with Do/Don't examples, see [Best Practices Overview](/best-practices/).
:::

::: tip Testing
For testing the full machine lifecycle with `TestMachine`, see [Testing Overview](/testing/overview).
:::
