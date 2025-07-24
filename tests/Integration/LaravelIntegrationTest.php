<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ElevatorMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

// === HasMachines Trait Tests ===

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

// === Model Attributes to Machines Tests ===

it('can persist the machine state', function (): void {
    /** @var ModelA $a */
    $modelA = ModelA::create([
        'value' => 'some value',
    ]);

    $modelA->traffic_mre->send(['type' => 'INC']);
    $modelA->traffic_mre->send(['type' => 'INC']);
    $modelA->traffic_mre->send(['type' => 'INC']);

    $modelA->traffic_mre->persist();

    expect($modelA->abc_mre)->toBeInstanceOf(Machine::class);
    expect($modelA->traffic_mre)->toBeInstanceOf(Machine::class);

    $this->assertDatabaseHas(ModelA::class, [
        'abc_mre' => null,
    ]);

    $this->assertDatabaseHas(ModelA::class, [
        'traffic_mre' => $modelA->traffic_mre->state->history->first()->root_event_id,
    ]);
});
