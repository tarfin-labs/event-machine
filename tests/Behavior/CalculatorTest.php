<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\MinimumGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\ApplyDiscountAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Calculators\PriceCalculator;
use Tarfinlabs\EventMachine\Tests\Stubs\Calculators\TotalCalculator;
use Tarfinlabs\EventMachine\Tests\Stubs\Calculators\AverageCalculator;

test('calculator can set context values that guards and actions can use', function (): void {
    // 1. Arrange
    $machine = Machine::create([
        'config' => [
            'initial' => 'start',
            'context' => [
                'quantity'  => 10,
                'unitPrice' => 15,
            ],
            'states' => [
                'start' => [
                    'on' => [
                        'PROCESS' => [
                            'target'      => 'processed',
                            'calculators' => PriceCalculator::class,
                            'guards'      => MinimumGuard::class,
                            'actions'     => ApplyDiscountAction::class,
                        ],
                    ],
                ],
                'processed' => [],
            ],
        ],
    ]);

    // 2. Act
    $state = $machine->send(['type' => 'PROCESS']);

    // 3. Assert
    expect($state->context->get('totalPrice'))->toBe(150);
    expect($state->context->get('finalPrice'))->toBe(135);
    expect($state->matches('processed'))->toBeTrue();
});

test('inline calculator functions work correctly', function (): void {
    // 1. Arrange
    $machine = Machine::create([
        'config' => [
            'initial' => 'start',
            'context' => [
                'numbers' => [1, 2, 3, 4, 5],
            ],
            'states' => [
                'start' => [
                    'on' => [
                        'CALCULATE' => [
                            'target'      => 'calculated',
                            'calculators' => 'calculateSumCalculator',
                        ],
                    ],
                ],
                'calculated' => [],
            ],
        ],
        'behavior' => [
            'calculators' => [
                'calculateSumCalculator' => function (ContextManager $context): void {
                    $numbers = $context->get('numbers');
                    $context->set('sum', array_sum($numbers));
                },
            ],
        ],
    ]);

    // 2. Act
    $state = $machine->send(['type' => 'CALCULATE']);

    // 3. Assert
    expect($state->context->get('sum'))->toBe(15);
});

test('multiple calculators can be chained', function (): void {
    // 1. Arrange
    $machine = Machine::create([
        'config' => [
            'initial' => 'start',
            'context' => [
                'values' => [10, 20, 30, 40, 50],
            ],
            'states' => [
                'start' => [
                    'on' => [
                        'ANALYZE' => [
                            'target'      => 'analyzed',
                            'calculators' => [
                                TotalCalculator::class,
                                AverageCalculator::class,
                            ],
                        ],
                    ],
                ],
                'analyzed' => [],
            ],
        ],
    ]);

    // 2. Act
    $state = $machine->send(['type' => 'ANALYZE']);

    // 3. Assert
    expect($state->context->get('total'))->toBe(150);
    expect($state->context->get('average'))->toBe(30);
});

test('calculators run before guards and actions', function (): void {
    // 1. Arrange
    $executionOrder = [];

    $machine = Machine::create([
        'config' => [
            'initial' => 'start',
            'context' => ['value' => 10],
            'states'  => [
                'start' => [
                    'on' => [
                        'CHECK' => [
                            'target'      => 'end',
                            'calculators' => 'calculateStuffCalculator',
                            'guards'      => 'checkStuffGuard',
                            'actions'     => 'doStuffAction',
                        ],
                    ],
                ],
                'end' => [],
            ],
        ],
        'behavior' => [
            'calculators' => [
                'calculateStuffCalculator' => function () use (&$executionOrder) {
                    return $executionOrder[] = 'calculator';
                },
            ],
            'guards' => [
                'checkStuffGuard' => function () use (&$executionOrder) {
                    $executionOrder[] = 'guard';

                    return true;
                },
            ],
            'actions' => [
                'doStuffAction' => function () use (&$executionOrder): void {
                    $executionOrder[] = 'action';
                },
            ],
        ],
    ]);

    // 2. Act
    $state = $machine->send(['type' => 'CHECK']);

    // 3. Assert
    expect($executionOrder)->toBe(['calculator', 'guard', 'action']);
});

