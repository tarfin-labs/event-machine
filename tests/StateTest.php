<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

test('state value can be matched', function (): void {
    $machine = MachineDefinition::define(config: [
        'id'      => 'machine',
        'initial' => 'stateA',
        'states'  => [
            'stateA' => [
                'on' => [
                    'EVENT' => 'stateB.subStateOfB',
                ],
            ],
            'stateB' => [
                'states' => [
                    'subStateOfB' => [],
                ],
            ],
        ],
    ]);

    $initialState = $machine->getInitialState();

    expect($initialState->matches('stateA'))->toBe(true);
    expect($initialState->matches('machine.stateA'))->toBe(true);

    $newState = $machine->transition(event: ['type' => 'EVENT']);

    expect($newState->matches('stateB.subStateOfB'))->toBe(true);
    expect($newState->matches('machine.stateB.subStateOfB'))->toBe(true);
});

test('Logs if log writing is turned on', function (): void {
    $machine = TrafficLightsMachine::create();

    Log::shouldReceive('debug')->times(3);

    $machine->send(event: ['type' => 'MUT']);
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
    ]);

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
