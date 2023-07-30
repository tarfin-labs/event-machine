<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\InternalEvent;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\IncreaseEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\AddAnotherValueEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachineCompact;

test('TrafficLightsMachine definition returns a MachineDefinition instance', function (): void {
    $machine = TrafficLightsMachine::build();

    expect($machine)->toBeInstanceOf(MachineDefinition::class);
});

test('TrafficLightsMachine transitions between states using EventMachine', function (): void {
    $machine = TrafficLightsMachine::build();

    $newState = $machine->transition(event: ['type' => 'MUT']);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($newState->context->count)->toBe(1);

    $newState = $machine->transition(event: ['type' => 'INC'], state: $newState);

    expect($newState->context->count)->toBe(2);

    $newState = $machine->transition(event: [
        'type' => 'MUT',
    ], state: $newState);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($newState->context->count)->toBe(4);

    $newState = $machine->transition(event: [
        'type'    => 'ADD',
        'payload' => [
            'value' => 16,
        ],
    ], state: $newState);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($newState->context->count)->toBe(20);
});

test('TrafficLightsMachine transitions between states using an IncreaseEvent implementing EventBehavior', function (): void {
    $machine = TrafficLightsMachine::build();

    $increaseEvent = new IncreaseEvent();
    expect($increaseEvent)->toBeInstanceOf(EventBehavior::class);

    $newState = $machine->transition(event: $increaseEvent);
    expect($newState->context->count)->toBe(2);
});

test('TrafficLightsMachine transitions between states using an AddAnotherValueEvent implementing EventBehavior', function (): void {
    $machine = TrafficLightsMachine::build();

    $addAnotherValueEvent = new AddAnotherValueEvent(41);
    expect($addAnotherValueEvent)->toBeInstanceOf(EventBehavior::class);

    $newState = $machine->transition(event: $addAnotherValueEvent);
    expect($newState->context->count)->toBe(42);
});

test('TrafficLightsMachineCompact can be build', function (): void {
    $machine = TrafficLightsMachineCompact::build();

    expect($machine)->toBeInstanceOf(MachineDefinition::class);
});

test('TrafficLightsMachine can be started', function (): void {
    $machineActor = TrafficLightsMachine::start();

    $state = $machineActor->send(['type' => 'INC']);

    expect($state)
        ->toBeInstanceOf(State::class)
        ->and($state->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($state->context->count)->toBe(2);

    expect(['machine_value' => json_encode($state->value), 'type' => sprintf(InternalEvent::STATE_INIT->value, Str::of($state->currentStateDefinition->key)->classBasename()->camel())])
        ->toBeInDatabase(MachineEvent::class);
});
