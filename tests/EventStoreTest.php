<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

it('stores events', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'green',
        'states'  => [
            'green' => [
                'on' => [
                    'GREEN_TIMER' => 'yellow',
                ],
            ],
            'yellow' => [
                'on' => [
                    'RED_TIMER' => 'red',
                ],
            ],
            'red' => [],
        ],
    ]);

    $newState = $machine->transition(state: null, event: [
        'type' => 'GREEN_TIMER',
    ]);

    $newState = $machine->transition(state: $newState, event: [
        'type' => 'RED_TIMER',
    ]);

    expect($newState->history)->toHaveCount(2);
});
