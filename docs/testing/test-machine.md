# TestMachine

Fluent test wrapper inspired by `Livewire::test()`. Provides a chainable API for sending events and asserting state, context, transitions, and behavior execution.

## Construction

TestMachine can be created five ways depending on your testing needs. Use `Machine::test()` for existing machine classes, `TestMachine::create()` for direct construction, `withContext()` when initial entry actions depend on context, `define()` for inline throwaway definitions, and `for()` when you already have a Machine instance.

<!-- doctest-attr: ignore -->
```php
// From a Machine subclass (delegates to TestMachine::create())
TrafficLightsMachine::test()
TrafficLightsMachine::test(['count' => 42])

// Direct construction (same as test(), for explicit TestMachine reference)
TestMachine::create(TrafficLightsMachine::class)
TestMachine::create(TrafficLightsMachine::class, ['count' => 42])

// Pre-start context — entry actions see injected values
TrafficLightsMachine::withContext(['count' => 42])

// Inline definition (no Machine class, no persistence)
TestMachine::define(
    config: ['id' => 'counter', 'initial' => 'active', 'context' => ['count' => 0], 'states' => [
        'active' => ['on' => ['GO' => ['target' => 'done']]],
        'done'   => [],
    ]],
)

// Wrap existing instance
$machine = TrafficLightsMachine::create();
TestMachine::for($machine)
```

## Configuration

Chain configuration methods before sending events to customize machine behavior for the test.

<!-- doctest-attr: ignore -->
```php
->faking([SendEmailAction::class, ChargePaymentAction::class])  // class-based: spy mode
->faking(['broadcastAction'])                                      // inline: fake with no-op
->faking(['isValidGuard' => true])                                 // inline: fake with return value
->faking(['calcTax' => fn(ContextManager $ctx) => $ctx->set('tax', 0)])  // inline: custom replacement
->withoutPersistence()                                            // skip DB writes
->withoutParallelDispatch()                                       // run regions sequentially
->withScenario('rush_order')                                      // set scenario type
```

## Sending Events

<!-- doctest-attr: ignore -->
```php
->send('SUBMIT')                                              // string shorthand
->send(['type' => 'PAY', 'payload' => ['amount' => 100]])    // full event
->send(PaymentEvent::forTesting()->toArray())                 // from factory
->sendMany(['SUBMIT', 'PAY', 'SHIP'])                        // sequence
```

## State Assertions

<!-- doctest-attr: ignore -->
```php
->assertState('awaiting_payment')
->assertState('checkout.payment')          // hierarchical
->assertNotState('cancelled')
->assertFinished()                         // current state is type:final
->assertResult($expected)                  // final state result value
```

## Context Assertions

<!-- doctest-attr: ignore -->
```php
->assertContext('total', 100)
->assertContextHas('paid_at')
->assertContextMissing('error')
->assertContextMatches('amount', fn($v) => $v > 0 && $v < 10000)
->assertContextIncludes(['a' => 1, 'b' => 2])
```

## Transition Assertions

<!-- doctest-attr: ignore -->
```php
->assertTransition('NEXT', 'yellow')       // send + assertState in one
->assertGuarded('SHIP')                    // event blocked, state unchanged
->assertGuardedBy('SHIP', IsStockAvailableGuard::class)  // blocked by specific guard
->assertValidationFailed('PAY', 'amount')  // MachineValidationException thrown
```

## History Assertions

<!-- doctest-attr: ignore -->
```php
->assertHistoryContains('SUBMIT', 'PAY')
->assertHistoryOrder('SUBMIT', 'PAY', 'SHIP')  // events appear in this order
->assertTransitionedThrough(['idle', 'processing', 'done'])  // states visited (including @always)
```

## Path Assertions

<!-- doctest-attr: ignore -->
```php
->assertPath([
    ['event' => 'START',   'state' => 'active'],
    ['event' => 'PROCESS', 'state' => 'done', 'context' => ['completed' => true]],
])
```

## Parallel Assertions

<!-- doctest-attr: ignore -->
```php
->assertRegionState('payment', 'charged')
->assertAllRegionsCompleted()              // any parallel state's @done fired
->assertAllRegionsCompleted('processing')  // specific parallel state route
```

## Behavior Assertions

<!-- doctest-attr: ignore -->
```php
->assertBehaviorRan(SendEmailAction::class)        // class-based
->assertBehaviorRan('broadcastAction')              // inline
->assertBehaviorNotRan(RefundAction::class)
->assertBehaviorRanTimes(SendEmailAction::class, 2)
->assertBehaviorRanWith(SendEmailAction::class, fn($ctx) => $ctx->get('email') !== null)
->assertBehaviorRanWith('myAction', fn(array $params) => $params[0]->get('done'))  // inline: array param
```

## Available Events Assertions

Assert which events the machine's current state can accept. Useful for verifying the machine exposes the correct API at each step — especially with forward endpoints.

<!-- doctest-attr: ignore -->
```php
// Assert a specific event is available
->assertAvailableEvent('SUBMIT_ORDER')

// Assert an event is NOT available (e.g., after transitioning away)
->assertNotAvailableEvent('SUBMIT_ORDER')

// Assert exact set of available event types
->assertAvailableEvents(['SUBMIT_ORDER', 'CANCEL'])

// Assert a forwarded event is available (source: 'forward')
->assertForwardAvailable('PROVIDE_CARD')

// Assert no events available (final state)
->assertNoAvailableEvents()
```

