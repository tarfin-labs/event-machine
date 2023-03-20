<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\MachineDefinition;

test('root state definition has a reference to the machine definition', function (): void {
    $nullMachine = MachineDefinition::define();

    expect($nullMachine->root->machine)->toBe($nullMachine);
});

test('root state definition has a local id', function (): void {
    $machineName = 'custom_machine_name';
    $machine     = MachineDefinition::define(config: ['name' => $machineName]);

    expect($machine->root)->toHaveProperty('localId');
    expect($machine->root->localId)->toBe($machineName);
});

test('root state definition has a default local id', function (): void {
    $machine = MachineDefinition::define();

    expect($machine->root)->toHaveProperty('localId');
    expect($machine->root->localId)->toBe(MachineDefinition::DEFAULT_NAME);
});
