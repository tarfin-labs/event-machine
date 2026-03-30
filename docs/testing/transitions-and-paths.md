# Testing Transitions & Paths

Test state transitions, guard behavior, and complete lifecycle paths using `Machine::test()`.

## Single Transitions

`assertTransition()` sends the named event to the machine and verifies that the machine lands in the expected target state. It is the simplest way to confirm that a single event produces the correct outcome.

<!-- doctest-attr: ignore -->
```php
AllInvocationPointsMachine::test()
    ->assertTransition('PROCESS', 'active');
```

## Guard Testing

A guarded transition is one that a guard condition has rejected: the event is received but the machine stays in its current state without transitioning. `assertGuarded()` confirms this blocking behavior, while `assertTransition()` confirms the transition succeeds when the guard passes.

<!-- doctest-attr: ignore -->
```php
// Guard blocks — state unchanged
AllInvocationPointsMachine::test(['count' => 0])
    ->assertGuarded('PROCESS');

// Guard passes — transition occurs
AllInvocationPointsMachine::test(['count' => 5])
    ->assertTransition('PROCESS', 'active');

// Force guard result via faking
IsCountPositiveGuard::shouldReturn(true);
AllInvocationPointsMachine::test(['count' => 0])
    ->assertTransition('PROCESS', 'active');  // guard bypassed
```

## Guard-Specific Assertions

Verify which guard blocked an event with `assertGuardedBy()`:

<!-- doctest-attr: ignore -->
```php
// Assert a specific guard blocked the transition
AllInvocationPointsMachine::test(['count' => 0])
    ->assertGuardedBy('PROCESS', IsCountPositiveGuard::class);

// Debug all guard results
$test = AllInvocationPointsMachine::test(['count' => 0]);
$results = $test->debugGuards('PROCESS');
// ['IsCountPositiveGuard' => false]
```

## Validation Guard Testing

`ValidationGuardBehavior` differs from a regular guard in one important way: instead of silently blocking the transition, it throws a validation exception with structured error messages. `assertValidationFailed()` catches that exception and lets you assert which field caused the failure.

<!-- doctest-attr: ignore -->
```php
OrderMachine::test()
    ->assertValidationFailed(
        ['type' => 'PAY', 'payload' => ['amount' => -1]],
        'amount',  // expected error key
    );
```

## Path Testing — Full Lifecycle

`assertPath()` drives the machine through an entire sequence in one call: it sends each event in order and immediately asserts the expected state and context after each step. This makes it the primary tool for verifying multi-step workflows, because a single `assertPath()` replaces a chain of individual `send()` + `assertState()` calls.

<!-- doctest-attr: ignore -->
```php
TrafficLightsMachine::test()
    ->assertPath([
        ['event' => 'INCREASE', 'state' => 'active', 'context' => ['count' => 1]],
        ['event' => 'INCREASE', 'state' => 'active', 'context' => ['count' => 2]],
    ]);
```

## Hierarchical State Transitions

Nested (compound) states are identified with dot notation, where the parent state name and child state name are joined by a dot (e.g., `checkout.shipping`). Use the same notation in `assertState()` and `assertTransition()` to target or verify any level of the hierarchy.

<!-- doctest-attr: ignore -->
```php
CheckoutMachine::test()
    ->assertState('checkout.shipping')
    ->assertTransition('CONTINUE', 'checkout.payment')
    ->assertTransition('CONTINUE', 'checkout.review')
    ->assertTransition('CONFIRM', 'completed');
```

## @always Transitions

`@always` transitions fire automatically when their guard condition is met:

<!-- doctest-attr: ignore -->
```php
SyncMachine::test(['is_ready' => false])
    ->assertState('waiting')
    ->send(['type' => 'UPDATE', 'payload' => ['is_ready' => true]])
    ->assertState('processing');  // @always transition fired
```

Verify transient router states were visited using `assertTransitionedThrough()`:

