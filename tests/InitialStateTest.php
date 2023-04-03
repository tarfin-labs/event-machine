<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Definition\StateDefinition;

test('initial states are correctly set for both top-level machine definition and sub-states when explicitly specified', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'green',
        'states'  => [
            'green' => [
                'initial' => 'walk',
                'states'  => [
                    'walk' => [],
                    'wait' => [],
                    'stop' => [],
                ],
            ],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machine->initial)
        ->toBeInstanceOf(StateDefinition::class)
        ->key->toBe('green');

    expect($machine->states['green']->initial)
        ->toBeInstanceOf(StateDefinition::class)
        ->key->toBe('walk');

    expect($machine->states['yellow']->initial)->toBeNull();
    expect($machine->states['red']->initial)->toBeNull();

    expect($machine->states['green']->states['walk']->initial)->toBeNull();
    expect($machine->states['green']->states['wait']->initial)->toBeNull();
    expect($machine->states['green']->states['stop']->initial)->toBeNull();
});

test('the first state definition is set as the initial state for both top-level machine definition and sub-states when not explicitly specified', function (): void {
    $machine = MachineDefinition::define(config: [
        'states' => [
            'green' => [
                'states' => [
                    'walk' => [],
                    'wait' => [],
                    'stop' => [],
                ],
            ],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machine->initial)
        ->toBeInstanceOf(StateDefinition::class)
        ->key->toBe('green');

    expect($machine->states['green']->initial)
        ->toBeInstanceOf(StateDefinition::class)
        ->key->toBe('walk');

    expect($machine->states['yellow']->initial)->toBeNull();
    expect($machine->states['red']->initial)->toBeNull();

    expect($machine->states['green']->states['walk']->initial)->toBeNull();
    expect($machine->states['green']->states['wait']->initial)->toBeNull();
    expect($machine->states['green']->states['stop']->initial)->toBeNull();
});
