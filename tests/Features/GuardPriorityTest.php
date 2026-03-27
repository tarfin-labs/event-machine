<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;

test('first-match guard wins in document order when multiple guards return true', function (): void {
    // 1. Arrange
    $firstGuardExecuted  = false;
    $secondGuardExecuted = false;

    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        'CHOOSE' => [
                            [
                                'target' => 'option_a',
                                'guards' => 'firstTrueGuard',
                            ],
                            [
                                'target' => 'option_b',
                                'guards' => 'secondTrueGuard',
                            ],
                        ],
                    ],
                ],
                'option_a' => [],
                'option_b' => [],
            ],
        ],
        'behavior' => [
            'guards' => [
                'firstTrueGuard' => function () use (&$firstGuardExecuted) {
                    $firstGuardExecuted = true;

                    return true;
                },
                'secondTrueGuard' => function () use (&$secondGuardExecuted) {
                    $secondGuardExecuted = true;

                    return true;
                },
            ],
        ],
    ]);

    // 2. Act
    $state = $machine->send(['type' => 'CHOOSE']);

    // 3. Assert — first-match semantics: option_a wins because its guard is listed first
    expect($state->matches('option_a'))->toBeTrue()
        ->and($firstGuardExecuted)->toBeTrue()
        ->and($secondGuardExecuted)->toBeFalse(); // second guard should not even be evaluated
});
