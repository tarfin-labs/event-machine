# Testing Transitions & Paths

Test state transitions, guard behavior, and complete lifecycle paths using `Machine::test()`.

## Single Transitions

<!-- doctest-attr: ignore -->
```php
AllInvocationPointsMachine::test()
    ->assertTransition('PROCESS', 'active');
```

## Guard Testing

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

<!-- doctest-attr: ignore -->
```php
OrderMachine::test()
    ->assertValidationFailed(
        ['type' => 'PAY', 'payload' => ['amount' => -1]],
        'amount',  // expected error key
    );
```

## Path Testing — Full Lifecycle

<!-- doctest-attr: ignore -->
```php
TrafficLightsMachine::test()
    ->assertPath([
        ['event' => 'INCREASE', 'state' => 'active', 'context' => ['count' => 1]],
        ['event' => 'INCREASE', 'state' => 'active', 'context' => ['count' => 2]],
    ]);
```

## Hierarchical State Transitions

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

## Raised Events

Actions can raise internal events that trigger further transitions:

<!-- doctest-attr: ignore -->
```php
OrderMachine::test()
    ->send('PROCESS')
    ->assertState('completed')
    ->assertHistoryContains('PROCESSING_COMPLETE');
```

::: tip Related
See [TestMachine](/testing/test-machine) for the complete assertion API,
[Isolated Testing](/testing/isolated-testing) for unit-level guard testing,
[Fakeable Behaviors](/testing/fakeable-behaviors) for guard faking,
and [Recipes](/testing/recipes) for common real-world patterns.
:::
