# Action Design

Actions are the side-effect layer of your machine. They send emails, write to databases, call APIs, and mutate context. Because actions run during transitions, they must be designed with care -- especially around idempotency and responsibility boundaries.

## Idempotency

Actions may execute more than once. Queue workers retry failed jobs. Timer sweeps re-fire missed events. A deployment may restart a transition mid-flight. If your action is not idempotent, a retry can charge a customer twice or send duplicate emails.

**Idempotent** means: running the action again with the same input produces the same result and the same side effects as running it once.

```php no_run
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\ContextManager;

// Idempotent: uses an idempotency key
class ChargePaymentAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // Payment gateway deduplicates on idempotency_key
        $result = PaymentGateway::charge(
            amount: $context->get('order_total'),
            idempotencyKey: $context->get('orderId') . '_charge',
        );

        $context->set('chargeId', $result->id);
    }
}
```

```php no_run
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\ContextManager;

// NOT idempotent: creates duplicate records on retry
class CreateInvoiceAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // BAD: no dedup -- retry creates a second invoice
        $invoice = Invoice::create(['order_id' => $context->get('orderId')]);
        $context->set('invoiceId', $invoice->id);
    }
}
```

**Fix:** Use `firstOrCreate`, upsert, or an idempotency key.

## Entry vs Transition vs Exit

EventMachine supports three action positions. Each serves a distinct purpose.

| Position | When It Runs | Typical Use |
|----------|-------------|-------------|
| `entry` | Every time the state is entered | Initialization, notifications, loading data |
| Transition (`actions`) | For a specific event transition | Business logic tied to that event |
| `exit` | Every time the state is exited | Cleanup, logging, releasing resources |

```php ignore
'awaiting_payment' => [
    'entry' => 'sendPaymentReminderAction',           // runs on every entry
    'exit'  => 'logPaymentPhaseCompletedAction',      // runs on every exit
    'on'    => [
        'PAYMENT_RECEIVED' => [
            'target'  => 'processing',
            'actions' => 'recordPaymentAction',        // runs only for this event
        ],
        'ORDER_CANCELLED' => 'cancelled',              // exit action still runs
    ],
],
```

## Anti-Pattern: Action That Throws to Prevent Transition

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

// Anti-pattern: using exception as flow control

class ValidateOrderAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        if ($context->get('order_total') <= 0) {
            throw new \RuntimeException('Invalid order total');
        }
    }
}
```

Actions run _after_ the transition is selected. Throwing does not cleanly prevent the transition -- it leaves the machine in an inconsistent state.

**Fix:** Use a guard. Guards run _before_ the transition fires and cleanly block it.

```php ignore
'on' => [
    'ORDER_CONFIRMED' => [
        'target' => 'processing',
        'guards' => 'isOrderTotalValidGuard',  // blocks transition cleanly
        'actions' => 'processOrderAction',
    ],
],
```

## Anti-Pattern: Ordering Dependency Between Actions

```php ignore
// Anti-pattern: second action depends on first action's context write

'on' => [
    'ORDER_SUBMITTED' => [
        'target'  => 'processing',
        'actions' => ['calculateTotalAction', 'applyDiscountAction'],
        //           ^^^ applyDiscountAction reads total set by calculateTotalAction
    ],
],
```

Action execution order within an array is sequential and guaranteed, but coupling actions through shared context writes makes them fragile and hard to test independently.

**Fix:** Use a calculator for the computation, or split the work into two states.

```php ignore
// Option A: calculator computes, action uses
'on' => [
    'ORDER_SUBMITTED' => [
        'target'      => 'processing',
        'calculators' => ['orderTotalCalculator', 'discountCalculator'],
        'actions'     => 'finalizeOrderAction',
    ],
],

// Option B: two states for sequential processing
'states' => [
    'calculating' => [
        'entry' => 'calculateTotalAction',
        'on'    => ['@always' => 'applying_discount'],
    ],
    'applying_discount' => [
        'entry' => 'applyDiscountAction',
        'on'    => ['@always' => 'processing'],
    ],
    'processing' => [],
],
```

## Anti-Pattern: Mega-Action

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

// Anti-pattern: one action doing five things

class ProcessOrderAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // 1. Validate inventory
        // 2. Charge payment
        // 3. Generate invoice
        // 4. Send confirmation email
        // 5. Update analytics
    }
}
```

