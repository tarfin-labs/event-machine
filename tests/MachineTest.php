<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\MachineDefinition;

test('a machine is an instance of a MachineDefinition', function (): void {
    $nullMachine = MachineDefinition::define();

    expect($nullMachine)->toBeInstanceOf(MachineDefinition::class);
});

test('a machine has a name', function (): void {
    $machineWithName = MachineDefinition::define(config: [
        'name' => 'machine-name',
    ]);

    expect($machineWithName->name)->toBe('machine-name');
});

test('a machine has a default name', function (): void {
    $nullMachine = MachineDefinition::define();

    $nullNameMachine = MachineDefinition::define(config: [
        'name' => null,
    ]);

    expect($nullNameMachine->name)
        ->toBe(MachineDefinition::DEFAULT_NAME)
        ->and($nullMachine->name)
        ->toBe(MachineDefinition::DEFAULT_NAME);
});
