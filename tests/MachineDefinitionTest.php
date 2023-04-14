<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

test('a machine definition is an instance of a MachineDefinition', function (): void {
    $nullMachine = MachineDefinition::define();

    expect($nullMachine)->toBeInstanceOf(MachineDefinition::class);
});

test('a machine definition has its config', function (): void {
    $config = [
        'id' => 'machine-id',
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

test('a machine definition has a id', function (): void {
    $machineWithName = MachineDefinition::define(config: [
        'id' => 'machine-id',
    ]);

    expect($machineWithName)->toHaveProperty('id');
    expect($machineWithName->id)->toBe('machine-id');
});

test('a machine definition has a default id', function (): void {
    $nullMachine = MachineDefinition::define();

    $nullNameMachine = MachineDefinition::define(config: [
        'id' => null,
    ]);

    expect($nullNameMachine->id)->toBe(MachineDefinition::DEFAULT_ID);
    expect($nullMachine->id)->toBe(MachineDefinition::DEFAULT_ID);
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

test('a machine definition has a idMap', function (): void {
    $machineWithStates = MachineDefinition::define(config: [
        'states' => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    expect($machineWithStates)->toHaveProperty('idMap');
    expect($machineWithStates->idMap)->toBeInstanceOf(SplObjectStorage::class);
    expect($machineWithStates->idMap->contains($machineWithStates->root))->toBeTrue();
    expect($machineWithStates->idMap->contains($machineWithStates->states['green']))->toBeTrue();
    expect($machineWithStates->idMap->contains($machineWithStates->states['yellow']))->toBeTrue();
    expect($machineWithStates->idMap->contains($machineWithStates->states['red']))->toBeTrue();
});

test('a machine definition can have context', function (): void {
    $machine = MachineDefinition::define([
        'id'      => 'test',
        'context' => [
            'foo' => 'bar',
        ],
    ]);

    $context = $machine->config['context'];

    expect($context)->toBeArray();
    expect($context['foo'])->toBe('bar');
});
