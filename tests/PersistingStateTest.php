<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Models\MachineEvent;
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

    $rootEventId = $machineActor->state->history->first()->root_event_id;

    $anotherMachineActor = TrafficLightsMachine::start($rootEventId);

    expect($anotherMachineActor->state->context->data->count)->toBe(3);
});
