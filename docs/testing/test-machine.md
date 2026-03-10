# TestMachine

Fluent test wrapper inspired by `Livewire::test()`. Provides a chainable API for sending events and asserting state, context, transitions, and behavior execution.

## Construction

<!-- doctest-attr: ignore -->
```php
// From a Machine subclass
TrafficLightsMachine::test()
TrafficLightsMachine::test(['count' => 42])

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

<!-- doctest-attr: ignore -->
```php
->faking([SendEmailAction::class, ChargePaymentAction::class])  // selective behavior faking
->withoutPersistence()                                            // skip DB writes
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
```

## Context Assertions

<!-- doctest-attr: ignore -->
```php
->assertContext('total', 100)
->assertContextHas('paid_at')
->assertContextMissing('error')
->assertContextIncludes(['a' => 1, 'b' => 2])
```

## Transition Assertions

<!-- doctest-attr: ignore -->
```php
->assertTransition('NEXT', 'yellow')       // send + assertState in one
->assertGuarded('SHIP')                    // event blocked, state unchanged
->assertValidationFailed('PAY', 'amount')  // MachineValidationException thrown
```

## History Assertions

<!-- doctest-attr: ignore -->
```php
->assertHistoryContains('SUBMIT', 'PAY')
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
```

## Behavior Assertions

<!-- doctest-attr: ignore -->
```php
->assertBehaviorRan(SendEmailAction::class)
->assertBehaviorNotRan(RefundAction::class)
```

## Accessors

<!-- doctest-attr: ignore -->
```php
->machine()     // underlying Machine instance
->state()       // current State object
->context()     // ContextManager instance
```

## Cleanup

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
        ['event' => 'INCREASE', 'state' => 'active', 'context' => ['count' => 3]],
    ]);
```

::: tip Related
See [Isolated Testing](/testing/isolated-testing) for unit-level `runWithState()`,
[Fakeable Behaviors](/testing/fakeable-behaviors) for the faking API,
[Transitions & Paths](/testing/transitions-and-paths) for guard and path testing,
[Parallel Testing](/testing/parallel-testing) for parallel state testing,
and [Persistence Testing](/testing/persistence-testing) for DB-level testing.
:::
