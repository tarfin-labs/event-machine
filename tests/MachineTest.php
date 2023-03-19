<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\MachineDefinition;

test('a machine is an instance of a MachineDefinition', function (): void {
    $machine = MachineDefinition::define();

    expect($machine)->toBeInstanceOf(MachineDefinition::class);
});

test('a machine has a name', function (): void {
    $machine = MachineDefinition::define([
        'name' => 'machine-name',
    ]);

    expect($machine->name)->toBe('machine-name');
});
