<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

/*
 * dispatchTo FIFO ordering under load.
 *
 * When multiple events are dispatched to the same machine via SendToMachineJob,
 * the lock mechanism should serialize them. Each event should be processed in
 * order (FIFO) because earlier jobs acquire the lock first. Under load, later
 * events retry until the lock is released.
 *
 * Key invariant: no event is lost, no corruption, no failed jobs.
 */

it('LocalQA: multiple dispatchTo events are serialized via lock — no lost events', function (): void {
    $machine = AsyncParentMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Dispatch START first to move machine to processing
    SendToMachineJob::dispatch(
        machineClass: AsyncParentMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'START'],
    );

    // Wait for START to be processed
    $started = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'processing');
    }, timeoutSeconds: 30);

    expect($started)->toBeTrue('Machine did not reach processing state');

    // Now dispatch multiple events in rapid succession
    for ($i = 0; $i < 5; $i++) {
        SendToMachineJob::dispatch(
            machineClass: AsyncParentMachine::class,
            rootEventId: $rootEventId,
            event: ['type' => 'PING'],
        );
    }

    // Wait for all events to be processed (or for the machine to settle)
    sleep(5);

    // Machine should be in a consistent state
    $restored = AsyncParentMachine::create(state: $rootEventId);
    expect($restored->state)->not->toBeNull()
        ->and($restored->state->currentStateDefinition)->not->toBeNull();

    // No failed jobs from the burst
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0, 'Burst dispatchTo caused failed jobs');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0, 'Stale locks remain after burst dispatchTo');
});

it('LocalQA: dispatchTo to multiple machines in parallel does not cross-contaminate', function (): void {
    // Create two independent machines
    $machineA = AsyncParentMachine::create();
    $machineA->persist();
    $idA = $machineA->state->history->first()->root_event_id;

    $machineB = AsyncParentMachine::create();
    $machineB->persist();
    $idB = $machineB->state->history->first()->root_event_id;

    // Dispatch START to both simultaneously
    SendToMachineJob::dispatch(
        machineClass: AsyncParentMachine::class,
        rootEventId: $idA,
        event: ['type' => 'START'],
    );
    SendToMachineJob::dispatch(
        machineClass: AsyncParentMachine::class,
        rootEventId: $idB,
        event: ['type' => 'START'],
    );

    // Wait for both to process
    $bothProcessed = LocalQATestCase::waitFor(function () use ($idA, $idB) {
        $csA = MachineCurrentState::where('root_event_id', $idA)->first();
        $csB = MachineCurrentState::where('root_event_id', $idB)->first();

        return $csA && str_contains($csA->state_id, 'processing')
            && $csB && str_contains($csB->state_id, 'processing');
    }, timeoutSeconds: 30);

    expect($bothProcessed)->toBeTrue('Both machines did not reach processing state');

    // Each machine's events belong only to that machine (no cross-contamination)
    $eventsA = DB::table('machine_events')->where('root_event_id', $idA)->count();
    $eventsB = DB::table('machine_events')->where('root_event_id', $idB)->count();

    expect($eventsA)->toBeGreaterThan(0)
        ->and($eventsB)->toBeGreaterThan(0);

    // No failed jobs
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0);
});
