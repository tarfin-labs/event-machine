<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
test('search nothing', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'green',
        'id'      => 'machine',
        'states'  => [
            'green'  => [],
            'yellow' => [],
            'red'    => [],
        ],
    ]);

    $foundStateDefinition = $machine->getNearestStateDefinitionByString(state: null);
    expect($foundStateDefinition)->toBe(null);
});
