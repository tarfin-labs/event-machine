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

test('multiple calculators are executed in sequence and stop on first failure', function (): void {
    // 1. Arrange
    $calculator1Executed = false;
    $calculator2Executed = false;
    $calculator3Executed = false;
    $guardExecuted       = false;
    $actionExecuted      = false;

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
                                'calculators' => [
                                    'calculator1',
                                    'failingCalculator',
                                    'calculator3',
                                ],
                                'guards'  => 'someGuard',
                                'actions' => 'someAction',
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
                'calculator1' => function () use (&$calculator1Executed): void {
                    $calculator1Executed = true;
                },
                'failingCalculator' => function () use (&$calculator2Executed): void {
                    $calculator2Executed = true;
                    throw new RuntimeException('Calculator 2 failed');
                },
                'calculator3' => function () use (&$calculator3Executed): void {
                    $calculator3Executed = true;
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
        ->and($calculator1Executed)->toBeTrue() // first calculator was executed
        ->and($calculator2Executed)->toBeTrue() // second calculator was executed and failed
        ->and($calculator3Executed)->toBeFalse() // third calculator was never executed
        ->and($guardExecuted)->toBeFalse() // guard was never executed
        ->and($actionExecuted)->toBeFalse(); // action was never executed
});
