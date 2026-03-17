# Machine Delegation

Machine delegation allows a state to **delegate** its work to another machine. When the state is entered, the child machine starts. When the child completes (reaches a final state), the parent's `@done` transition fires.

This follows the same logic as `type: 'parallel'`:
- **Parallel state:** "This state contains parallel regions; when all reach final, `@done` fires"
- **Machine delegation:** "This state delegates to another machine; when child reaches final, `@done` fires"

## Basic Example

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
                    'payment_result' => null,
                ],
                'states' => [
                    'validating' => [
                        'machine' => ValidationMachine::class,
                        'with'    => ['order_id'],
                        '@done'   => [
                            'target'  => 'processing_payment',
                            'actions' => 'storeValidationResultAction',
                        ],
                        '@fail' => 'validation_failed',
                    ],
                    'processing_payment' => [
                        'machine' => PaymentMachine::class,
                        'with'    => ['order_id'],
                        '@done'   => 'completed',
                        '@fail'   => 'payment_failed',
                    ],
                    'completed'         => ['type' => 'final'],
                    'validation_failed' => ['type' => 'final'],
                    'payment_failed'    => ['type' => 'final'],
                ],
            ],
        );
    }
}
```

**Reads as:** "`validating` state delegates to `ValidationMachine`. Passes `order_id` to the child. When the child completes, stores the validation result and transitions to `processing_payment`."

## Config Reference

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `machine` | `string` (FQCN) | Yes | Child machine class. Must extend `Machine`. |
| `with` | `array\|Closure` | No | Data to pass from parent context to child. |
| `@done` | `string\|array` | No | Fires when child reaches a final state. Absence signals fire-and-forget. |
| `@fail` | `string\|array` | No | Fires when child fails. Not valid without `@done`. |
| `@timeout` | `array` | No | Fires when child doesn't complete within the given time. Async only. Not valid without `@done`. |
| `queue` | `bool\|string\|array` | No | Run child asynchronously on a queue. **Required** for fire-and-forget. |
| `forward` | `array` | No | Event types to forward from parent to the running child. Not valid without `@done`. |
| `on` | `array` | No | Additional events the parent can handle while child is running. |
| `target` | `string` | No | Fire-and-forget + immediate transition. Requires `queue`. Mutually exclusive with `@done`. |

## `with` — Context Transfer

The `with` key controls what data flows from parent context to child context. Three formats are supported:

<!-- doctest-attr: ignore -->
```php
// Format 1: Same-named keys
'with' => ['order_id', 'total_amount'],
// Child context receives: { order_id: ..., total_amount: ... }

// Format 2: Key mapping (child_key => parent_key)
'with' => [
    'id'     => 'order_id',        // child sees 'id', parent has 'order_id'
    'amount' => 'total_amount',    // child sees 'amount', parent has 'total_amount'
],

// Format 3: Dynamic (closure)
'with' => fn (ContextManager $ctx) => [
    'order_id' => $ctx->get('order_id'),
    'amount'   => $ctx->get('total_amount') * 100,
],
```

Without `with`, the child starts with its own default context. No parent data is transferred automatically.

## `@done` — Child Completion

When the child machine reaches a final state, the parent's `@done` transition fires. Uses the standard transition format:

<!-- doctest-attr: ignore -->
```php
// String shorthand
'@done' => 'next_state',

// With actions
'@done' => [
    'target'  => 'next_state',
    'actions' => 'handleResultAction',
],

// Multi-branch guarded fork
'@done' => [
    ['target' => 'approved', 'guards' => 'isApprovedGuard'],
    ['target' => 'review',   'actions' => 'requestReviewAction'],
],
```

### Accessing Child Result Data

When `@done` fires, the event is a `ChildMachineDoneEvent` with typed accessors for `output()`, `result()`, `childMachineId()`, and `childMachineClass()`. When `@fail` fires, the event is a `ChildMachineFailEvent` with `errorMessage()`, `output()`, and identity accessors.

See [Data Flow — `@done` Event](/advanced/delegation-data-flow#child-parent-the-done-event) and [Data Flow — `@fail` Event](/advanced/delegation-data-flow#child-parent-the-fail-event) for typed accessor examples.

::: tip Time-Based Events
You can add `after` and `every` timers to transitions on delegation states. `@timeout` (child deadline) and `after`/`every` (state timers) coexist — they serve different purposes. See [Time-Based Events](/advanced/time-based-events).
:::

## `@fail` — Child Failure

Fires when the child machine throws an exception or reaches a failure state:

<!-- doctest-attr: ignore -->
```php
'@fail' => 'error_state',

