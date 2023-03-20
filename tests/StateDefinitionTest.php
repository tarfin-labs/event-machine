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