A single action doing five things is five reasons to change, five things to mock, and five potential failure points tangled together.

**Fix:** One responsibility per action. Compose them in the transition definition.

```php ignore
'on' => [
    'ORDER_CONFIRMED' => [
        'target'  => 'processing',
        'actions' => [
            'reserveInventoryAction',
            'chargePaymentAction',
            'generateInvoiceAction',
            'sendConfirmationEmailAction',
            'trackAnalyticsAction',
        ],
    ],
],
```

## raise() Patterns

`raise()` queues an internal event for processing after the current transition completes. It enables action chaining without tight coupling.

**Safe: linear chain**

```php ignore
// A -> raise(NEXT) -> B -> raise(DONE) -> C
// No cycles, terminates naturally
```

**Dangerous: circular chain**

```php ignore
// A -> raise(GO_B) -> B -> raise(GO_A) -> A -> ...
// Infinite loop -- MaxTransitionDepthExceededException at depth 100
```

## raise() vs sendTo() vs dispatchTo()

| Method | Scope | Execution | Use When |
|--------|-------|-----------|----------|
| `raise()` | Same machine | Sync, same macrostep | Chaining steps within a workflow |
| `sendTo()` | Different machine | Sync, blocking | Immediate cross-machine coordination |
| `dispatchTo()` | Different machine | Async, queued | Fire-and-forget notifications |

Generally, prefer `raise()` for internal flow control, `sendTo()` when you need the target's result immediately, and `dispatchTo()` for loose coupling.

## Guidelines

1. **Design for retry.** Assume every action will run at least twice. Use idempotency keys, `firstOrCreate`, or upserts.

2. **One responsibility per action.** If you find yourself adding "and" to describe what an action does, split it.

3. **Entry for setup, transition for logic, exit for cleanup.** This separation makes the machine self-documenting.

4. **Never throw to block transitions.** Use guards for that. Actions assume the transition has already been approved.

5. **Keep `raise()` chains linear.** Circular raise chains hit the depth limit. If you need a loop, use explicit events sent from outside the macrostep.

## Scenario-Friendly Design

Scenarios intercept **delegations** (job/machine invoke), not transition actions. Actions attached to transitions, entry, or exit run with real side effects during scenario execution unless explicitly overridden in `plan()`.

An action with a lazy "fallback to real API" branch is a design smell:

<!-- doctest-attr: ignore -->
```php
// Anti-pattern: lazy I/O fallback in an action
public function __invoke(MyContext $ctx): void
{
    if ($ctx->queryId !== null) {
        return;
    }
    // This fires during scenario runs if queryId is not pre-populated
    $ctx->queryId = ExternalApi::getQueryId($ctx->tckn);
}
```

Scenarios cannot simulate this without action-level overrides, which partially defeats their purpose. Instead, put external calls into job or machine delegations:

1. **`ProvidesFailure`** gives you typed error handling.
2. **Scenarios can intercept** the delegation cleanly via `plan()` outcomes.
3. **Retry/timeout policies** can be expressed declaratively in the machine config.

<!-- doctest-attr: ignore -->
```php
// Preferred: external I/O in a dedicated delegation state
'querying_external_id' => [
    'job'   => FetchExternalIdJob::class,
    '@done' => 'processing',
    '@fail' => 'query_failed',
],
```

When refactoring is not feasible, override the action in the scenario's `plan()`:

<!-- doctest-attr: ignore -->
```php
'matching_phone' => [
    MatchAndStoreAction::class => ['queryId' => 'SCENARIO-001'],
],
```

See [Scenario Plan: Pitfalls](/advanced/scenario-plan#pitfalls) for more examples.

## Related

- [Actions](/behaviors/actions) -- reference documentation
- [Raised Events](/advanced/raised-events) -- `raise()` mechanics
- [Cross-Machine Messaging](/advanced/sendto) -- `sendTo()` and `dispatchTo()`
- [Guard Design](./guard-design) -- when to block transitions
- [Scenario Plan: Pitfalls](/advanced/scenario-plan#pitfalls) -- scenario-specific action gotchas
