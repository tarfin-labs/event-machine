<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

it('machineId is set after fresh create', function (): void {
    $machine = TrafficLightsMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    expect($machine->state->context->machineId())->toBe($rootEventId);
});

it('machineId is preserved after restore from DB', function (): void {
    $machine = TrafficLightsMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Restore from DB
    $restored = TrafficLightsMachine::create(state: $rootEventId);

    expect($restored->state->context->machineId())->toBe($rootEventId);
});
