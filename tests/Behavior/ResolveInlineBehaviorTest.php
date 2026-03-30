<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\OrderMachine;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;

test('it can get a calculator behavior', function (): void {
    // 1. Arrange
    $calculator = OrderMachine::getCalculator('calculateOrderTotalCalculator');
    $context    = new ContextManager(['itemsCount' => 5]);

    // 2. Act
    $calculator($context);

    // 3. Assert
    expect($calculator)->toBeCallable();
    expect($context->get('itemsCount'))->toBe(50);
});

test('it can get a guard behavior', function (): void {
    // 1. Arrange
    $guard          = OrderMachine::getGuard('validateOrderGuard');
    $validContext   = new ContextManager(['itemsCount' => 5]);
    $invalidContext = new ContextManager(['itemsCount' => 0]);

    // 2. Act & 3. Assert
    expect($guard)->toBeCallable()
        ->and($guard($validContext))->toBeTrue()
        ->and($guard($invalidContext))->toBeFalse();
});

test('it can get an action behavior', function (): void {
    // 1. Arrange
    $action  = OrderMachine::getAction('createOrderAction');
    $context = new ContextManager();

    // 2. Act
    $action($context);

    // 3. Assert
    expect($action)->toBeCallable()
        ->and($context->get('orderCreated'))->toBeTrue();
});

test('it can get an event behavior', function (): void {
    // 1. Act
    $eventBehavior = OrderMachine::getEvent('orderCreatedEvent');

    // 3. Assert
    expect($eventBehavior)->toBeInstanceOf(EventBehavior::class)
        ->and($eventBehavior::getType())->toBe('ORDER_CREATED');
});

test('it throws exception when behavior not found', function (): void {
    expect(fn () => OrderMachine::getCalculator('nonExistentCalculator'))
        ->toThrow(BehaviorNotFoundException::class, 'Behavior of type `calculators.nonExistentCalculator` not found.');

    expect(fn () => OrderMachine::getGuard('nonExistentGuard'))
        ->toThrow(BehaviorNotFoundException::class, 'Behavior of type `guards.nonExistentGuard` not found.');

    expect(fn () => OrderMachine::getAction('nonExistentAction'))
        ->toThrow(BehaviorNotFoundException::class, 'Behavior of type `actions.nonExistentAction` not found.');

    expect(fn () => OrderMachine::getEvent('nonExistentEvent'))
        ->toThrow(BehaviorNotFoundException::class, 'Behavior of type `events.nonExistentEvent` not found.');
});

test('it throws exception when behavior type not found', function (): void {
    // Act & Assert
    expect(fn () => OrderMachine::getBehavior('invalidType.someBehavior'))
        ->toThrow(BehaviorNotFoundException::class, 'Behavior of type `invalidType.someBehavior` not found.');
});

test('it can resolve inline key with named params from tuple', function (): void {
    // 1. Arrange — define machine with inline guard referenced via tuple
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'idle',
            'context' => ['amount' => 500],
            'states'  => [
                'idle' => [
                    'on' => [
                        'CHECK' => [
                            'target' => 'passed',
                            'guards' => [['isInRangeGuard', 'min' => 100, 'max' => 1000]],
                        ],
                    ],
                ],
                'passed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'isInRangeGuard' => fn (ContextManager $ctx, int $min, int $max): bool => $ctx->get('amount') >= $min && $ctx->get('amount') <= $max,
            ],
        ],
    );

    // 2. Act
    $state = $machine->transition(event: ['type' => 'CHECK']);

    // 3. Assert — guard passed with named params, transition occurred
    expect($state->matches('passed'))->toBeTrue();
});
