<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\MachineDefinition;

test('a machine definition is an instance of a MachineDefinition', function (): void {
    $nullMachine = MachineDefinition::define();

    expect($nullMachine)->toBeInstanceOf(MachineDefinition::class);
});

test('a machine definition has its config', function (): void {
    $config = [
        'name' => 'machine-name',
    ];

    $machineWithName = MachineDefinition::define(config: $config);

    expect($machineWithName)->toHaveProperty('config');
    expect($machineWithName->config)->toBe($config);
});

test('a machine definition has a name', function (): void {
    $machineWithName = MachineDefinition::define(config: [
        'name' => 'machine-name',
    ]);

    expect($machineWithName)->toHaveProperty('name');
    expect($machineWithName->name)->toBe('machine-name');
});

test('a machine definition has a default name', function (): void {
    $nullMachine = MachineDefinition::define();

    $nullNameMachine = MachineDefinition::define(config: [
        'name' => null,
    ]);

    expect($nullNameMachine->name)->toBe(MachineDefinition::DEFAULT_NAME);
    expect($nullMachine->name)->toBe(MachineDefinition::DEFAULT_NAME);
});
