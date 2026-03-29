<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Exceptions\InvalidStateConfigException;

test('multiple unguarded transitions on same event are rejected by validator', function (): void {
    // EventMachine enforces that a guardless (default) branch must be the last condition.
    // Having two unguarded transitions on the same event is an invalid configuration
    // because the second one would be unreachable — the validator catches this at definition time.
    //
    // This is EventMachine's approach to document-order tiebreaking for unguarded transitions:
    // rather than silently picking the first, it rejects ambiguous configs entirely.
    // For the guarded equivalent (both guards return true), see GuardPriorityTest.

    Machine::create([
        'config' => [
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO' => [
                            [
                                'target' => 'first_target',
                            ],
                            [
                                'target' => 'second_target',
                            ],
                        ],
                    ],
                ],
                'first_target'  => [],
                'second_target' => [],
            ],
        ],
    ]);
})->throws(
    InvalidStateConfigException::class,
    "State 'idle' has invalid conditions order for event 'GO'. Default condition (no guards) must be the last condition."
);

test('three unguarded transitions on same event are rejected by validator', function (): void {
    // Same principle: multiple guardless branches are always invalid,
    // regardless of how many there are.

    Machine::create([
        'config' => [
            'initial' => 'waiting',
            'states'  => [
                'waiting' => [
                    'on' => [
                        'PROCEED' => [
                            ['target' => 'alpha'],
                            ['target' => 'beta'],
                            ['target' => 'gamma'],
                        ],
                    ],
                ],
                'alpha' => [],
                'beta'  => [],
                'gamma' => [],
            ],
        ],
    ]);
})->throws(
    InvalidStateConfigException::class,
    "State 'waiting' has invalid conditions order for event 'PROCEED'. Default condition (no guards) must be the last condition."
);

test('single unguarded transition after guarded transitions is valid', function (): void {
    // The valid pattern: guarded branches first, then one unguarded default at the end.
    // This confirms the validator allows the correct configuration.

    $machine = Machine::create([
        'config' => [
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => [
                        'GO' => [
                            [
                                'target' => 'guarded_target',
                                'guards' => 'alwaysFalseGuard',
                            ],
                            [
                                'target' => 'default_target',
                            ],
                        ],
                    ],
                ],
                'guarded_target' => [],
                'default_target' => [],
            ],
        ],
        'behavior' => [
            'guards' => [
                'alwaysFalseGuard' => fn () => false,
            ],
        ],
    ]);

    $state = $machine->send(['type' => 'GO']);

    // Guard fails, so the default (unguarded) branch wins
    expect($state->matches('default_target'))->toBeTrue();
});
