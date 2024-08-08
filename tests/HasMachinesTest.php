<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ElevatorMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

it('it should return a machine', function (): void {
    $abcMachine = AbcMachine::create();
    $abcMachine->persist();

    $trafficLightsMachine = TrafficLightsMachine::create();
    $trafficLightsMachine->persist();

    $elevatorMachine = ElevatorMachine::create();
    $elevatorMachine->persist();

    ModelA::create([
        'abc_mre'      => $abcMachine->state->history->first()->root_event_id,
        'traffic_mre'  => $trafficLightsMachine->state->history->first()->root_event_id,
        'elevator_mre' => $elevatorMachine->state->history->first()->root_event_id,
    ]);

    $modelA = ModelA::first();

    expect($modelA->elevator_mre)
        ->toBeInstanceOf(Machine::class)
        ->and($modelA->abc_mre)
        ->toBeInstanceOf(Machine::class)
        ->and($modelA->traffic_mre)
        ->toBeInstanceOf(Machine::class);
});
