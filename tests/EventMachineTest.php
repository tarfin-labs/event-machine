<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\IncreaseEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\AddAnotherValueEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachineCompact;

test('TrafficLightsMachine definition returns a MachineDefinition instance', function (): void {
    $machineDefinition = TrafficLightsMachine::definition();

    expect($machineDefinition)->toBeInstanceOf(MachineDefinition::class);
});

test('TrafficLightsMachine transitions between states using Machine', function (): void {
    $machine = TrafficLightsMachine::create();

    $newState = $machine->send(event: ['type' => 'MUT']);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($newState->context->count)->toBe(0);

    $newState = $machine->send(event: ['type' => 'INC']);
    $newState = $machine->send(event: ['type' => 'INC']);

    expect($newState->context->count)->toBe(2);

    $newState = $machine->send(event: ['type' => 'MUT']);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($newState->context->count)->toBe(4);

    $newState = $machine->send(event: [
        'type'    => 'ADD',
        'payload' => [
            'value' => 16,
        ],
    ]);

    expect($newState)
        ->toBeInstanceOf(State::class)
        ->and($newState->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($newState->context->count)->toBe(20);
});

test('TrafficLightsMachine transitions between states using an IncreaseEvent implementing EventBehavior', function (): void {
    $machineDefinition = TrafficLightsMachine::definition();

    $increaseEvent = new IncreaseEvent();
    expect($increaseEvent)->toBeInstanceOf(EventBehavior::class);

    $newState = $machineDefinition->transition(event: $increaseEvent);
    expect($newState->context->count)->toBe(1);
});

test('TrafficLightsMachine transitions between states using an AddAnotherValueEvent implementing EventBehavior', function (): void {
    $machineDefinition = TrafficLightsMachine::definition();

    $addAnotherValueEvent = new AddAnotherValueEvent(41);
    expect($addAnotherValueEvent)->toBeInstanceOf(EventBehavior::class);

    $newState = $machineDefinition->transition(event: $addAnotherValueEvent);
    expect($newState->context->count)->toBe(41);
});

test('TrafficLightsMachineCompact can be build', function (): void {
    $machineDefinition = TrafficLightsMachineCompact::definition();

    expect($machineDefinition)->toBeInstanceOf(MachineDefinition::class);
});

test('TrafficLightsMachine can be started', function (): void {
    $machine = TrafficLightsMachine::create();

    $state = $machine->send(['type' => 'INC']);

    expect($state)
        ->toBeInstanceOf(State::class)
        ->and($state->value)->toBe([MachineDefinition::DEFAULT_ID.MachineDefinition::STATE_DELIMITER.'active'])
        ->and($state->context->count)->toBe(1);

    $this->assertDatabaseHas(MachineEvent::class, [
        'machine_value' => json_encode($state->value, JSON_THROW_ON_ERROR),
        'type'          => InternalEvent::STATE_ENTER->generateInternalEventName($machine->definition->id, $state->currentStateDefinition->key),
    ]);
});
