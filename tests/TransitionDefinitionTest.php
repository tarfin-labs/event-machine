<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Definition\TransitionDefinition;

test('single actions can be defined as strings instead of arrays', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        'target'  => 'yellow',
                        'actions' => 'action1',
                    ],
                ],
            ],
            'yellow' => [],
        ],
    ]);

    $timerTransition = $machine->stateDefinitions['green']->transitionDefinitions['TIMER'];

    expect($timerTransition->branches[0]->actions)->toBe(['action1']);
});

test('transitions can have decriptions', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        'target'      => 'yellow',
                        'description' => 'The timer has expired',
                    ],
                ],
            ],
            'yellow' => [],
        ],
    ]);

    $timerTransition = $machine->stateDefinitions['green']->transitionDefinitions['TIMER'];

    expect($timerTransition->description)->toBe('The timer has expired');
});

test('the transition target can be null', function (): void {
    $machine = MachineDefinition::define([
        'initial' => 'active',
        'states'  => [
            'active' => [
                'on' => [
                    'INC' => [
                        'actions' => ['increment'],
                    ],
                    'DEC' => [
                        'actions' => 'decrement',
                    ],
                ],
            ],
        ],
    ]);

    $incTransition = $machine->stateDefinitions['active']->transitionDefinitions['INC'];
    $decTransition = $machine->stateDefinitions['active']->transitionDefinitions['DEC'];

    expect($incTransition->branches[0]->target)->toBe(null);
    expect($decTransition->branches[0]->target)->toBe(null);
});

test('transitions can have actions', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        'target'  => 'yellow',
                        'actions' => [
                            'action1',
                            'action2',
                        ],
                    ],
                ],
            ],
            'yellow' => [],
        ],
    ]);

    $timerTransition = $machine->stateDefinitions['green']->transitionDefinitions['TIMER'];

    expect($timerTransition->branches[0]->actions)->toBe(['action1', 'action2']);
});

test('a guarded transition can have specified guards', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        'target' => 'yellow',
                        'guards' => 'guard1',
                    ],
                ],
            ],
            'yellow' => [],
        ],
    ]);

    /** @var TransitionDefinition $timerTransition */
    $timerTransition = $machine->stateDefinitions['green']->transitionDefinitions['TIMER'];

    expect($timerTransition->branches[0]->guards)->toBe(['guard1']);
});

test('a guarded transition can have multiple specified guards', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        'target' => 'yellow',
                        'guards' => [
                            'guard1',
                            'guard2',
                            'guard3',
                        ],
                    ],
                ],
            ],
            'yellow' => [],
        ],
    ]);

    /** @var TransitionDefinition $timerTransition */
    $timerTransition = $machine->stateDefinitions['green']->transitionDefinitions['TIMER'];

    expect($timerTransition->branches[0]->guards)->toBe([
        'guard1',
        'guard2',
        'guard3',
    ]);
});

test('a guarded transition can have multiple if-else targets', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => [
                        [
                            'target' => 'yellow',
                            'guards' => 'guard1',
                        ],
                        [
                            'target' => 'red',
                            'guards' => 'guard2',
                        ],
                        [
                            'target' => 'pedestrian',
                        ],
                    ],
                ],
            ],
            'yellow'     => [],
            'red'        => [],
            'pedestrian' => [],
        ],
    ]);

    $transitions = $machine->stateDefinitions['green']->transitionDefinitions;
    expect($transitions)
        ->toBeArray()
        ->toHaveCount(1)
        ->toHaveKey('TIMER');

    /** @var TransitionDefinition $guardedTimerTransitions */
    $guardedTimerTransitions = $transitions['TIMER'];

    expect($guardedTimerTransitions)->toBeInstanceOf(TransitionDefinition::class);

    expect($guardedTimerTransitions->branches[0]->target->key)->toBe('yellow');
    expect($guardedTimerTransitions->branches[0]->guards)->toBe(['guard1']);

    expect($guardedTimerTransitions->branches[1]->target->key)->toBe('red');
    expect($guardedTimerTransitions->branches[1]->guards)->toBe(['guard2']);

    expect($guardedTimerTransitions->branches[2]->target->key)->toBe('pedestrian');
    expect($guardedTimerTransitions->branches[2]->guards)->toBeNull();
});
