<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

test('state value can be matched', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'      => 'machine',
        'initial' => 'state_a',
        'states'  => [
            'state_a' => [
                'on' => [
                    'EVENT' => 'state_b.sub_state_of_b',
                ],
            ],
            'state_b' => [
                'states' => [
                    'sub_state_of_b' => [],
                ],
            ],
        ],
    ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $initialState = $machine->getInitialState();

    expect($initialState->matches('state_a'))->toBe(true);
    expect($initialState->matches('machine.state_a'))->toBe(true);

    $newState = $machine->transition(event: ['type' => 'EVENT']);

    expect($newState->matches('state_b.sub_state_of_b'))->toBe(true);
    expect($newState->matches('machine.state_b.sub_state_of_b'))->toBe(true);
});

test('Logs if log writing is turned on', function (): void {
    $machine = TrafficLightsMachine::create();

    Log::shouldReceive('debug')->times(3);

    $machine->send(event: ['type' => 'MULTIPLY']);
});

test('sequence numbers are always count of history at time of creation', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'      => 'test_machine',
        'initial' => 'idle',
        'states'  => [
            'idle' => [
                'on' => [
                    'START' => 'active',
                ],
            ],
            'active' => [
                'on' => [
                    'STOP' => 'idle',
                ],
            ],
        ],
    ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $initialState = $machine->getInitialState();

    // The most recent event's sequence number should equal the history count
    // This tests that sequence_number = count($this->history) + 1 was calculated correctly
    expect($initialState->history->last()->sequence_number)->toBe($initialState->history->count());

    $activeState = $machine->transition(event: ['type' => 'START']);

    // After transition, the new event should have sequence number equal to history count
    expect($activeState->history->last()->sequence_number)->toBe($activeState->history->count());

    $backToIdleState = $activeState->currentStateDefinition->machine->transition(
        event: ['type' => 'STOP'],
        state: $activeState
    );

    // After second transition, same rule should apply
    expect($backToIdleState->history->last()->sequence_number)->toBe($backToIdleState->history->count());
});
