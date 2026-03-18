# Testing Strategy

EventMachine is testable at every level. A well-designed test suite uses the right level for each concern -- fast isolated tests for logic, integration tests for flow, and end-to-end tests for the full pipeline.

## Four Layers

| Layer | What to Test | Speed | Dependencies |
|-------|-------------|-------|-------------|
| **Unit** | Individual behaviors (guards, actions, calculators) | Milliseconds | None (in-memory) |
| **Integration** | State flow, transition paths, guard gating | Fast | SQLite in-memory |
| **E2E** | Full pipeline: timers, scheduled events, persistence | Moderate | SQLite + artisan |
| **LocalQA** | Async delegation, parallel dispatch, locking | Slow | MySQL + Redis + Horizon |

### Unit: Isolated Behavior Testing

Test individual guards, actions, and calculators without booting a machine. Use `State::forTesting()` and `runWithState()`.

```php no_run
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\ContextManager;

class IsRetryAllowedGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return $context->get('retry_count') < 3;
    }
}

// Test: guard returns true when retries remain
$state = State::forTesting(['retry_count' => 2]);
assert(IsRetryAllowedGuard::runWithState($state) === true);

// Test: guard returns false when retries exhausted
$state = State::forTesting(['retry_count' => 3]);
assert(IsRetryAllowedGuard::runWithState($state) === false);
```

**Test here:** Guard boolean logic, action side effects on context, calculator math.

### Integration: State Flow Testing

Test transition paths and guard gating using the `TestMachine` fluent API. This boots the machine and runs real transitions.

```php ignore
// Test: order follows happy path

OrderWorkflowMachine::test(['order_id' => 'ORD-001', 'order_total' => 500])
    ->assertState('idle')
    ->send('ORDER_SUBMITTED')
    ->assertState('submitted')
    ->send('PAYMENT_RECEIVED')
    ->assertState('processing')
    ->assertContext('order_total', 500);
```

```php ignore
// Test: guard blocks transition when total is zero

OrderWorkflowMachine::test(['order_id' => 'ORD-002', 'order_total' => 0])
    ->assertState('idle')
    ->send('ORDER_SUBMITTED')
    ->assertState('idle');   // guard blocked, stayed in idle
```

**Test here:** Transition paths, context changes across transitions, guard pass/fail scenarios, multi-branch routing.

### E2E: Full Pipeline

Test the complete lifecycle including persistence, timer processing, and scheduled events. These tests use artisan commands and real database writes.

```php ignore
// Test: timer fires after advancing time

OrderWorkflowMachine::test(['order_id' => 'ORD-003', 'order_total' => 100])
    ->send('ORDER_SUBMITTED')
    ->assertState('awaiting_payment')
    ->assertHasTimer('ORDER_EXPIRED')
    ->advanceTimers(Timer::days(7))
    ->assertState('cancelled');
```

**Test here:** Timer sweep, scheduled event processing, persist/restore cycles, artisan command output.

### LocalQA: Real Infrastructure

Tests requiring real MySQL, Redis, and Laravel Horizon for async features. These live in `tests/LocalQA/` and are excluded from `composer test`.

**Test here:** Async child delegation, queue job dispatch and completion, parallel dispatch with database locking, timeout handling, concurrent state mutation.

## What to Test Where

| Concern | Layer | Why |
|---------|-------|-----|
| Guard returns correct boolean | Unit | Fast, no machine needed |
| Action modifies context correctly | Unit | Isolated side effect |
| Calculator produces correct value | Unit | Pure computation |
| Event triggers correct transition | Integration | Needs machine flow |
| Guard blocks transition | Integration | Needs machine + guard wiring |
| Context propagates across states | Integration | Multi-step flow |
| `@always` routing | Integration | Needs guard evaluation chain |
| Timer fires at deadline | E2E | Needs `advanceTimers()` |
| Scheduled event targets instances | E2E | Needs resolver + scheduler |
| Persist and restore state | E2E | Needs database |
| Async child completes and reports | LocalQA | Needs real queue |
| Parallel dispatch with locking | LocalQA | Needs MySQL locks |
| Available events correct per state | Integration | `assertAvailableEvent`, `assertForwardAvailable` |

## Machine::fake() for Isolation

When testing a parent machine, you do not want child machines to actually run. `Machine::fake()` short-circuits delegation, returning a configurable result.

```php ignore
// Arrange: fake the child machine before creating the parent
PaymentMachine::fake(result: ['payment_id' => 'pay_123'], finalState: 'settled');

// Act + Assert: test parent orchestration without running children
OrderWorkflowMachine::test(['order_id' => 'ORD-004'])
    ->send('ORDER_SUBMITTED')
    ->assertState('shipping');   // child faked, @done fired, parent moved to next
// No cleanup needed — InteractsWithMachines handles it
```

