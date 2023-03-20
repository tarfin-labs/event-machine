<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\StateDefinition;
use Tarfinlabs\EventMachine\MachineDefinition;

test('state definition is an instance of StateDefinition', function (): void {
    $machine         = MachineDefinition::define();

    expect($machine->root)->toBeInstanceOf(StateDefinition::class);
});
