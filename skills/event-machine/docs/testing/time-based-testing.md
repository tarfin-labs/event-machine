# Testing Time-Based Events

Test timer transitions using TestMachine's fluent API. `advanceTimers()` simulates time passing — no need to interact with internal tables or artisan commands.

## Testing `after` Timers

<!-- doctest-attr: no_run -->
```php
OrderMachine::test()
    ->assertState('awaiting_payment')
    ->advanceTimers(Timer::days(8))     // 8 days > 7 day deadline
    ->assertState('cancelled')
    ->assertTimerFired('ORDER_EXPIRED');
```

Timer not yet past deadline:

<!-- doctest-attr: no_run -->
```php
OrderMachine::test()
    ->assertState('awaiting_payment')
    ->advanceTimers(Timer::days(3))     // 3 days < 7 day deadline
    ->assertState('awaiting_payment')   // still waiting
    ->assertTimerNotFired('ORDER_EXPIRED');
```

## Testing `every` Timers

<!-- doctest-attr: no_run -->
```php
SubscriptionMachine::test()
    ->assertState('active')
    ->advanceTimers(Timer::days(31))    // past 30-day interval
    ->assertState('active')             // stays in state
    ->assertContext('billingCount', 1) // action ran
    ->advanceTimers(Timer::days(31))    // another cycle
    ->assertContext('billingCount', 2);
```

## Testing `every` with max/then

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Support\Timer;

RetryMachine::test()
    ->assertState('retrying')
    ->advanceTimers(Timer::hours(7))    // retry 1
    ->assertContext('retryCount', 1)
    ->advanceTimers(Timer::hours(7))    // retry 2
    ->assertContext('retryCount', 2)
    ->advanceTimers(Timer::hours(7))    // retry 3 (max)
    ->assertContext('retryCount', 3)
    ->advanceTimers(Timer::hours(7))    // past max → MAX_RETRIES sent
    ->assertState('failed')
    ->assertFinished();
```

## Testing Implicit Cancel

When the machine leaves a state, its timers are implicitly cancelled:

<!-- doctest-attr: no_run -->
```php
OrderMachine::test()
    ->assertState('awaiting_payment')
    ->send('PAY')                       // leave the state
    ->assertState('processing')
    ->advanceTimers(Timer::days(8))     // timer would have fired, but...
    ->assertState('processing');         // no effect — timer cancelled
```

## Timer Assertions

<!-- doctest-attr: no_run -->
```php
OrderMachine::test()
    ->assertState('awaiting_payment')

    // Assert that a timer exists on the current state
    ->assertHasTimer('ORDER_EXPIRED')

    // Assert timer has NOT fired yet
    ->assertTimerNotFired('ORDER_EXPIRED')

    // Advance time past deadline
    ->advanceTimers(Timer::days(8))

    // Assert timer HAS fired
    ->assertTimerFired('ORDER_EXPIRED');
```

## Full Lifecycle Example

<!-- doctest-attr: no_run -->
```php
OrderMachine::test(['orderId' => 'ORD-123'])
    ->assertState('awaiting_payment')
    ->assertHasTimer('ORDER_EXPIRED')
    ->assertHasTimer('PAYMENT_REMINDER')

    // Day 1: reminder fires
    ->advanceTimers(Timer::days(1))
    ->assertState('awaiting_payment')
    ->assertBehaviorRan('sendReminderAction')

    // Day 7: order expired
    ->advanceTimers(Timer::days(7))
    ->assertState('cancelled')
    ->assertTimerFired('ORDER_EXPIRED')
    ->assertFinished();
```

## Testing Timer Events Manually

Timer events are regular events — you can send them directly without the sweep:

<!-- doctest-attr: no_run -->
```php
OrderMachine::test()
    ->send('ORDER_EXPIRED')              // manual send, no advanceTimers needed
    ->assertState('cancelled');
```

## Advanced: Using processTimers()

For fine-grained control, use `processTimers()` (runs sweep without advancing time):

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Models\MachineCurrentState;

// Manually backdate and sweep
$test = OrderMachine::test();
$test->machine()->persist();

$rootEventId = $test->machine()->state->history->first()->root_event_id;

MachineCurrentState::forInstance($rootEventId)
    ->update(['state_entered_at' => now()->subDays(8)]);

$test->processTimers()
    ->assertState('cancelled');
```

## Timer Testing Without Persistence

`advanceTimers()` works without database persistence — use it with `Machine::test()`, `Machine::startingAt()`, `TestMachine::define()`, or `withoutPersistence()`. Timer state is tracked in-memory automatically.

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\Testing\TestMachine;

TestMachine::define([
    'id'      => 'pin_flow',
    'initial' => 'waiting',
    'states'  => [
        'waiting' => [
            'on' => [
                'PIN_EXPIRED' => [
                    'target' => 'expired',
                    'after'  => Timer::seconds(120),
                ],
            ],
        ],
        'expired' => ['type' => 'final'],
    ],
])
->assertHasTimer('PIN_EXPIRED', Timer::seconds(120))  // verify duration
->advanceTimers(Timer::seconds(60))                     // 60s < 120s
->assertState('waiting')                                // not triggered yet
->advanceTimers(Timer::seconds(61))                     // cumulative 121s > 120s
->assertState('expired')                                // triggered
->assertTimerFired('PIN_EXPIRED');
```

In-memory mode supports:
- `@after` timers with dedup (fire only once)
- `@every` timers with `max` and `then`
- Timer fire history survives state transitions (for `assertTimerFired`)
- Guard-blocked transitions (fire recorded, state unchanged)
- Cumulative `advanceTimers()` calls

::: info Automatic detection
`advanceTimers()`, `assertTimerFired()`, and `assertTimerNotFired()` auto-detect whether the machine has persistence. When persistence is off, they use the in-memory path. When persistence is on, they use the database path. No code changes needed.
:::

## Timer Testing Methods Reference

| Method | Description |
|--------|-------------|
| `advanceTimers(Timer $duration)` | Advance time by duration and run timer sweep (works with and without persistence) |
| `processTimers()` | Run timer sweep without advancing time (persistence only) |
| `assertHasTimer(string $event, ?Timer $duration)` | Assert current state has a timer for this event, optionally verify duration |
| `assertTimerFired(string $event)` | Assert timer event was fired (auto-detects persistence mode) |
| `assertTimerNotFired(string $event)` | Assert timer event was NOT fired (auto-detects persistence mode) |
