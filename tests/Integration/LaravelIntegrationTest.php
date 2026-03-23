<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\AbcMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ElevatorMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

// === Machine Casting via $casts Tests ===

it('returns machine instances when accessing cast attributes', function (): void {
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

    // Accessing machine attributes triggers lazy proxy initialization
    expect($modelA->abc_mre->state)->not->toBeNull()
        ->and($modelA->abc_mre)->toBeInstanceOf(Machine::class)
        ->and($modelA->traffic_mre)->toBeInstanceOf(Machine::class)
        ->and($modelA->elevator_mre)->toBeInstanceOf(Machine::class);
});

it('can persist and restore machine state through transitions', function (): void {
    $modelA = ModelA::create([
        'value' => 'some value',
    ]);

    // Auto-initialized machines have root_event_id stored
    expect($modelA->getRawOriginal('abc_mre'))->not->toBeNull();
    expect($modelA->getRawOriginal('traffic_mre'))->not->toBeNull();

    // Send events through lazy proxy — triggers initialization + transition
    $modelA->traffic_mre->send(['type' => 'INCREASE']);
    $modelA->traffic_mre->send(['type' => 'INCREASE']);
    $modelA->traffic_mre->send(['type' => 'INCREASE']);

    $modelA->traffic_mre->persist();

    expect($modelA->traffic_mre)->toBeInstanceOf(Machine::class);

    $this->assertDatabaseHas(ModelA::class, [
        'traffic_mre' => $modelA->traffic_mre->state->history->first()->root_event_id,
    ]);
});
