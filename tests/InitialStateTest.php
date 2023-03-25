<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\StateDefinition;
use Tarfinlabs\EventMachine\MachineDefinition;

test('a machine definition has initial state', function (): void {
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

test('a sub state definition can have initial state', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'green',
        'states'  => [
            'green'  => [
                'initial' => 'walk',
                'states'  => [
                    'walk'  => [],
                    'wait'  => [],
                    'stop'  => [],
                ],
            ],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machine->initialState)
        ->toBeInstanceOf(StateDefinition::class)
        ->key->toBe('green');

    expect($machine->states['green']->initialState)
        ->toBeInstanceOf(StateDefinition::class)
        ->key->toBe('walk');

    expect($machine->states['yellow']->initialState)->toBeNull();
    expect($machine->states['red']->initialState)->toBeNull();

    expect($machine->states['green']->states['walk']->initialState)->toBeNull();
    expect($machine->states['green']->states['wait']->initialState)->toBeNull();
    expect($machine->states['green']->states['stop']->initialState)->toBeNull();
});
