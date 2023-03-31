<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\MachineDefinition;

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
                        'actions'    => 'action1',
                        'conditions' => 'condition1',
                    ],
                ],
            ],
        ],
    ]);

    $timerTransition = $machine->states['green']->transitions['TIMER'];

    expect($timerTransition->conditions)->toBe(['condition1']);
});

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