| Method | Description |
|--------|-------------|
| `assertAvailableEvent(string $eventType)` | Event type is available in current state |
| `assertNotAvailableEvent(string $eventType)` | Event type is NOT available |
| `assertAvailableEvents(array $expectedTypes)` | Exact set of available events matches |
| `assertForwardAvailable(string $eventType)` | Forwarded event is available (`source: 'forward'`) |
| `assertNoAvailableEvents()` | No events available — current state is final or has no transitions |

::: tip Parent vs Forward Events
`assertAvailableEvent()` matches events from any source. `assertForwardAvailable()` specifically matches events with `source: 'forward'` — those auto-generated from the `forward` config on delegation states. See [Available Events](/laravel-integration/available-events) for details.
:::

## Timer Testing

Methods for testing time-based events (`after`/`every` on transitions).

<!-- doctest-attr: ignore -->
```php
// Advance time and run timer sweep
->advanceTimers(Timer::days(7))

// Run sweep without advancing time
->processTimers()

// Assert timer exists on current state
->assertHasTimer('ORDER_EXPIRED')

// Assert timer fired / not fired
->assertTimerFired('ORDER_EXPIRED')
->assertTimerNotFired('ORDER_EXPIRED')
```

See [Time-Based Testing](/testing/time-based-testing) for complete examples.

## Accessors

When you need direct access to the underlying machine, state, or context — for example, to perform custom assertions not covered by the built-in methods.

<!-- doctest-attr: ignore -->
```php
->machine()     // underlying Machine instance
->state()       // current State object
->context()     // ContextManager instance
```

## Utilities

Helper methods for debugging and mid-chain side effects.

<!-- doctest-attr: ignore -->
```php
// Execute a callback mid-chain for side-effect assertions
->tap(fn($test) => Notification::assertSentTo($user, ApprovalNotification::class))

// Debug guard evaluation results (WARNING: sends the event, mutates state)
->debugGuards('SUBMIT')  // returns ['IsValidGuard' => true, 'HasStockGuard' => false]
```

## Cleanup

Call `resetFakes()` when you need to clear fakes mid-test. In most cases, the global `afterEach` hook handles this automatically.

<!-- doctest-attr: ignore -->
```php
->resetFakes()  // cleanup faked behaviors registered via faking()
```

## Complete Example

<!-- doctest-attr: ignore -->
```php
TrafficLightsMachine::test()
    ->assertState('active')
    ->assertContext('count', 0)
    ->send('INCREASE')
    ->send('INCREASE')
    ->assertContext('count', 2)
    ->assertHistoryContains('INCREASE')
    ->assertPath([
        // assertPath sends each event, then checks state + context
        ['event' => 'INCREASE', 'state' => 'active', 'context' => ['count' => 3]],
    ]);
```

## Direct State Access

Use direct state access when you need raw values for complex assertions, or when integrating with external assertion libraries that don't work with TestMachine's fluent API.

For advanced cases where TestMachine doesn't fit, you can access the underlying state directly:

<!-- doctest-attr: ignore -->
```php
// Direct state matching
expect($machine->state->matches('processing'))->toBeTrue();
expect($machine->state->value)->toBe(['order.processing']);

// Context access
expect($machine->state->context->get('total'))->toBe(100);
expect($machine->state->context->has('paid_at'))->toBeTrue();

// History inspection
expect($machine->state->history->pluck('type'))->toContain('SUBMIT');
```

## Timer Helpers

For testing time-based events (`after`/`every` on transitions):

<!-- doctest-attr: ignore -->
```php
// Advance time and trigger timer sweep inline
$machine->test()
    ->advanceTimers(Timer::days(8))
    ->assertState('expired');

// Run timer sweep without advancing time
$machine->test()
    ->processTimers()
    ->assertState('active');  // no timers due yet

// Assert timer exists / fired / not fired
$machine->test()
    ->assertHasTimer('ORDER_EXPIRED')
    ->advanceTimers(Timer::days(8))
    ->assertTimerFired('ORDER_EXPIRED');

$machine->test()
    ->assertTimerNotFired('ORDER_EXPIRED');
```

See [Time-Based Testing](/testing/time-based-testing) for full details.

## Schedule Helpers

For testing scheduled events (`schedules` key on definition):

<!-- doctest-attr: ignore -->
```php
// Send scheduled event inline (bypasses queue)
$machine->test()
    ->runSchedule('CHECK_EXPIRY')
    ->assertState('expired');

// Assert schedule exists
$machine->test()
    ->assertHasSchedule('CHECK_EXPIRY')
    ->assertHasSchedule('DAILY_REPORT');
```

See [Scheduled Testing](/testing/scheduled-testing) for full details.

::: tip Related
See [Isolated Testing](/testing/isolated-testing) for unit-level `runWithState()`,
[Fakeable Behaviors](/testing/fakeable-behaviors) for the faking API,
[Transitions & Paths](/testing/transitions-and-paths) for guard and path testing,
[Parallel Testing](/testing/parallel-testing) for parallel state testing,
[Persistence Testing](/testing/persistence-testing) for DB-level testing,
[Time-Based Testing](/testing/time-based-testing) for timer testing,
and [Scheduled Testing](/testing/scheduled-testing) for schedule testing.
:::
