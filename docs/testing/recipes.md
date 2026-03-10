# Testing Recipes

Common real-world testing patterns combining isolated, faked, and machine-level techniques.

## Recipe: External API Action

<!-- doctest-attr: ignore -->
```php
// Strategy 1: Mock the service (test real action logic)
it('handles API failure gracefully', function () {
    $this->mock(PaymentGateway::class)
        ->shouldReceive('charge')
        ->andThrow(new ApiTimeoutException());

    $state = State::forTesting(['amount' => 100]);
    ProcessPaymentAction::runWithState($state);

    expect($state->context->get('api_error'))->toBe('Payment API timeout');
});

// Strategy 2: Fake the action (test machine flow)
it('continues flow after payment', function () {
    ProcessPaymentAction::shouldRun()
        ->andReturnUsing(fn($ctx) => $ctx->set('paid', true));

    OrderMachine::test()->send('PAY')->assertState('preparing');
});
```

## Recipe: Guard Chain (Multiple Guards)

<!-- doctest-attr: ignore -->
```php
it('requires all guards to pass', function () {
    HasItemsGuard::shouldReturn(true);
    IsValidAmountGuard::shouldReturn(true);
    HasPermissionGuard::shouldReturn(false);  // third guard fails

    OrderMachine::test()->assertGuarded('SUBMIT');
});

it('first failing guard blocks', function () {
    HasItemsGuard::shouldReturn(false);  // first guard fails
    // remaining guards never evaluated

    OrderMachine::test()->assertGuarded('SUBMIT');
});
```

## Recipe: Calculator Ordering

<!-- doctest-attr: ignore -->
```php
it('calculators run in declared order', function () {
    // subtotal → discount → tax → total
    $state = State::forTesting([
        'items' => [['price' => 100, 'qty' => 2]],
        'discount_rate' => 0.10,
        'tax_rate' => 0.18,
    ]);

    CalculateSubtotalCalculator::runWithState($state);
    expect($state->context->get('subtotal'))->toBe(200);

    ApplyDiscountCalculator::runWithState($state);
    expect($state->context->get('discounted'))->toBe(180);

    CalculateTaxCalculator::runWithState($state);
    expect($state->context->get('total'))->toBe(212.40);
});
```

## Recipe: Raised Event Flow

<!-- doctest-attr: ignore -->
```php
it('action raises event that triggers further transition', function () {
    OrderMachine::test(['items' => [['id' => 1]]])
        ->send('VALIDATE')
        ->assertState('validated')
        ->assertHistoryContains('VALIDATION_PASSED');
});

it('raised event failure path', function () {
    OrderMachine::test(['items' => []])
        ->send('VALIDATE')
        ->assertState('validation_failed')
        ->assertHistoryContains('VALIDATION_FAILED');
});
```

## Recipe: Multi-Step Lifecycle with Selective Faking

<!-- doctest-attr: ignore -->
```php
it('completes full order lifecycle', function () {
    // Fake notifications but run everything else real
    OrderMachine::test(['order_id' => 1])
        ->faking([SendEmailAction::class, SendSmsAction::class])
        ->assertPath([
            ['event' => 'SUBMIT',  'state' => 'awaiting_payment'],
            ['event' => 'PAY',     'state' => 'preparing',       'context' => ['paid' => true]],
            ['event' => 'SHIP',    'state' => 'shipped'],
            ['event' => 'DELIVER', 'state' => 'delivered'],
        ])
        ->assertBehaviorRan(SendEmailAction::class)
        ->assertBehaviorRan(SendSmsAction::class);
});
```

## Recipe: Testing State Restoration

<!-- doctest-attr: ignore -->
```php
it('machine continues correctly after restore', function () {
    $machine = OrderMachine::create();
    $machine->send(['type' => 'SUBMIT']);

    $rootId = $machine->state->history->first()->root_event_id;

    // Restore from DB and continue
    $restored = OrderMachine::create(state: $rootId);
    expect($restored->state->matches('awaiting_payment'))->toBeTrue();

    $restored->send(['type' => 'PAY']);
    expect($restored->state->matches('preparing'))->toBeTrue();
});
```

::: tip Related
See [Overview](/testing/overview) for the testing pyramid,
[Isolated Testing](/testing/isolated-testing) for `runWithState()`,
[Fakeable Behaviors](/testing/fakeable-behaviors) for the faking API,
[Constructor DI](/testing/constructor-di) for service mocking,
[Transitions & Paths](/testing/transitions-and-paths) for guard and path testing,
[TestMachine](/testing/test-machine) for the fluent wrapper,
and [Persistence Testing](/testing/persistence-testing) for DB-level testing.
:::
