# Delegation Patterns

This page covers common machine delegation patterns: orchestrator, saga/compensation, and when to use cross-machine messaging vs the orchestrator pattern.

## Orchestrator Pattern

The orchestrator machine's `definition()` **IS** the system definition. Reading it tells you which machines exist, how they relate, and what data flows between them.

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class OrderWorkflowMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'order_workflow',
                'initial' => 'validating',
                'context' => [
                    'order_id'       => null,
                    'total_amount'   => 0,
                    'payment_result' => null,
                ],
                'states' => [
                    'validating' => [
                        'machine' => ValidationMachine::class,
                        'with'    => ['order_id'],
                        '@done'   => 'processing_payment',
                        '@fail'   => 'validation_failed',
                    ],
                    'processing_payment' => [
                        'machine' => PaymentMachine::class,
                        'with'    => ['order_id', 'total_amount'],
                        'queue'   => 'payments',
                        '@done'   => [
                            'target'  => 'shipping',
                            'actions' => 'storePaymentResultAction',
                        ],
                        '@fail' => 'payment_failed',
                    ],
                    'shipping' => [
                        'machine' => ShippingMachine::class,
                        'with'    => ['order_id'],
                        '@done'   => 'completed',
                        '@fail'   => 'shipping_failed',
                    ],
                    'completed'         => ['type' => 'final'],
                    'validation_failed' => ['type' => 'final'],
                    'payment_failed'    => ['type' => 'final'],
                    'shipping_failed'   => ['type' => 'final'],
                ],
            ],
        );
    }
}
```

```
OrderWorkflowMachine (orchestrator)
    ├── invokes ValidationMachine  → @done → processing_payment
    ├── invokes PaymentMachine     → @done → shipping
    └── invokes ShippingMachine    → @done → completed
```

### Why No Separate System Class

The orchestrator machine already declares everything:

| Concern | Solved By |
|---------|-----------|
| Where are machines defined? | Orchestrator's `definition()` |
| How do they communicate? | `@done`/`@fail` transitions |
| Who coordinates the flow? | The orchestrator machine |
| How do siblings talk? | They don't — flow goes through the orchestrator |

## Saga / Compensation Pattern

When a step fails and you need to undo previous steps, use the saga pattern with compensation machines:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class BookingMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'booking',
                'initial' => 'reserving_flight',
                'context' => [
                    'booking_id'  => null,
                    'flight_ref'  => null,
                    'hotel_ref'   => null,
                ],
                'states' => [
                    'reserving_flight' => [
                        'machine' => FlightReservationMachine::class,
                        'with'    => ['booking_id'],
                        '@done'   => [
                            'target'  => 'reserving_hotel',
                            'actions' => 'storeFlightRefAction',
                        ],
                        '@fail' => 'failed',
                    ],
                    'reserving_hotel' => [
                        'machine' => HotelReservationMachine::class,
                        'with'    => ['booking_id'],
                        '@done'   => [
                            'target'  => 'confirmed',
                            'actions' => 'storeHotelRefAction',
                        ],
                        // Hotel fails → cancel flight
                        '@fail' => 'cancelling_flight',
                    ],
                    'cancelling_flight' => [
                        'machine' => FlightCancellationMachine::class,
                        'with'    => ['flight_ref'],
                        '@done'   => 'failed',
                        '@fail'   => 'failed',
                    ],
                    'confirmed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
        );
    }
}
```

**Key insight:** The compensating machine (`FlightCancellationMachine`) is just another child machine — no special API needed.

## Parallel Orchestration

Combine parallel states with machine delegation to run multiple child machines concurrently:

<!-- doctest-attr: ignore -->
```php
'processing' => [
    'type'   => 'parallel',
    '@done'  => 'shipping',
    '@fail'  => 'compensating',
    'states' => [
        'payment' => [
            'initial' => 'charging',
            'states'  => [
                'charging' => [
                    'machine' => PaymentMachine::class,
                    'with'    => ['order_id', 'total_amount'],
                    '@done'   => 'charged',
                ],
                'charged' => ['type' => 'final'],
            ],
        ],
        'inventory' => [
            'initial' => 'reserving',
            'states'  => [
                'reserving' => [
                    'machine' => InventoryMachine::class,
                    'with'    => ['order_id'],
                    '@done'   => 'reserved',
                ],
                'reserved' => ['type' => 'final'],
            ],
        ],
    ],
],
```

