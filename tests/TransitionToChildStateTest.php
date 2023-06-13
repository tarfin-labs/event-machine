<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

it('can transition to a child state', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'stateA' => [
                'on' => [
                    'EVENT' => 'stateB.subStateOfB',
                ],
            ],
            'stateB' => [
                'states' => [
                    'subStateOfB' => [],
                ],
            ],
        ],
    ]);

    $newState = $machine->transition(state: null, event: [
        'type' => 'EVENT',
    ]);

    expect($newState->value)->toBe(['(machine).stateB.subStateOfB']);
});

it('can transition from a child state', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'stateB.subStateOfB',
        'states'  => [
            'stateA' => [],
            'stateB' => [
                'states' => [
                    'subStateOfB' => [
                        'on' => [
                            'EVENT' => 'stateA',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $newState = $machine->transition(state: null, event: [
        'type' => 'EVENT',
    ]);

    expect($newState->value)->toBe(['(machine).stateA']);
});