// With guards for conditional handling
'@fail' => [
    ['target' => 'retrying', 'guards' => 'canRetryGuard', 'actions' => 'incrementRetryAction'],
    ['target' => 'failed',   'actions' => 'logFailureAction'],
],
```

## `@timeout` — Child Timeout

Only meaningful in async mode. Fires when the child doesn't complete within the specified time:

<!-- doctest-attr: ignore -->
```php
'processing_payment' => [
    'machine'  => PaymentMachine::class,
    'queue'    => 'payments',
    '@done'    => 'shipping',
    '@fail'    => 'payment_failed',
    '@timeout' => [
        'after'   => 300,                // seconds
        'target'  => 'payment_timed_out',
        'actions' => 'logTimeoutAction',
    ],
],
```

## Fire-and-Forget

When you need to spawn a child machine without tracking its result, omit `@done`. The child runs independently — its completion or failure does not affect the parent.

### Stay in State (primary pattern)

The state spawns the child on entry and continues functioning normally with its own `on` events:

<!-- doctest-attr: ignore -->
```php
'prevented' => [
    'machine' => VerificationMachine::class,
    'with'    => ['tckn'],
    'queue'   => 'verifications',
    // No @done → fire-and-forget
    'on' => ['RETRY' => 'retrying'],
],
```

**Reads as:** "When entering `prevented`, spawn `VerificationMachine` in the background. The state handles its own events normally."

### Spawn and Move On (with @always)

Use `@always` to immediately transition after spawning the child:

<!-- doctest-attr: ignore -->
```php
'dispatching_verification' => [
    'machine' => VerificationMachine::class,
    'with'    => ['tckn'],
    'queue'   => 'verifications',
    'on'      => ['@always' => 'prevented'],
],
```

### Spawn and Move On (with target)

Alternatively, use `target` for an explicit fire-and-forget transition (consistent with the `job` key pattern):

<!-- doctest-attr: ignore -->
```php
'dispatching_verification' => [
    'machine' => VerificationMachine::class,
    'with'    => ['tckn'],
    'queue'   => 'verifications',
    'target'  => 'prevented',
],
```

### Rules

- Fire-and-forget requires `queue` — the child must run asynchronously.
- `@done` absence is the signal — no new keyword needed.
- `@fail`, `@timeout`, `output`, and `forward` are not valid without `@done`.
- The child still persists its own `MachineEvent` records (observability).
- The child receives parent identity (`sendToParent()` still works).

## `queue` — Async Execution

By default, child machines run **synchronously** (inline). Add `queue` to run the child on a Laravel queue:

<!-- doctest-attr: ignore -->
```php
// Default queue
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

**Sync vs Async:**
- **Sync (default):** Child runs inline. Parent transitions to `@done` immediately after child completes. Simplest option.
- **Async (queue):** Child runs on a queue worker. Parent stays in the delegating state until a `ChildMachineCompletionJob` arrives with the result.

## `forward` — Event Forwarding

Forward parent events to the running child machine. Useful when the child needs to receive external updates:

<!-- doctest-attr: ignore -->
```php
'processing' => [
    'machine' => PaymentMachine::class,
    'queue'   => 'payments',
    'forward' => [
        'APPROVE_PAYMENT',                     // Forward as-is
        'UPDATE_SHIPPING_INFO' => 'UPDATE_INFO', // Rename for child
    ],
    '@done' => 'completed',
],
```

## Delegation Inside Parallel States

The `machine` key works at any state level, including within parallel regions. Each region runs its own child machine. The region's `@done` fires when its child completes. The parallel state's `@done` fires when **all** regions reach final.

See [Delegation Patterns — Parallel Orchestration](/advanced/delegation-patterns#parallel-orchestration) for a full example.

## Testing with Machine Faking

Use `Machine::fake()` to short-circuit child machines in tests:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;

// Fake a child machine to return a specific result
PaymentMachine::fake(result: ['payment_id' => 'pay_123']);

// Run the parent machine — child is short-circuited
$machine = OrderWorkflowMachine::create();
$machine->send(['type' => 'START']);

// Assert the child was invoked
PaymentMachine::assertInvoked();
PaymentMachine::assertInvokedTimes(1);
PaymentMachine::assertInvokedWith(['order_id' => 'ORD-1']);
PaymentMachine::assertNotInvoked(); // or verify it was NOT invoked

// Fake a failure
PaymentMachine::fake(fail: true, error: 'Insufficient funds');

// Reset all fakes
Machine::resetMachineFakes();
```

`Machine::fake()` options:
- `result: array` — The result the child "returns" via `@done`
- `fail: true` — Child triggers `@fail` instead of `@done`
- `error: string` — Error message for `@fail`
- `finalState: string` — Override the final state name

## Infinite Loop Protection

Each machine — parent and child — has its own independent depth counter. A sync child with a deep `@always` chain does not consume the parent's depth budget.

::: warning Known Limitation
When `@done` or `@fail` routes the parent to a new state, `@always` transitions on that new state are **not** automatically evaluated. This is because child completion routing uses an internal bypass path (`executeChildTransitionBranch`) that does not go through the standard `transition()` method. This may be addressed in a future release.
:::
