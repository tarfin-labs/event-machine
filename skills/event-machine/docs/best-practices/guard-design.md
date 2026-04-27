# Guard Design

Guards decide whether a transition proceeds. They answer a yes/no question: "Given the current state, event, and context, should this transition fire?"

## The Rule

**Guards must be pure.** Given the same state, event, and context, a guard must always return the same result. No exceptions.

This means:

- No network calls
- No database queries
- No file I/O
- No writing to context
- No logging with side effects
- No calls to `now()` or `time()`

If a guard violates purity, it becomes unpredictable. Tests pass in isolation and fail in CI. A retry changes outcomes. A timeout alters business logic.

## Anti-Pattern: Guard with API Call

```php no_run
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\ContextManager;

// Anti-pattern: impure guard -- network call inside

class IsPaymentValidGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        // BAD: network failure changes guard result
        $response = Http::get("https://api.payment.com/verify/{$context->get('paymentId')}");

        return $response->json('status') === 'valid';
    }
}
```

If the API is down, the guard returns `false` and the transition is blocked -- not because of a business rule, but because of infrastructure. The machine silently takes the wrong path.

**Fix:** Call the API in a previous state's entry action. Store the result in context. Let the guard read it.

```php no_run
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\ContextManager;

// Step 1: Action fetches and stores
class VerifyPaymentAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $response = Http::get("https://api.payment.com/verify/{$context->get('paymentId')}");
        $context->set('paymentStatus', $response->json('status'));
    }
}

// Step 2: Guard reads stored value -- pure
class IsPaymentValidGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return $context->get('paymentStatus') === 'valid';
    }
}
```

## Anti-Pattern: Guard That Writes Context

```php
use Tarfinlabs\EventMachine\Behavior\GuardBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

// Anti-pattern: guard mutates context

class HasSufficientBalanceGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        // BAD: guard writes context
        $balance = $context->get('account_balance') - $context->get('order_total');
        $context->set('remaining_balance', $balance);

        return $balance >= 0;
    }
}
```

If the guard is evaluated multiple times (multi-branch transitions try guards in order), the context mutation happens on every evaluation. Worse, if the guard returns `false`, the mutation still happened.

**Fix:** Use a calculator for the computation. Calculators run once before guards and are designed for context mutation.

```php ignore
// Calculator computes the value
'on' => [
    'ORDER_CONFIRMED' => [
        'target'      => 'processing',
        'calculators' => 'remainingBalanceCalculator',
        'guards'      => 'hasSufficientBalanceGuard',
    ],
],
```

```php
use Tarfinlabs\EventMachine\Behavior\GuardBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

// Guard only reads -- pure
class HasSufficientBalanceGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return $context->get('remaining_balance') >= 0;
    }
}
```

## Anti-Pattern: Time-Dependent Guard

```php no_run
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\ContextManager;

// Anti-pattern: depends on current time

class IsNotExpiredGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return now()->lt($context->get('deadline'));
    }
}
```

This guard returns different results depending on when it is called -- violating purity. It also makes testing require time manipulation.

**Fix:** Use an `after` timer. Let the timer mechanism fire an `ORDER_EXPIRED` event when the deadline passes. The machine transitions via the timer, not a guard.

```php ignore
'awaiting_payment' => [
    'on' => [
        'PAYMENT_RECEIVED' => 'processing',
        'ORDER_EXPIRED'    => ['target' => 'cancelled', 'after' => Timer::days(7)],
    ],
],
```

## What Good Guards Look Like

```php
use Tarfinlabs\EventMachine\Behavior\GuardBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

// Simple comparison
class IsPaymentValidGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return $context->get('paymentStatus') === 'valid';
    }
}
```

```php
use Tarfinlabs\EventMachine\Behavior\GuardBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

// Threshold check
class HasSufficientBalanceGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return $context->get('remaining_balance') >= 0;
    }
}
```

```php
use Tarfinlabs\EventMachine\Behavior\GuardBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

// Boolean flag
class IsRetryAllowedGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return $context->get('retryCount') < 3;
    }
}
```

All three read context, compare, and return a boolean. No side effects. No external calls. Easy to test with `State::forTesting()`.

## Runtime Purity Enforcement

EventMachine does not just recommend guard purity -- it enforces it at runtime. Before evaluating guards on a transition branch, the engine snapshots the context. If any guard fails, the context is restored to the snapshot. This prevents side-effect leakage between branches in multi-path transitions.

However, this is a safety net, not a license to write impure guards. The snapshot/restore mechanism catches accidental context mutation but cannot undo external side effects (network calls, database writes, file I/O). Those escape the safety net entirely.

### Why API Calls in Guards Are Dangerous

An API call in a guard creates three problems:

1. **Non-determinism.** Network failures, timeouts, and rate limits change the guard's result for reasons unrelated to business logic. The machine silently takes the wrong path.
2. **Performance.** Guards may be evaluated multiple times during multi-branch resolution. Each evaluation repeats the API call.
3. **Untestable.** `State::forTesting()` and `runWithState()` cannot exercise network-dependent guards without mocking infrastructure.

The fix is always the same: move the external call to an **action** in a preceding state, store the result in context, and let the guard read the stored value.

### Calculator vs Guard: Division of Responsibility

| Responsibility | Calculator | Guard |
|---------------|-----------|-------|
| Compute derived values | Yes | No |
| Mutate context | Yes (designed for it) | No (rolled back on failure) |
| Make yes/no decisions | No | Yes |
| Call external services | No (use actions) | No (use actions) |
| Run before guards | Yes (always) | N/A |

If you find a guard doing computation before checking a condition, split it: put the computation in a calculator, put the check in the guard. The calculator runs once; the guard reads the result.

## Guidelines

1. **Pure, always.** Same inputs, same output. If you need external data, fetch it in an action first.

2. **No context mutation.** Use calculators for computation, actions for side effects. Guards only read. EventMachine will roll back accidental mutations via snapshot/restore, but relying on this is a code smell.

3. **Use boolean prefixes.** `Is`, `Has`, `Can`, `Should` -- these make the guard's purpose self-documenting.

4. **Prefer simple expressions.** A guard that requires 20 lines of logic is a sign that the computation belongs in a calculator with the guard simply checking the result.

5. **Test with `runWithState()`.** Pure guards are trivial to unit test in isolation.

## Related

- [Guards](/behaviors/guards) -- reference documentation
- [Calculators](/behaviors/calculators) -- compute values before guards
- [Isolated Testing](/testing/isolated-testing) -- unit testing guards
- [Naming Conventions](/building/conventions) -- boolean prefix rules
