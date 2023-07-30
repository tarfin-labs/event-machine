<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Definition\StateDefinitionType;
use Tarfinlabs\EventMachine\Exceptions\InvalidFinalStateDefinitionException;

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

test('a state definition can be compound', function (): void {
    $machine = MachineDefinition::define(config: [
        'initial' => 'yellow',
        'states'  => [
            'yellow' => [
                'states' => [
                    'a' => [],
                    'b' => [],
                ],
            ],
        ],
    ]);

    $yellowState = $machine->stateDefinitions['yellow'];

    expect($yellowState->type)->toBe(StateDefinitionType::COMPOUND);
});

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

test('a final state definition can not have child states', function (): void {
    MachineDefinition::define(config: [
        'initial' => 'yellow',
        'states'  => [
            'yellow' => [
                'type'   => 'final',
                'states' => [
                    'a' => [],
                    'b' => [],
                ],
            ],
        ],
    ]);
})->throws(
    exception: InvalidFinalStateDefinitionException::class,
    exceptionMessage: 'Final state `machine.yellow` can not have child states.'
);

test('a final state definition can not have transitions', function (): void {
    MachineDefinition::define(config: [
        'initial' => 'yellow',
        'states'  => [
            'yellow' => [
                'type' => 'final',
                'on'   => [
                    'EVENT' => [
                        'target' => 'red',
                    ],
                ],
            ],
            'red' => [],
        ],
    ]);
})->throws(
    exception: InvalidFinalStateDefinitionException::class,
    exceptionMessage: 'Final state `machine.yellow` can not have transitions.'
);
