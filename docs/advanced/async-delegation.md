# Sync vs Async Delegation

Machine delegation supports two execution modes: **sync** (default) and **async** (queue). This page compares both modes and explains the webhook pattern.

## Sync Mode (Default)

When no `queue` key is specified, the child runs **inline** within the parent's transition:

```
Parent.send(EVENT)
  → Parent enters 'processing' state
  → Detects 'machine' key
  → Resolves child context via 'with'
  → Creates and runs child machine inline
  │
  ├── Child reaches final → parent's @done fires immediately
  └── Child fails → parent's @fail fires immediately
```

**Use when:** Child execution is fast (no external I/O), and you want simplicity.

## Async Mode (Queue)

Add the `queue` key to run the child on a Laravel queue:

```
Parent.send(EVENT)
  → Parent enters 'processing' state
  → Dispatches ChildMachineJob to queue
  → Parent STAYS in 'processing' state (waiting)
  → Parent persists to DB

         ┌──── Queue Worker ────┐
         │                      │
         ▼                      │
  ChildMachineJob.handle()      │
  → Creates and runs child      │
  │                             │
  ├── Child reaches final       │
  │   → ChildMachineCompletionJob dispatched
  │     → Restores parent from DB
  │     → Routes @done to parent
  │     → Parent transitions to next state
  │
  └── Child fails
      → ChildMachineCompletionJob dispatched (with error)
      → Routes @fail to parent
```

**Use when:** Child has I/O operations, needs external webhooks, or should run independently.

## Queue Configuration

<!-- doctest-attr: ignore -->
```php
// Run on default queue
'queue' => true,

// Named queue
'queue' => 'payments',

// Detailed configuration
'queue' => [
    'connection' => 'redis',
    'queue'      => 'payments',
    'retry'      => 3,
],
```

## Webhook Pattern

The most powerful async use case: a child machine that waits for external webhooks.

```
Parent Machine
  │
  └── 'processing_payment' state
        │
        ├── machine: PaymentMachine (async, queue)
        │     │
        │     ├── awaiting_charge (entry: call Stripe API)
        │     │     │
        │     │     └── Stripe sends webhook ──→ PaymentMachine endpoint
        │     │                                   POST /webhooks/payment/{machineId}/success
        │     │                                   │
        │     ├── charged (final) ◄───────────────┘
        │     │
        │     └── (parent receives @done with payment result)
        │
        └── @done → shipping
```

### How It Works

1. Parent enters `processing_payment` → dispatches `ChildMachineJob`
2. Child starts, calls Stripe API (passing its `machineId()` in the webhook URL)
3. Child persists in `awaiting_charge` state — `ChildMachineJob` completes
4. Time passes... Stripe processes the charge
5. Stripe sends webhook to child's endpoint
6. `MachineController` restores child from `{machineId}` route parameter
7. Child transitions to `charged` (final state)
8. Auto-completion dispatches `ChildMachineCompletionJob`
9. Parent receives `@done` and transitions to `shipping`

### Child Machine with Endpoints

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class PaymentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'payment',
                'initial' => 'awaiting_charge',
                'context' => ['order_id' => null, 'amount' => 0],
                'states'  => [
                    'awaiting_charge' => [
                        'on' => [
                            'CHARGE_SUCCEEDED' => 'charged',
                            'CHARGE_FAILED'    => 'failed',
                        ],
                    ],
                    'charged' => ['type' => 'final'],
                    'failed'  => ['type' => 'final'],
                ],
            ],
            endpoints: [
                'CHARGE_SUCCEEDED' => [
                    'uri'    => '/webhooks/payment/{machineId}/success',
                    'method' => 'POST',
                ],
                'CHARGE_FAILED' => [
                    'uri'    => '/webhooks/payment/{machineId}/failure',
                    'method' => 'POST',
                ],
            ],
        );
    }
}
```

### Building Webhook URLs

Every machine has access to its own `machineId()` via context:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class InitiateChargeAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $machineId = $context->machineId();

        PaymentGateway::charge([
            'amount'      => $context->get('amount'),
            'webhook_url' => url("/webhooks/payment/{$machineId}/success"),
        ]);
    }
}
```

## Comparison Table

| Aspect | Sync | Async (Queue) |
|--------|------|---------------|
| Child execution | Inline, blocking | On queue worker |
| Parent state | Transitions immediately | Stays in delegating state |
| Persistence | Optional | Required (parent + child) |
| External I/O | Not recommended | Designed for it |
| Webhook support | No | Yes |
| `@timeout` | Not applicable | Supported |
| `forward` | Not applicable | Supported |
| Complexity | Simple | More moving parts |
