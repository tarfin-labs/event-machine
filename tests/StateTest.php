<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('state value can be matched', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'      => 'machine',
        'initial' => 'stateA',
        'states'  => [
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

    $initialState = $machine->getInitialState();

    expect($initialState->matches('stateA'))->toBe(true);
    expect($initialState->matches('machine.stateA'))->toBe(true);
});
