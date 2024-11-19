<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\OrderMachine;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;

test('it can get a calculator behavior', function (): void {
    // 1. Arrange
    $calculator = OrderMachine::getCalculator('calculateOrderTotal');
    $context    = new ContextManager(['items_count' => 5]);

    // 2. Act
    $calculator($context);

    // 3. Assert
    expect($calculator)->toBeCallable();
    expect($context->get('items_count'))->toBe(50);
});

test('it can get a guard behavior', function (): void {
    // 1. Arrange
    $guard          = OrderMachine::getGuard('validateOrder');
    $validContext   = new ContextManager(['items_count' => 5]);
    $invalidContext = new ContextManager(['items_count' => 0]);

    // 2. Act & 3. Assert
    expect($guard)->toBeCallable()
        ->and($guard($validContext))->toBeTrue()
        ->and($guard($invalidContext))->toBeFalse();
});

test('it can get an action behavior', function (): void {
    // 1. Arrange
    $action  = OrderMachine::getAction('createOrder');
    $context = new ContextManager();

    // 2. Act
    $action($context);

    // 3. Assert
    expect($action)->toBeCallable()
        ->and($context->get('order_created'))->toBeTrue();
});

test('it can get an event behavior', function (): void {
    // 1. Act
    $eventBehavior = OrderMachine::getEvent('orderCreated');

    // 3. Assert
    expect($eventBehavior)->toBeInstanceOf(EventBehavior::class)
        ->and($eventBehavior::getType())->toBe('ORDER_CREATED');
});
