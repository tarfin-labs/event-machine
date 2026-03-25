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

::: tip Dispatch Timing
`ChildMachineJob` is not dispatched during entry action execution. It is dispatched **after the macrostep completes** -- after all entry actions, listeners, and raised events have been processed. If raised events cause a state change, the job is never dispatched. See [Macrostep and Invoke Timing](/reference/execution-model#macrostep-and-invoke-timing).
:::

```
Parent.send(EVENT)
  → Parent enters 'processing' state
  → Entry actions and raised events processed (macrostep)
  → Dispatches ChildMachineJob to queue (after macrostep)
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

::: tip @done.{state} in Async Mode
`@done.{state}` routing works identically in async mode. The `ChildMachineCompletionJob` carries the child's final state key through the pipeline and routes to the matching `@done.{state}` transition on the parent. See [Per-Final-State Routing](/advanced/machine-delegation#per-final-state-routing).
:::

## Testing Async Delegation

<!-- doctest-attr: ignore -->
```php
// Test async dispatch
Queue::fake();
OrderMachine::test()
    ->send('START_PAYMENT')
    ->assertState('awaiting_payment');
Queue::assertPushed(ChildMachineJob::class);

// Test with faked child (sync short-circuit)
PaymentMachine::fake(result: ['payment_id' => 'pay_123'], finalState: 'approved');
OrderMachine::test()
    ->send('START_PAYMENT')
    ->assertState('completed');
Machine::resetMachineFakes();
```

::: tip Full Testing Guide
For comprehensive async delegation testing patterns, see [Delegation Testing](/testing/delegation-testing).
:::

::: warning Testing Async Delegation
`Queue::fake()` verifies dispatch but not the full pipeline (child runs → completes → parent routes). For end-to-end verification with real infrastructure, see [Recipe: Full Async Delegation Pipeline](/testing/recipes#recipe-full-async-delegation-pipeline).
:::

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

## Forward Pattern (Interactive Child)

The most common forward use case is a child machine that needs **user interaction** before completing. Unlike webhooks (where an external system calls the child), forward endpoints let users interact with the child **through the parent's routes**.

### Webhook vs Forward

| Aspect | Webhook | Forward |
|--------|---------|---------|
| Who calls child? | External system (e.g., Stripe) | User via parent endpoint |
| Child needs own routes? | Yes (`endpoints` on child) | No (auto-exposed via parent's `forward` config) |
| Trigger | 3rd-party callback | User HTTP request |
| Use case | Payment gateways, SMS providers | Multi-step forms, approvals, interactive workflows |
| Response contains | Child state only | Parent + child state combined |
| Validation | Child's own endpoint handler | Child's `EventBehavior` (auto-resolved) |

### Full Example: Order with Interactive Payment

A parent machine delegates to a payment child that requires the user to provide a card number and then confirm the payment:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class OrderMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'order',
                'initial' => 'idle',
                'context' => ['order_id' => null],
                'states'  => [
                    'idle' => [
                        'on' => ['START' => 'processing_payment'],
                    ],
                    'processing_payment' => [
                        'machine' => PaymentFlowMachine::class,
                        'queue'   => 'default',
                        'with'    => ['order_id'],
                        'forward' => [
                            'PROVIDE_CARD',                      // Format 1: forward as-is
                            'CONFIRM_PAYMENT' => [               // Format 3: with endpoint customization
                                'contextKeys' => ['card_last4', 'status'],
                                'status'      => 200,
                            ],
                        ],
                        'on'    => ['CANCEL' => 'cancelled'],
                        '@done' => 'completed',
                        '@fail' => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                    'cancelled' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START'  => StartOrderEvent::class,
                    'CANCEL' => CancelOrderEvent::class,
                ],
            ],
            endpoints: [
                'START',
                'CANCEL',
            ],
        );
    }
}
```

The child machine defines the events and transitions but does **not** define its own `endpoints`:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class PaymentFlowMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'payment_flow',
                'initial' => 'awaiting_card',
                'context' => [
                    'order_id'   => null,
                    'card_last4' => null,
                    'status'     => 'pending',
                ],
                'states' => [
                    'awaiting_card' => [
                        'on' => [
                            'PROVIDE_CARD' => [
                                'target'  => 'awaiting_confirmation',
                                'actions' => 'storeCardAction',
                            ],
                        ],
                    ],
                    'awaiting_confirmation' => [
                        'on' => [
                            'CONFIRM_PAYMENT' => 'charged',
                        ],
                    ],
                    'charged' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'PROVIDE_CARD'    => ProvideCardEvent::class,
                    'CONFIRM_PAYMENT' => ConfirmPaymentEvent::class,
                ],
                'actions' => [
                    'storeCardAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $cardNumber = $event->payload['card_number'] ?? '';
                        $ctx->set('card_last4', substr($cardNumber, -4));
                        $ctx->set('status', 'card_provided');
                    },
                ],
            ],
        );
    }
}
```

The flow works like this:

```
User: POST /orders/{order}/start
  → Parent enters 'processing_payment'
  → Child spawns on queue → persists in 'awaiting_card'

User: POST /orders/{order}/provide-card  { card_number: "4111111111111111" }
  → Parent.send(PROVIDE_CARD)
  → tryForwardEventToChild() routes to child
  → Child transitions: awaiting_card → awaiting_confirmation
  → Response: { parent.value, child.value, child.context }

User: POST /orders/{order}/confirm-payment  { confirmation_code: "ABC" }
  → Parent.send(CONFIRM_PAYMENT)
  → tryForwardEventToChild() routes to child
  → Child transitions: awaiting_confirmation → charged (final)
  → ChildMachineCompletionJob dispatched → parent @done fires
  → Response: { parent.value, child.value, child.context }
```

### Endpoint Customization (Format 3)

Forward entries support the same endpoint customization keys as regular endpoints. Use the array format (Format 3) when you need control over the auto-generated route:

<!-- doctest-attr: ignore -->
```php
'forward' => [
    'PROVIDE_CARD' => [
        'child_event'      => 'PROVIDE_CARD',     // Rename for child (optional)
        'uri'              => '/enter-payment',    // Custom URI (default: /provide-card)
        'method'           => 'PATCH',             // HTTP method (default: POST)
        'middleware'       => ['throttle:10'],      // Route middleware
        'action'           => CustomAction::class,  // Parent-level action lifecycle
        'result'           => CustomResult::class,  // ResultBehavior (receives ForwardContext)
        'contextKeys'      => ['card_last4'],       // Filter child context in response
        'status'           => 202,                  // HTTP status code
        'available_events' => false,                // Suppress available_events in response
    ],
],
```

## Comparison Table

| Aspect | Sync | Async (Queue) | Fire-and-Forget (Queue, no `@done`) |
|--------|------|---------------|-------------------------------------|
| Child execution | Inline, blocking | On queue worker | On queue worker |
| Parent state | Transitions immediately | Stays in delegating state | Stays or transitions (`@always`/`target`) |
| Persistence | Optional | Required (parent + child) | Required (child only) |
| External I/O | Not recommended | Designed for it | Designed for it |
| Webhook support | No | Yes | No (parent doesn't track child) |
| Forward endpoint | Not applicable | Supported | Not applicable |
| `@timeout` | Not applicable | Supported | Not applicable |
| `MachineChild` record | No | Yes | No |
| Complexity | Simple | More moving parts | Simple (no lifecycle tracking) |
