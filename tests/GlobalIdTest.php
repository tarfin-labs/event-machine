<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\MachineDefinition;

test('global id represent root state definition', function (): void {
    // TODO: This test can be written better after implementing states in state definition.

    $machineWithName = MachineDefinition::define(config: [
        'id' => 'machine-id',
    ]);

    expect($machineWithName->root->id)->toBe('machine-id');
});