test('calculator failures prevent guard and action execution', function (): void {
    // 1. Arrange
    $actionExecuted = false;
    $guardExecuted  = false;

    $machine = Machine::create([
        'config' => [
            'initial' => 'start',
            'context' => ['value' => 10],
            'states'  => [
                'start' => [
                    'on' => [
                        'PROCESS' => [
                            'target'      => 'processed',
                            'calculators' => 'problematicCalculationCalculator',
                            'guards'      => 'checkValueGuard',
                            'actions'     => 'recordValueAction',
                        ],
                    ],
                ],
                'processed' => [],
            ],
        ],
        'behavior' => [
            'calculators' => [
                'problematicCalculationCalculator' => function (): void {
                    throw new RuntimeException();
                },
            ],
            'guards' => [
                'checkValueGuard' => function () use (&$guardExecuted) {
                    $guardExecuted = true;

                    return true;
                },
            ],
            'actions' => [
                'recordValueAction' => function () use (&$actionExecuted): void {
                    $actionExecuted = true;
                },
            ],
        ],
    ]);

    // 2. Act
    $state = $machine->send(['type' => 'PROCESS']);

    // 3. Assert
    // The calculator failed, so transition should not happen
    expect($state->matches('start'))->toBeTrue();

    // Guard and action should not have executed
    expect($guardExecuted)->toBeFalse();
    expect($actionExecuted)->toBeFalse();

    // Should have recorded a calculator fail event
    expect($state->history->pluck('type')->contains('machine.calculator.problematicCalculationCalculator.fail'))->toBeTrue();
});

test('calculators can use event data', function (): void {
    // 1. Arrange
    $machine = Machine::create([
        'config' => [
            'initial' => 'start',
            'context' => [],
            'states'  => [
                'start' => [
                    'on' => [
                        'MULTIPLY' => [
                            'target'      => 'end',
                            'calculators' => 'multiplyNumbersCalculator',
                        ],
                    ],
                ],
                'end' => [],
            ],
        ],
        'behavior' => [
            'calculators' => [
                'multiplyNumbersCalculator' => function (ContextManager $context, EventBehavior $event): void {
                    $numbers = $event->payload['numbers'] ?? [];
                    $context->set('total', array_product($numbers));
                },
            ],
        ],
    ]);

    // 2. Act
    $state = $machine->send([
        'type'    => 'MULTIPLY',
        'payload' => [
            'numbers' => [2, 3, 4],
        ],
    ]);

    // 3. Assert
    expect($state->context->get('total'))->toBe(24);
});

test('calculator can have parameters', function (): void {
    // 1. Arrange
    $machine = Machine::create([
        'config' => [
            'initial' => 'start',
            'context' => [
                'value' => 100,
            ],
            'states' => [
                'start' => [
                    'on' => [
                        'APPLY_TAX' => [
                            'target'      => 'taxed',
                            'calculators' => 'calculateTaxCalculator:0.2', // 20% tax rate
                        ],
                    ],
                ],
                'taxed' => [],
            ],
        ],
        'behavior' => [
            'calculators' => [
                'calculateTaxCalculator' => function (
                    ContextManager $context,
                    EventBehavior $event,
                    ?array $args = null
                ): void {
                    $taxRate = (float) $args[0];
                    $value   = $context->get('value');
                    $context->set('tax', $value * $taxRate);
                },
            ],
        ],
    ]);

    // 2. Act
    $state = $machine->send(['type' => 'APPLY_TAX']);

    // 3. Assert
    expect($state->context->get('tax'))->toBe(20.0);
});
