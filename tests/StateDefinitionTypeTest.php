<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
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
    exceptionMessage: 'The final state `machine.yellow` should not have child states. Please revise your state machine definitions to ensure that final states are correctly configured without child states.'
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
    exceptionMessage: 'The final state `machine.yellow` should not have transitions. Check your state machine configuration to ensure events are not dispatched when in a final state.'
);

test('an initial state of type final triggers machine finish event', function (): void {
    $machine = Machine::create(definition: [
        'id'      => 'mac',
        'initial' => 'yellow',
        'states'  => [
            'yellow' => [
                'type' => 'final',
            ],
        ],
    ]);

    expect($machine->state->history->pluck('type')->toArray())
        ->toEqual([
            'mac.start',
            'mac.state.yellow.enter',
            'mac.state.yellow.entry.start',
            'mac.state.yellow.entry.finish',
            'mac.finish',
        ]);
});

test('a state of type final triggers machine finish event', function (): void {
    $machine = Machine::withDefinition(MachineDefinition::define(config: [
        'id'      => 'dummy',
        'initial' => 'yellow',
        'states'  => [
            'yellow' => [
                'on' => [
                    'EVENT' => [
                        'target' => 'red',
                    ],
                ],
            ],
            'red' => [
                'type' => 'final',
            ],
        ],
    ]));

    $state = $machine->send(['type' => 'EVENT']);

    expect($state->history->pluck('type')->toArray())
        ->toEqual([
            'dummy.start',
            'dummy.state.yellow.enter',
            'dummy.state.yellow.entry.start',
            'dummy.state.yellow.entry.finish',
            'EVENT',
            'dummy.transition.yellow.EVENT.start',
            'dummy.transition.yellow.EVENT.finish',
            'dummy.state.yellow.exit.start',
            'dummy.state.yellow.exit.finish',
            'dummy.state.yellow.exit',
            'dummy.state.red.enter',
            'dummy.state.red.entry.start',
            'dummy.state.red.entry.finish',
            'dummy.finish',
        ]);
});