Both children run. The parallel state's `@done` fires when **all** regions reach final.

## Communication Patterns

| Pattern | Mechanism | Best For |
|---------|-----------|----------|
| **Orchestration** | `machine` key | All inter-machine workflows (primary pattern) |
| **Sync progress** | `sendToParent()` | Child → parent immediate updates |
| **Async progress** | `dispatchToParent()` | Child → parent via queue |
| **External interaction** | Endpoints (webhooks) | Third-party callbacks |
| **Loose coupling** | Laravel Events | Cross-model, fire-and-forget |
| **Sync escape hatch** | `sendTo()` | Direct cross-machine messaging |
| **Async escape hatch** | `dispatchTo()` | Queued cross-machine messaging |

### Design Rule: Orchestrator First

Sibling machines should **not** communicate directly. Let the orchestrator handle flow:

<!-- doctest-attr: ignore -->
```php
// WRONG: PaymentMachine directly triggers ShippingMachine
class NotifyShippingAction extends ActionBehavior {
    public function __invoke(ContextManager $context): void {
        $this->sendTo(
            machineClass: ShippingMachine::class,
            rootEventId: $context->get('shipping_machine_id'),
            event: ['type' => 'START_SHIPPING'],
        );
    }
}

// RIGHT: Orchestrator manages the flow (visible in definition)
'processing_payment' => [
    'machine' => PaymentMachine::class,
    '@done'   => 'shipping',          // orchestrator decides what's next
],
'shipping' => [
    'machine' => ShippingMachine::class,
],
```

**`sendTo()` / `dispatchTo()` are escape hatches**, not the primary communication pattern. Their main use case is `sendToParent()` / `dispatchToParent()` for progress reporting.

## Fire-and-Forget Pattern

Fire-and-forget means dispatching work without tracking the result. Use it for side effects where the parent doesn't care about the outcome.

### Machine Delegation (stay in state)

Omit `@done` to make a machine delegation fire-and-forget. The parent stays in the state and handles its own events:

<!-- doctest-attr: ignore -->
```php
'suspended' => [
    'machine' => AuditMachine::class,
    'with'    => ['user_id'],
    'queue'   => 'background',
    // No @done → fire-and-forget
    'on' => ['REACTIVATE' => 'active'],
],
```

### Machine Delegation (spawn and transition)

Use `@always` or `target` to spawn and immediately move to the next state:

<!-- doctest-attr: ignore -->
```php
'dispatching_audit' => [
    'machine' => AuditMachine::class,
    'with'    => ['user_id'],
    'queue'   => 'background',
    'on'      => ['@always' => 'suspended'],
],
```

### Job Actor

For single-step async operations:

<!-- doctest-attr: ignore -->
```php
'logging' => [
    'job'    => AuditLogJob::class,
    'with'   => ['action', 'user_id'],
    'target' => 'next_state',
],
```

### dispatchTo() from an Action

For sending an event to an existing machine without waiting:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class SendAlertAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $this->dispatchTo(
            machineClass: AlertMachine::class,
            rootEventId: $context->get('alert_machine_id'),
            event: ['type' => 'SEND_ALERT'],
        );
    }
}
```

### Choosing the Right Mechanism

| Mechanism | Tracks Result | Parent Waits | Use Case |
|-----------|--------------|-------------|----------|
| `machine + queue` (no `@done`) | No | No | Stateful child (multiple states, webhooks) |
| `job` + `target` | No | No | Single-step async (logging, notification) |
| `dispatchTo()` | No | No | Event to existing machine |
| `machine` + `@done` | Yes | Yes | Complex stateful delegation |
| `job` + `@done` | Yes | Yes | Managed async job |
