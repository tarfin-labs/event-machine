<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

it('can persist the machine state', function (): void {
    $machineActor = TrafficLightsMachine::start();

    $machineActor->send(['type' => 'INC']);
    $machineActor->send(['type' => 'INC']);
    $machineActor->send(['type' => 'INC']);

    $machineActor->persist();

    $eventIds = $machineActor->state->history
        ->pluck('id')
        ->map(fn ($key) => ['id' => $key])
        ->toArray();

    expect($eventIds)->each->toBeInDatabase(MachineEvent::class);
});
