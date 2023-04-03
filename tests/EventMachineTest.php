<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

test('TrafficLightsMachine definition returns a MachineDefinition instance', function (): void {
    $machine = TrafficLightsMachine::definition();

    expect($machine)->toBeInstanceOf(MachineDefinition::class);
});

test('TrafficLightsMachine transitions between states using EventMachine', function (): void {
    $machine = TrafficLightsMachine::definition();

    $newState = $machine->transition(state: null, event: ['type' => 'MUT']);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['active']);
    expect($newState->contextData)->toBe(['count' => 1]);

    // Ensure that the machine's context has not been changed.
    expect($machine->context->get('count'))->toBe(1);

    $newState = $machine->transition(state: $newState, event: ['type' => 'INC']);
    expect($newState->contextData)->toBe(['count' => 2]);

    $newState = $machine->transition(state: $newState, event: [
        'type' => 'MUT',
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe(['active']);
    expect($newState->contextData)->toBe(['count' => 4]);

    // Ensure that the machine's context has been changed.
    expect($machine->context->get('count'))->toBe(4);
});
