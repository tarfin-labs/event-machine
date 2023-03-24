<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\MachineDefinition;
use Tarfinlabs\EventMachine\TransitionDefinition;

it('should list transitions', function (): void {
    $lightMachine = MachineDefinition::define([
        'id'      => 'light-machine',
        'initial' => 'green',
        'states'  => [
            'green' => [
                'on' => [
                    'TIMER'           => 'yellow',
                    'POWER_OUTAGE'    => 'red',
                    'FORBIDDEN_EVENT' => null,
                ],
            ],
            'yellow' => [
                'on' => [
                    'TIMER'        => 'red',
                    'POWER_OUTAGE' => 'red',
                ],
            ],
            'red' => [
                'on' => [
                    'TIMER'        => 'green',
                    'POWER_OUTAGE' => [
                        'target' => 'red',
                    ],
                ],
                'initial' => 'walk',
                'states'  => [
                    'walk' => [
                        'on' => [
                            'PED_COUNTDOWN' => 'wait',
                        ],
                    ],
                    'wait' => [
                        'on' => [
                            'PED_COUNTDOWN' => 'stop',
                        ],
                    ],
                    'stop' => [],
                ],
            ],
        ],
    ]);

    expect($lightMachine->states['green']->transitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->states['yellow']->transitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->states['red']->transitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->states['red']->states['walk']->transitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->states['red']->states['wait']->transitions)->each->toBeInstanceOf(TransitionDefinition::class);
    expect($lightMachine->states['red']->states['stop']->transitions)->each->toBeInstanceOf(TransitionDefinition::class);
});
