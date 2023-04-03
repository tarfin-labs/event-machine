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

    $timerTransition = $machine->states['green']->transitions['TIMER'];

    expect($timerTransition->actions)->toBe(['action1']);
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

    $timerTransition = $machine->states['green']->transitions['TIMER'];

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

    $incTransition = $machine->states['active']->transitions['INC'];
    $decTransition = $machine->states['active']->transitions['DEC'];

    expect($incTransition->target)->toBeNull();
    expect($decTransition->target)->toBeNull();
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

    $timerTransition = $machine->states['green']->transitions['TIMER'];

    expect($timerTransition->actions)->toBe(['action1', 'action2']);
});

test('a guarded transition can have specified condition', function (): void {
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

    $timerTransition = $machine->states['green']->transitions['TIMER'];

    expect($timerTransition->conditions)->toBe(['guard1']);
});

test('a guarded transition can have multiple specified conditions', function (): void {
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

    $timerTransition = $machine->states['green']->transitions['TIMER'];

    expect($timerTransition->conditions)->toBe([
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

    $transitions = $machine->states['green']->transitions;
    expect($transitions)
        ->toBeArray()
        ->toHaveCount(1)
        ->toHaveKey('TIMER');

    $guardedTimerTransitions = $transitions['TIMER'];

    expect($guardedTimerTransitions)
        ->toBeArray()
        ->toHaveCount(3)
        ->each->toBeInstanceOf(TransitionDefinition::class);

    expect($guardedTimerTransitions[0]->target->key)->toBe('yellow');
    expect($guardedTimerTransitions[0]->conditions)->toBe(['guard1']);

    expect($guardedTimerTransitions[1]->target->key)->toBe('red');
    expect($guardedTimerTransitions[1]->conditions)->toBe(['guard2']);

    expect($guardedTimerTransitions[2]->target->key)->toBe('pedestrian');
    expect($guardedTimerTransitions[2]->conditions)->toBeNull();
});
