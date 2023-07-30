<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Definition\StateDefinitionType;

test('a state definition can be defined as final', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'yellow',
        'states'  => [
            'yellow' => [
                'type' => 'final',
            ],
        ],
    ]);

    $yellowState = $machine->stateDefinitions['yellow'];

    expect($yellowState->type)->toBe(StateDefinitionType::FINAL);
});

test('a state definition can be atomic', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'yellow',
        'states'  => [
            'yellow' => [],
        ],
    ]);

    $yellowState = $machine->stateDefinitions['yellow'];

    expect($yellowState->type)->toBe(StateDefinitionType::ATOMIC);
});
