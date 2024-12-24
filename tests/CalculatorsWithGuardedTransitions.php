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