<!-- doctest-attr: ignore -->
```php
// @always states appear in history even though they resolve immediately
OrderMachine::test()
    ->send('SUBMIT')
    ->assertTransitionedThrough(['idle', 'router', 'processing'])
    ->assertState('processing');
```

### Testing Event Preservation (v8+)

Verify that `@always` actions receive the original event payload:

<!-- doctest-attr: ignore -->
```php
// Action on @always transition captures the original event
OrderMachine::test()
    ->send(['type' => 'SUBMIT', 'payload' => ['tckn' => '12345678901']])
    ->assertState('verification')
    ->assertContext('captured_payload', ['tckn' => '12345678901']);
```

## Raised Events

An action can push additional events onto the machine's internal queue using `raise()`. Those raised events are processed immediately after the current transition completes, exactly as if they had been sent from outside — enabling a single external event to trigger a chain of further transitions. `assertHistoryContains()` lets you verify that a raised event was processed during that chain.

<!-- doctest-attr: ignore -->
```php
OrderMachine::test()
    ->send('PROCESS')
    ->assertState('completed')
    ->assertHistoryContains('PROCESSING_COMPLETE');
```

## Path Coverage Analysis

EventMachine can statically enumerate all paths through a machine definition and track which paths your tests exercise.

### Enumerating Paths

```bash
php artisan machine:paths "App\Machines\FindeksMachine"
```

This produces a complete list of all possible paths grouped by type: HAPPY, FAIL, TIMEOUT, LOOP, GUARD_BLOCK, DEAD_END.

### Tracking Coverage in Tests

Add the `TracksPathCoverage` trait to your test suite. It automatically enables the tracker, cleans stale data, and exports coverage when the process exits:

<!-- doctest-attr: ignore -->
```php
// In tests/Pest.php:
use Tarfinlabs\EventMachine\Testing\TracksPathCoverage;

uses(TracksPathCoverage::class)->in('Feature', 'Unit');

// Or in a PHPUnit base TestCase:
use Tarfinlabs\EventMachine\Testing\TracksPathCoverage;

abstract class TestCase extends BaseTestCase
{
    use TracksPathCoverage;
}
```

The trait works with both PHPUnit and Pest, including parallel test runners (Paratest). Each worker writes a separate coverage file; the `machine:coverage` command merges them automatically.

The tracker records state transitions through `TestMachine`. Paths are completed when `assertFinished()` or `assertState()` (on a FINAL state) is called.

### Coverage Assertions

<!-- doctest-attr: ignore -->
```php
// Assert all enumerated paths are covered by tests
FindeksMachine::assertAllPathsCovered();

// Assert at least 90% of paths are covered
FindeksMachine::assertPathCoverage(minimum: 90.0);
```

### Path Types

| Type | Meaning |
|------|---------|
| HAPPY | Reached a FINAL state without @fail or timer |
| FAIL | Path contains an @fail step |
| TIMEOUT | Path contains a timer-triggered step or @timeout |
| LOOP | Cycle detected — path revisits a state |
| GUARD_BLOCK | All guards fail with no fallback — event swallowed |
| DEAD_END | ATOMIC state with no transitions and not FINAL |

### Child Machine Visibility

Path analysis treats child machines as opaque (compositional verification). Each machine's paths are analyzed independently. The output shows:

- **Child machine/job class names** on invoke state steps (e.g., `processing (PaymentMachine)`)
- **Async/sync mode and queue** in the stats section
- **Unhandled child outcome warnings** when a child has final states the parent doesn't route via `@done.{state}`

To see a child machine's internal paths, run `machine:paths` on the child separately.

### CI Integration

```yaml
- run: composer test
- run: php artisan machine:coverage FindeksMachine --min=100
```

See [Artisan Commands](/laravel-integration/artisan-commands#machine-paths) for full command documentation.

::: tip Related
See [TestMachine](/testing/test-machine) for the complete assertion API,
[Isolated Testing](/testing/isolated-testing) for unit-level guard testing,
[Fakeable Behaviors](/testing/fakeable-behaviors) for guard faking,
and [Recipes](/testing/recipes) for common real-world patterns.
:::
