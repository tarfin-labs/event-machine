# Testing Troubleshooting

Common mistakes and their fixes when testing EventMachine state machines.

## Guard Always Fails When Using fakingAllGuards()

**Symptom:** All guarded transitions are blocked after calling `fakingAllGuards()`.

**Cause:** `fakingAllGuards()` creates Mockery spies. Spies return `null` by default. In guard evaluation, `null` is falsy → guard fails.

**Fix:** Use the `guards:` parameter to set explicit return values:

<!-- doctest-attr: ignore -->
```php
// ❌ Guard returns null → fails
OrderMachine::test()
    ->fakingAllGuards()
    ->send('SUBMIT')  // guarded transition blocked

// ✅ Guard returns true → passes
OrderMachine::test(guards: [IsEligibleGuard::class => true])
    ->fakingAllGuards(except: [IsEligibleGuard::class])
    ->send('SUBMIT')  // works
```

## Action Runs During @always Before fakingAllActions()

**Symptom:** An action executes with real logic during machine initialization, even though `fakingAllActions()` is in the chain.

**Cause:** `Machine::test()` calls `getInitialState()` which fires `@always` transitions. `fakingAllActions()` in the fluent chain runs *after* init — too late for actions on `@always`.

**Fix:** Use the `faking:` parameter to spy actions before init:

<!-- doctest-attr: ignore -->
```php
// ❌ StoreAction runs before fakingAllActions() is reached
OrderMachine::test(guards: [IsEligibleGuard::class => true])
    ->fakingAllActions()  // too late — @always already ran StoreAction

// ✅ StoreAction spied before init
OrderMachine::test(
    guards: [IsEligibleGuard::class => true],
    faking: [StoreAction::class],
)
->fakingAllActions()  // safe — @always StoreAction was already spied
```

## withRunningChild() Does Nothing

**Symptom:** `withRunningChild()` is called but forward events don't work. No error thrown.

**Cause:** `withRunningChild()` creates a `MachineChild` record in the database. It requires persistence — silently fails with `withoutPersistence()` or `TestMachine::define()`.

**Fix:** Use `Machine::test()` without `withoutPersistence()`:

<!-- doctest-attr: ignore -->
```php
// ❌ No DB → withRunningChild is a no-op
OrderMachine::test()
    ->withoutPersistence()
    ->withRunningChild(PaymentMachine::class)  // silently fails

// ✅ Persistence enabled (default)
OrderMachine::test()
    ->withRunningChild(PaymentMachine::class)  // creates DB record
    ->assertForwardAvailable('PROVIDE_CARD')
```

## simulateChildDone() Throws "does not have a child delegation"

**Symptom:** `simulateChildDone()` throws `AssertionFailedError` even though the state has a `machine:` or `job:` key.

**Cause:** The machine is not at the delegating state. Common reasons:
- `@always` transitioned away before you called simulate
- Fire-and-forget job immediately transitioned to `target` state
- Wrong state name assumed

**Fix:** Check the actual state before simulating:

<!-- doctest-attr: ignore -->
```php
OrderMachine::test(...)
    ->assertState('processing_payment')  // verify you're at the right state
    ->simulateChildDone(PaymentMachine::class, result: [...]);
```

For job actors, pass the **job class** (not the machine class):

<!-- doctest-attr: ignore -->
```php
// ❌ Wrong: passing machine class for a job actor
->simulateChildDone(PaymentMachine::class)

// ✅ Correct: passing job class
->simulateChildDone(ProcessPaymentJob::class, result: [...])
```

## Context Not Available to Entry Actions

**Symptom:** Entry actions on the initial state see `null` values even though context was passed.

**Cause:** This happened with the old `Machine::test(['key' => 'val'])` which applied context *after* initialization. Since 8.5.0, `Machine::test(context: [...])` merges context before init.

**Fix:** Use the named `context:` parameter:

<!-- doctest-attr: ignore -->
```php
// ✅ Entry actions see orderId = 'ORD-1'
OrderMachine::test(context: ['orderId' => 'ORD-1'])
    ->assertContextHas('order_loaded');
```

## Timer Doesn't Fire After advanceTimers()

**Symptom:** `advanceTimers(Timer::hours(25))` does nothing — state doesn't change.

**Cause:** Timer state tracking (`inMemoryStateEnteredAt`) wasn't initialized. This happens when:
- `withoutPersistence()` wasn't called (timer tracking requires in-memory mode or explicit `processTimers()`)
- The machine was created without entering a state with timers

**Fix:** Ensure `withoutPersistence()` is called, or use `Machine::startingAt()` which initializes timer tracking:

<!-- doctest-attr: ignore -->
```php
OrderMachine::test(context: [...])
    ->withoutPersistence()
    ->send('START')
    ->assertState('awaiting_confirmation')
    ->advanceTimers(Timer::hours(25))
    ->assertState('expired');
```

## Tests Pass Locally But Fail in CI

**Symptom:** Tests pass when run individually but fail when the full suite runs in parallel.

**Cause:** Behavior fakes (`spy()`, `shouldReturn()`) use static state. In parallel test execution, one test's fakes can bleed into another test.

**Fix:** Add `afterEach` cleanup:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;

afterEach(function (): void {
    InvokableBehavior::resetAllFakes();
});
```

## Related

- [Testing Overview](/testing/overview) — testing philosophy and tool selection
- [TestMachine](/testing/test-machine) — fluent API reference
- [Fakeable Behaviors](/testing/fakeable-behaviors) — mocking and spying guide
