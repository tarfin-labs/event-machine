<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\StateDefinition;
use Tarfinlabs\EventMachine\MachineDefinition;

test('a machine definitin has initial state', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'green',
        'states'  => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machine->initialState)
        ->toBeInstanceOf(StateDefinition::class)
        ->key->toBe('green');

    expect($machine->states['green']->initialState)->toBeNull();
    expect($machine->states['yellow']->initialState)->toBeNull();
    expect($machine->states['red']->initialState)->toBeNull();
});
