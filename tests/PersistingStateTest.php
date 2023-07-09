<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

it('can persist the machine state', function (): void {
    $machineActor = TrafficLightsMachine::start();

    $machineActor->send(['type' => 'INC']);
    $machineActor->send(['type' => 'INC']);
    $machineActor->send(['type' => 'INC']);

    $state = $machineActor->persist();

    $eventIds = $machineActor->state->history
        ->pluck('id')
        ->map(fn ($key) => ['id' => $key])
        ->toArray();

    expect($state)->toBeInstanceOf(State::class);
    expect($eventIds)->each->toBeInDatabase(MachineEvent::class);
});

it('can restore the persisted state', function (): void {
    $machineActor = TrafficLightsMachine::start();

    $machineActor->send(['type' => 'INC']);
    $machineActor->send(['type' => 'INC']);
    $machineActor->send(['type' => 'INC']);

    $machineActor->persist();

    $state = $machineActor->state;

    $rootEventId = $machineActor->state->history->first()->root_event_id;

    $restoredMachineState = TrafficLightsMachine::start($rootEventId)->state;

    expect($restoredMachineState->value)->toEqual($state->value);
    expect($restoredMachineState->context->toArray())->toEqual($state->context->toArray());

    expect($restoredMachineState->currentStateDefinition)
        ->toBeInstanceOf(StateDefinition::class)
        ->id->toBe($state->currentStateDefinition->id);

    expect($restoredMachineState->currentEventBehavior)
        ->toBeInstanceOf(EventDefinition::class)
        ->and($restoredMachineState->currentEventBehavior->toArray())
        ->toBe($state->currentEventBehavior->toArray());
});
