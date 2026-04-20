# Testing Cheat-Sheet (curated)

Synthesis of `docs/testing/*`, optimized for agent lookup. Always use `InteractsWithMachines` trait on the test case — auto-resets all fakes.

## Entry points

```php
OrderMachine::test($context = []);           // fluent TestMachine
OrderMachine::startingAt('nested.state');    // skip setup, jump to state
State::forTesting($context);                 // build state for unit tests
MyBehavior::runWithState($state, $event);    // invoke behavior directly
```

## Fluent chain — canonical example

```php
OrderMachine::test(['total' => 100])
    ->withoutPersistence()                  // no DB writes
    ->faking([SendEmailAction::class])      // spy this action
    ->send('SUBMIT')
    ->assertState('awaiting_payment')
    ->send('PAY')
    ->assertState('preparing')
    ->assertBehaviorRan(SendEmailAction::class)
    ->send('DELIVER')
    ->assertState('delivered')
    ->assertFinished();
```

## Assertions (complete)

### State / transition
- `assertState($name)` — current state value exactly matches
- `assertInState($name)` — state contains value (works across parallel regions)
- `assertNotInState($name)`
- `assertFinished()` — reached a `type: final` state
- `assertTransitioned($from, $to)` — a specific transition happened
- `assertNotTransitioned($from, $to)`

### Context
- `assertContext($key, $value)`
- `assertContextEquals([...])` — whole context matches
- `assertContextHas($key)` / `assertContextMissing($key)`

### Behaviors (Actions / Guards / Calculators)
- `assertBehaviorRan(Class::class)` / `assertBehaviorNotRan(Class::class)`
- `assertGuarded($event)` — transition was blocked by a guard
- `assertGuardedBy($event, GuardClass::class)`
- `assertNotGuarded($event)`

### Raised / sent
- `assertRaised(ActionClass::class)` / `ActionClass::assertRaised($event)` (isolated, per-class)
- `ActionClass::assertRaisedCount(N)`
- `ActionClass::assertNotRaised($event)`
- `ActionClass::assertNothingRaised()`
- `CommunicationRecorder::assertSentTo(TargetMachine::class, event: ...)`
- `CommunicationRecorder::assertRaised(...)`

### Timers / schedules
- `assertHasTimer($eventType)`
- `assertHasNoTimer($eventType)`
- `advanceTimers(Timer::days(7))` — virtual time advance, fires due timers

### Delegation
- `MyChildMachine::assertInvoked()`
- `MyChildMachine::assertInvokedWith([$input])`
- `MyChildMachine::assertNotInvoked()`

### Path coverage
- `Machine::assertAllPathsCovered()`
- `Machine::assertPathCoverage(minimum: 0.8)`
- Add `TracksPathCoverage` trait to TestCase

## Faking patterns

### Fake individual behaviors
```php
OrderMachine::test()
    ->faking([SendEmailAction::class, IsPaymentValidGuard::class])
    ->send('PAY');
```

### Fake ALL (with exceptions)
```php
OrderMachine::test()
    ->fakingAllActions(except: [CriticalAction::class])
    ->fakingAllGuards(except: [])
    ->fakingAllBehaviors(except: [MustRunCalculator::class]);
```

### Fake child machines (delegation)
```php
PaymentMachine::fake(
    output: new PaymentOutput(paymentId: 'pay_123'),
    finalState: 'settled',
);

OrderMachine::test()->send('PROCESS')->assertState('shipping');
PaymentMachine::assertInvokedWith(['orderId' => 'ORD-1']);
```

### Simulate child outcomes without running child
```php
OrderMachine::test()
    ->send('PROCESS')
    ->simulateChildDone(PaymentMachine::class, output: [...])
    ->assertState('shipping');

// Also: ->simulateChildFail(...)   ->simulateChildTimeout(...)
```

## Four-layer strategy

**1. Unit — one behavior, no machine:**
```php
$state = State::forTesting(['retries' => 2]);
expect(IsRetryAllowedGuard::runWithState($state))->toBeTrue();
```

**2. Integration — flow in memory, no DB:**
```php
OrderMachine::test()->withoutPersistence()->send('SUBMIT')->assertState('processing');
```

**3. E2E — real in-memory SQLite, real restore round-trip:**
```php
// Package test case uses RefreshDatabase + in-memory SQLite
$m = OrderMachine::create();
$m->send('SUBMIT');
$id = $m->state->history->first()->root_event_id;
$restored = OrderMachine::create(state: $id);
expect($restored->state->matches('processing'))->toBeTrue();
```

**4. LocalQA — real MySQL + Redis + Horizon** (see `references/qa-setup.md`)

## Test stub catalogue (`tests/Stubs/`)

Canonical real-world patterns. Consult before inventing:

- `TrafficLights` — minimal FSM
- `Calculator` — calculators + context mutation
- `Elevator` — hierarchical states + history
- `ChildDelegation` — sync/async child machines
- `Parallel` — parallel regions
- `Endpoint` — HTTP endpoint routing
- `JobActors` — `job` key delegation
- `ListenerMachines` — `listen` lifecycle hooks
- `AlwaysEventPreservation` — `@always` chains preserving triggering event

## Avoid

- `vendor/bin/pest` directly — slow + incomplete. Use `composer test` (parallel + all gates).
- `Bus::fake()` / `Queue::fake()` / in-memory SQLite in LocalQA — invalidates the whole purpose.
- Sleep-based waits — use `LocalQATestCase::waitFor()` instead.
- Asserting `MachineCurrentState` rows in parallel dispatch — restore the machine instead.
