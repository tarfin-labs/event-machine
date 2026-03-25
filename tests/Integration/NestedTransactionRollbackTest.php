<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

/*
 * User wraps Machine::send in DB::transaction that rolls back.
 *
 * When the caller wraps machine operations in an outer DB::transaction
 * and that transaction rolls back (either manually or via exception),
 * machine_events should NOT be persisted — they share the same connection.
 */

it('rolls back machine_events when outer DB::transaction is rolled back manually', function (): void {
    $machine = TrafficLightsMachine::create();

    // Count events before
    $eventsBefore = MachineEvent::count();

    DB::beginTransaction();

    $machine->send(['type' => 'INCREASE']);

    // Events written within transaction (visible to same connection)
    $eventsDuring = MachineEvent::count();
    expect($eventsDuring)->toBeGreaterThan($eventsBefore);

    DB::rollBack();

    // After rollback, events should be gone
    $eventsAfter = MachineEvent::count();
    expect($eventsAfter)->toBe($eventsBefore);
});

it('rolls back machine_events when outer DB::transaction throws exception', function (): void {
    $machine = TrafficLightsMachine::create();

    // Count events from machine creation
    $eventsAfterCreate = MachineEvent::count();

    try {
        DB::transaction(function () use ($machine): void {
            $machine->send(['type' => 'INCREASE']);
            $machine->send(['type' => 'INCREASE']);

            throw new RuntimeException('Simulated failure after sends');
        });
    } catch (RuntimeException) {
        // Expected — transaction should have rolled back
    }

    // Only the events from create() should remain — the sends rolled back
    $eventsAfter = MachineEvent::count();
    expect($eventsAfter)->toBe($eventsAfterCreate);
});

it('preserves machine_events when outer DB::transaction commits', function (): void {
    $machine = TrafficLightsMachine::create();

    $eventsAfterCreate = MachineEvent::count();

    DB::transaction(function () use ($machine): void {
        $machine->send(['type' => 'INCREASE']);
    });

    // Events from send should be persisted
    $eventsAfter = MachineEvent::count();
    expect($eventsAfter)->toBeGreaterThan($eventsAfterCreate);
});
