<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\StateDefinition;
use Tarfinlabs\EventMachine\MachineDefinition;

test('state definition is an instance of StateDefinition', function (): void {
    $machine         = MachineDefinition::define();

    expect($machine->root)->toBeInstanceOf(StateDefinition::class);
});

test('state definition has a machine reference', function (): void {
    $machine = MachineDefinition::define();

    expect($machine->root)->toHaveProperty('machine');
    expect($machine->root->machine)->toBeInstanceOf(MachineDefinition::class);
    expect($machine->root->machine)->toBe($machine);
});

test('state definition has a local id', function (): void {
    $localId = 'state_id';
    $machine = MachineDefinition::define(config: ['name' => $localId]);

    expect($machine->root)->toHaveProperty('localId');
    expect($machine->root->localId)->toBe($localId);
});

test('state definition has a null local id when not provided', function (): void {
    // TODO: This test can be written better.
    $machine = MachineDefinition::define();
    $stateDefinition = new StateDefinition(config: null, options: ['machine' => $machine]);

    expect($stateDefinition)->toHaveProperty('localId');
    expect($stateDefinition->localId)->toBeNull();
});

test('state definition has a config', function (): void {
    $machineConfiguration = [
        'name' => 'machine_name',
    ];
    $machine = MachineDefinition::define($machineConfiguration);

    expect($machine->root)->toHaveProperty('config');
    expect($machine->root->config)->toBe($machineConfiguration);
});
