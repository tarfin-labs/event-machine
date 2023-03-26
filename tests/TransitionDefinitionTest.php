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