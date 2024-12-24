<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;

test('when calculator and guarded transition succeed, action is executed', function (): void {
    // 1. Arrange
    $calculatorExecuted = false;
    $guardExecuted      = false;
    $actionExecuted     = false;

    $machine = Machine::create([
        'config' => [
            'initial' => 'state_a',
            'context' => [
                'value' => 10,
            ],
            'states' => [
                'state_a' => [
                    'on' => [
                        'CHECK' => [
                            [
                                'target'      => 'state_b',
                                'calculators' => 'successCalculator',
                                'guards'      => 'successGuard',
                                'actions'     => 'successAction',
                            ],
                            [
                                'target' => 'state_c',
                            ],
                        ],
                    ],
                ],
                'state_b' => [],
                'state_c' => [],
            ],
        ],
        'behavior' => [
            'calculators' => [
                'successCalculator' => function () use (&$calculatorExecuted): void {
                    $calculatorExecuted = true;
                },
            ],
            'guards' => [
                'successGuard' => function () use (&$guardExecuted) {
                    $guardExecuted = true;

                    return true;
                },
            ],
            'actions' => [
                'successAction' => function () use (&$actionExecuted): void {
                    $actionExecuted = true;
                },
            ],
        ],
    ]);

    // 2. Act
    $state = $machine->send(['type' => 'CHECK']);

    // 3. Assert
    expect($state->matches('state_b'))->toBeTrue()
        ->and($calculatorExecuted)->toBeTrue()
        ->and($guardExecuted)->toBeTrue()
        ->and($actionExecuted)->toBeTrue();
});

test('when calculator succeeds but guarded transition guard fails, next branch is tried', function (): void {
    // 1. Arrange
    $calculatorExecuted = false;
    $guardExecuted      = false;
    $actionExecuted     = false;

    $machine = Machine::create([
        'config' => [
            'initial' => 'state_a',
            'context' => [
                'value' => 10,
            ],
            'states' => [
                'state_a' => [
                    'on' => [
                        'CHECK' => [
                            [
                                'target'      => 'state_b',
                                'calculators' => 'successCalculator',
                                'guards'      => 'failGuard',
                                'actions'     => 'someAction',
                            ],
                            [
                                'target' => 'state_c',
                            ],
                        ],
                    ],
                ],
                'state_b' => [],
                'state_c' => [],
            ],
        ],
        'behavior' => [
            'calculators' => [
                'successCalculator' => function () use (&$calculatorExecuted): void {
                    $calculatorExecuted = true;
                },
            ],
            'guards' => [
                'failGuard' => function () use (&$guardExecuted) {
                    $guardExecuted = true;

                    return false;
                },
            ],
            'actions' => [
                'someAction' => function () use (&$actionExecuted): void {
                    $actionExecuted = true;
                },
            ],
        ],
    ]);

    // 2. Act
    $state = $machine->send(['type' => 'CHECK']);

    // 3. Assert
    expect($state->matches('state_c'))->toBeTrue()
        ->and($calculatorExecuted)->toBeTrue()
        ->and($guardExecuted)->toBeTrue()
        ->and($actionExecuted)->toBeFalse();
});

test('when calculator fails, guards and actions are not executed', function (): void {
    // 1. Arrange
    $guardExecuted  = false;
    $actionExecuted = false;

    $machine = Machine::create([
        'config' => [
            'initial' => 'state_a',
            'context' => [
                'value' => 10,
            ],
            'states' => [
                'state_a' => [
                    'on' => [
                        'CHECK' => [
                            [
                                'target'      => 'state_b',
                                'calculators' => 'failingCalculator',
                                'guards'      => 'someGuard',
                                'actions'     => 'someAction',
                            ],
                            [
                                'target' => 'state_c',
                            ],
                        ],
                    ],
                ],
                'state_b' => [],
                'state_c' => [],
            ],
        ],
        'behavior' => [
            'calculators' => [
                'failingCalculator' => function (): void {
                    throw new RuntimeException('Calculator failed');
                },
            ],
            'guards' => [
                'someGuard' => function () use (&$guardExecuted) {
                    $guardExecuted = true;

                    return true;
                },
            ],
            'actions' => [
                'someAction' => function () use (&$actionExecuted): void {
                    $actionExecuted = true;
                },
            ],
        ],
    ]);

    // 2. Act
    $state = $machine->send(['type' => 'CHECK']);

    // 3. Assert
    expect($state->matches('state_a'))->toBeTrue() // Calculator failed so we stay in state_a
        ->and($guardExecuted)->toBeFalse()
        ->and($actionExecuted)->toBeFalse();
});