`Machine::fake()` is a static call on the child machine class — call it **before** creating the parent. The `finalState` parameter determines which `@done.{state}` route fires on the parent. See [Inter-Machine Testing](/testing/delegation-testing) for the full API.

This lets you test the parent's orchestration logic (routing, error handling, context passing) without coupling to child machine internals.

### Standalone Machine Isolation

Use `Machine::fake()` to isolate controller or service tests from the machine pipeline:

<!-- doctest-attr: ignore -->
```php
CarSalesMachine::fake();
// Controller calls Machine::create() → gets stub
// Controller calls send() → no-op
// Controller calls persist() → no-op
// Your test only verifies the controller's own logic
```

## advanceTimers() for Time-Based Testing

`advanceTimers()` simulates time passage without `sleep()`. It updates the machine's internal clock and processes any timers that would have fired.

```php ignore
// Test: payment reminder sent after 1 day, order expires after 7

OrderWorkflowMachine::test(['order_id' => 'ORD-005'])
    ->send('ORDER_SUBMITTED')
    ->assertState('awaiting_payment')
    ->advanceTimers(Timer::days(1))
    ->assertBehaviorRan(SendPaymentReminderAction::class)
    ->assertState('awaiting_payment')       // still waiting
    ->advanceTimers(Timer::days(6))         // 7 days total
    ->assertState('cancelled');              // expired
```

## Anti-Pattern: Testing Internal Events

```php ignore
// Anti-pattern: testing raised event details

->send('ORDER_SUBMITTED')
->assertEventRaised('VALIDATION_STARTED')    // internal implementation detail
->assertEventRaised('VALIDATION_PASSED')     // brittle -- rename breaks test
```

Internal events (raised events, `@always` mechanics) are implementation details. Tests should assert on _observable_ outcomes: final state, context values, behavior execution.

**Fix:** Test the result, not the mechanism.

```php ignore
->send('ORDER_SUBMITTED')
->assertState('processing')                  // validates the outcome
->assertContext('is_validated', true)         // checks observable data
```

## Anti-Pattern: Testing Transition Order

```php ignore
// Anti-pattern: asserting exact transition sequence

->send('ORDER_SUBMITTED')
->assertTransitionSequence([
    'idle -> validating',
    'validating -> calculating',
    'calculating -> processing',
])
```

The internal routing through `@always` states may change during refactoring. Tests should verify the final state, not the path taken.

**Fix:** Assert on the end state and any observable side effects.

```php ignore
->send('ORDER_SUBMITTED')
->assertState('processing')
->assertBehaviorRan(CalculateOrderTotalAction::class)
```

## Example: Order Workflow at All Four Layers

```php ignore
// UNIT: guard logic
$state = State::forTesting(['order_total' => 500]);
assert(IsOrderTotalValidGuard::runWithState($state) === true);

$state = State::forTesting(['order_total' => 0]);
assert(IsOrderTotalValidGuard::runWithState($state) === false);
```

```php ignore
// INTEGRATION: happy path
OrderWorkflowMachine::test(['order_id' => 'ORD-100', 'order_total' => 500])
    ->send('ORDER_SUBMITTED')
    ->assertState('processing')
    ->send('PAYMENT_RECEIVED')
    ->assertState('paid');
```

```php ignore
// E2E: timer expiry
OrderWorkflowMachine::test(['order_id' => 'ORD-101', 'order_total' => 500])
    ->send('ORDER_SUBMITTED')
    ->assertState('awaiting_payment')
    ->advanceTimers(Timer::days(7))
    ->assertState('cancelled');
```

```php ignore
// LOCAL QA: async child delegation (tests/LocalQA/)
// Requires MySQL + Redis + Horizon running
OrderWorkflowMachine::create(['order_id' => 'ORD-102', 'order_total' => 500])
    ->send(['type' => 'ORDER_SUBMITTED']);
// Assert child job dispatched, await completion, verify parent advanced
```

## Guidelines

1. **Unit tests for behavior logic.** Fast, isolated, no machine booting. Use `runWithState()`.

2. **Integration tests for state flow.** Use `TestMachine` fluent API. Cover happy path, guard blocking, and error paths.

3. **E2E tests for infrastructure.** Timers, scheduled events, persistence. Use `advanceTimers()`.

4. **LocalQA for async features.** Real queue, real locks. Run separately from CI.

5. **Test outcomes, not internals.** Assert on final state and context, not on raised events or transition sequences.

6. **Fake children in parent tests.** `Machine::fake()` isolates parent orchestration from child implementation.

## Related

- [Testing Overview](/testing/overview) -- testing layers reference
- [Isolated Testing](/testing/isolated-testing) -- `State::forTesting()` and `runWithState()`
- [TestMachine](/testing/test-machine) -- fluent API reference
- [Delegation Testing](/testing/delegation-testing) -- `Machine::fake()`
- [Time-Based Testing](/testing/time-based-testing) -- `advanceTimers()`
- [Recipes](/testing/recipes) -- common real-world patterns
