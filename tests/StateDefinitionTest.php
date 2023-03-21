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
    $machine = MachineDefinition::define(config: ['name' => $localId]);

    expect($machine->root)->toHaveProperty('localId');
    expect($machine->root->localId)->toBe($localId);
});

test('a state definition has a null local id when not provided', function (): void {
    // TODO: This test can be written better.
    $machine         = MachineDefinition::define();
    $stateDefinition = new StateDefinition(config: null, options: ['machine' => $machine]);

    expect($stateDefinition)->toHaveProperty('localId');
    expect($stateDefinition->localId)->toBeNull();
});

test('a state definition has a config', function (): void {
    $machineConfiguration = [
        'name' => 'machine_name',
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
    // TODO: This test can be written better without using the root state definition
    $machine = MachineDefinition::define();

    expect($machine->root)->toHaveProperty('path');
    expect($machine->root->path)->toBe([]);
});

test('a state definition can have a description', function (): void {
    $description = 'This is a description';
    $machine     = MachineDefinition::define(config: [
        'description' => $description,
    ]);

    expect($machine->root)->toHaveProperty('description');
    expect($machine->root->description)->toBe($description);
});
