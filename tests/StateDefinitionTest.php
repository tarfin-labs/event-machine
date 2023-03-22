<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\StateDefinition;
use Tarfinlabs\EventMachine\MachineDefinition;

test('state definition is an instance of StateDefinition', function (): void {
    $machine = MachineDefinition::define();

    expect($machine->root)->toBeInstanceOf(StateDefinition::class);
});

test('state definition has a machine reference', function (): void {
    $machine = MachineDefinition::define();

    expect($machine->root)->toHaveProperty('machine');
    expect($machine->root->machine)->toBeInstanceOf(MachineDefinition::class);
    expect($machine->root->machine)->toBe($machine);
});

test('a state definition has a local id', function (): void {
    $localId = 'state_id';
    $machine = MachineDefinition::define(config: ['id' => $localId]);

    expect($machine->root)->toHaveProperty('key');
    expect($machine->root->key)->toBe($localId);
});

test('a state definition has a null local id when not provided', function (): void {
    // TODO: This test can be written better.
    $machine         = MachineDefinition::define();
    $stateDefinition = new StateDefinition(config: null, options: ['machine' => $machine]);

    expect($stateDefinition)->toHaveProperty('key');
    expect($stateDefinition->key)->toBeNull();
});

test('a state definition has a config', function (): void {
    $machineConfiguration = [
        'id' => 'machine_name',
    ];
    $machine = MachineDefinition::define($machineConfiguration);

    expect($machine->root)->toHaveProperty('config');
    expect($machine->root->config)->toBe($machineConfiguration);
});

test('a state definition has a null config when not provided', function (): void {
    $machine = MachineDefinition::define();

    expect($machine->root)->toHaveProperty('config');
    expect($machine->root->config)->toBeNull();
});

test('a state definition has a parent state definition', function (): void {
    // TODO: This test can be written better without using the root state definition and without expecting a null parent
    $machine = MachineDefinition::define();

    expect($machine->root)->toHaveProperty('parent');
    expect($machine->root->parent)->toBeNull();
});

test('the parent of a state definition is null if it has no parent', function (): void {
    // TODO: Write this test after implementing states on state definition
})->todo();

test('a state definition has a path', function (): void {
    $machineWithStates = MachineDefinition::define(config: [
        'states' => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machineWithStates->root)->toHaveProperty('path');
    expect($machineWithStates->root->path)->toBe([]);

    expect($machineWithStates->states['green'])->toHaveProperty('path');
    expect($machineWithStates->states['green']->path)->toBe(['green']);

    expect($machineWithStates->states['yellow'])->toHaveProperty('path');
    expect($machineWithStates->states['yellow']->path)->toBe(['yellow']);

    expect($machineWithStates->states['red'])->toHaveProperty('path');
    expect($machineWithStates->states['red']->path)->toBe(['red']);
});

test('a state definition can have a description', function (): void {
    $description = 'This is a description';
    $machine     = MachineDefinition::define(config: [
        'description' => $description,
    ]);

    expect($machine->root)->toHaveProperty('description');
    expect($machine->root->description)->toBe($description);
});

test('the state definition is null if not provided', function (): void {
    $nullMachine = MachineDefinition::define();

    expect($nullMachine->root)->toHaveProperty('description');
    expect($nullMachine->root->description)->toBeNull();
});

test('a state definition has an order', function (): void {
    $machineWithStates = MachineDefinition::define(config: [
        'states' => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machineWithStates->root)->toHaveProperty('order');
    expect($machineWithStates->root->order)->toBe(0);

    expect($machineWithStates->states['green'])->toHaveProperty('order');
    expect($machineWithStates->states['green']->order)->toBe(1);

    expect($machineWithStates->states['yellow'])->toHaveProperty('order');
    expect($machineWithStates->states['yellow']->order)->toBe(2);

    expect($machineWithStates->states['red'])->toHaveProperty('order');
    expect($machineWithStates->states['red']->order)->toBe(3);
});

test('a state definition has states', function (): void {
    $machineWithStates = MachineDefinition::define(config: [
        'states' => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machineWithStates->states)
        ->toBeArray()
        ->toHaveKeys(['green', 'yellow', 'red'])
        ->each->toBeInstanceOf(StateDefinition::class);
});

test('a state config can be null', function (): void {
    $machineWithStates = MachineDefinition::define(config: [
        'states' => [
            'green'  => null,
            'yellow' => null,
            'red'    => null,
        ],
    ]);

    expect($machineWithStates->states)
        ->toBeArray()
        ->toHaveKeys(['green', 'yellow', 'red'])
        ->each->toBeInstanceOf(StateDefinition::class);
});
