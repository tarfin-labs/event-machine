<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\StateDefinition;
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

test('a machine definition config is null if no config given', function (): void {
    $nullMachine = MachineDefinition::define();

    expect($nullMachine)->toHaveProperty('config');
    expect($nullMachine->config)->toBeNull();
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

test('a machine definition can have a version', function (): void {
    $machineWithVersion = MachineDefinition::define(config: [
        'version' => '2.3.4',
    ]);

    expect($machineWithVersion)->toHaveProperty('version');
    expect($machineWithVersion->version)->toBe('2.3.4');
});

test('a machine definition version is null if no version config given', function (): void {
    $nullMachine = MachineDefinition::define();

    expect($nullMachine)->toHaveProperty('version');
    expect($nullMachine->version)->toBeNull();
});

test('a machine definition has a delimiter', function (): void {
    $machineWithDelimiter = MachineDefinition::define(config: [
        'delimiter' => '->',
    ]);

    expect($machineWithDelimiter)->toHaveProperty('delimiter');
    expect($machineWithDelimiter->delimiter)->toBe('->');
});

test('a machine definition has a default delimiter', function (): void {
    $nullMachine = MachineDefinition::define();

    expect($nullMachine)->toHaveProperty('delimiter');
    expect($nullMachine->delimiter)->toBe(MachineDefinition::STATE_DELIMITER);
});

test('a machine definition has a root state definition', function (): void {
    $machine = MachineDefinition::define();

    expect($machine)->toHaveProperty('root');
    expect($machine->root)->toBeInstanceOf(StateDefinition::class);
});

test('root state definition has a reference to the machine definition', function (): void {
    $nullMachine = MachineDefinition::define();

    expect($nullMachine->root->machine)->toBe($nullMachine);
});
