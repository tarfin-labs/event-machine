<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Illuminate\Database\Eloquent\Collection;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

it('can persist the machine state', function (): void {
    $machine = TrafficLightsMachine::create();

    $machine->send(['type' => 'INC'], shouldPersist: false);
    $machine->send(['type' => 'INC'], shouldPersist: false);
    $machine->send(['type' => 'INC'], shouldPersist: false);

    $state = $machine->persist();

    $eventIds = $machine->state->history
        ->pluck('id')
        ->map(fn ($key) => ['id' => $key])
        ->toArray();

    expect($state)->toBeInstanceOf(State::class);

    foreach ($eventIds as $eventId) {
        $this->assertDatabaseHas(MachineEvent::class, $eventId);
    }
});

it('can restore the persisted state', function (): void {
    $machine = TrafficLightsMachine::create();

    $machine->send(['type' => 'INC'], shouldPersist: false);
    $machine->send(['type' => 'INC'], shouldPersist: false);
    $machine->send(['type' => 'INC'], shouldPersist: false);

    $machine->persist();

    $state = $machine->state;

    $rootEventId = $machine->state->history->first()->root_event_id;

    $restoredMachineState = TrafficLightsMachine::create(state: $rootEventId)->state;

    expect($restoredMachineState->value)->toEqual($state->value);
    expect($restoredMachineState->context->toArray())->toEqual($state->context->toArray());

    expect($restoredMachineState->currentStateDefinition)
        ->toBeInstanceOf(StateDefinition::class)
        ->id->toBe($state->currentStateDefinition->id);

    expect($restoredMachineState->currentEventBehavior)
        ->toBeInstanceOf(EventDefinition::class)
        ->and($restoredMachineState->currentEventBehavior->toArray())
        ->toBe($state->currentEventBehavior->toArray());

    expect($restoredMachineState->history)
        ->toBeInstanceOf(Collection::class)
        ->each->toBeInstanceOf(MachineEvent::class);

    expect($restoredMachineState->history->toArray())
        ->toBe($state->history->toArray());
});

it('can auto persist after an event', function (): void {
    $machine = TrafficLightsMachine::create();

    $machine->send(['type' => 'INC'], shouldPersist: true);

    $eventIds = $machine->state->history
        ->pluck('id')
        ->map(fn ($key) => ['id' => $key])
        ->toArray();

    foreach ($eventIds as $eventId) {
        $this->assertDatabaseHas(MachineEvent::class, $eventId);
    }
});
