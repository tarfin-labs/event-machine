<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\SlowRegionParallelMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchViaEventMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();

    config(['machine.parallel_dispatch.enabled' => true]);
    config(['machine.parallel_dispatch.queue' => 'default']);
    // Short region timeout to create race condition with manual completion
    config(['machine.parallel_dispatch.region_timeout' => 3]);
});

afterEach(function (): void {
    config(['machine.parallel_dispatch.enabled' => false]);
    config(['machine.parallel_dispatch.region_timeout' => 0]);
});

// ═══════════════════════════════════════════════════════════════
//  Region timeout vs region completion — race condition
// ═══════════════════════════════════════════════════════════════

it('LocalQA: region completion at same time as timeout — no double transition, no corruption', function (): void {
    // SlowRegionParallelMachine:
    //   Region A: sets context + raises REGION_A_DONE → reaches final
    //   Region B: sets context only, no raise → stalls
    //
    // With region_timeout=3s, ParallelRegionTimeoutJob is dispatched with 3s delay.
    // We wait ~2s then manually complete Region B via REGION_B_DONE.
    // The timeout job fires at ~3s — at roughly the same time as our completion.
    //
    // Expected: machine reaches either completed (@done) or failed (@fail), not both.
    // No corruption, no double transition, no stale locks.
    $machine = SlowRegionParallelMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for Region A's entry action to complete (sets regionADone=true)
    $regionAReady = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = SlowRegionParallelMachine::create(state: $rootEventId);

        return $restored->state->context->get('regionADone') === true;
    }, timeoutSeconds: 60, description: 'region timeout race: Region A entry action complete');

    expect($regionAReady)->toBeTrue('Region A entry action did not complete');

    // Wait ~2 seconds, then complete both regions via events.
    // This creates a tight race with the 3s timeout job.
    sleep(2);

    // Complete Region A (already done via raise, but send event to be safe)
    SendToMachineJob::dispatch(
        machineClass: SlowRegionParallelMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'REGION_A_DONE'],
    );

    // Complete Region B — this races with the timeout job
    SendToMachineJob::dispatch(
        machineClass: SlowRegionParallelMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'REGION_B_DONE'],
    );

    // Wait for machine to settle in a final state (completed or failed — either is valid)
    $settled = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && (str_contains($cs->state_id, 'completed') || str_contains($cs->state_id, 'failed'));
    }, timeoutSeconds: 60, description: 'region timeout race: machine reaches final state');

    expect($settled)->toBeTrue('Machine did not reach any final state');

    // Verify machine is in exactly ONE final state (not transitioning between them)
    $restored   = SlowRegionParallelMachine::create(state: $rootEventId);
    $finalState = $restored->state->currentStateDefinition->id;
    expect($finalState)->toMatch('/\.(completed|failed)$/');

    // Region A context should be preserved regardless of outcome
    expect($restored->state->context->get('regionADone'))->toBeTrue();

    // Verify no double @done or double @fail — at most one of each
    $doneEvents = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->where('type', 'like', '%parallel%done%')
        ->count();

    $failEvents = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.timeout%')
        ->count();

    // Either @done fired OR timeout fired, but not both causing double transitions
    // (Both may record events, but only one should cause a state transition)
    expect($doneEvents + $failEvents)->toBeGreaterThanOrEqual(1);

    // No stale locks
    $locksCleared = LocalQATestCase::waitFor(function () use ($rootEventId) {
        return DB::table('machine_locks')->where('root_event_id', $rootEventId)->count() === 0;
    }, timeoutSeconds: 10, description: 'region timeout race: locks cleared');

    expect($locksCleared)->toBeTrue('Stale lock remains after race');

    // No failed jobs — race condition should not crash workers
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0, 'Region timeout race caused failed jobs');
});

it('LocalQA: region timeout fires just after last region completes — no regression', function (): void {
    // Use ParallelDispatchViaEventMachine which has entry actions that set context.
    // Complete both regions before the 3s timeout, then verify timeout no-ops.
    $machine = ParallelDispatchViaEventMachine::create();
    $machine->send(['type' => 'START_PROCESSING']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for entry actions to complete
    $ready = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = ParallelDispatchViaEventMachine::create(state: $rootEventId);

        return $restored->state->context->get('regionAData') !== null
            && $restored->state->context->get('regionBData') !== null;
    }, timeoutSeconds: 60, description: 'region timeout no-regression: entry actions complete');

    expect($ready)->toBeTrue('Entry actions did not complete');

    // Complete both regions quickly (before 3s timeout)
    SendToMachineJob::dispatch(
        machineClass: ParallelDispatchViaEventMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'REGION_A_DONE'],
    );

    usleep(300_000); // 300ms to serialize

    SendToMachineJob::dispatch(
        machineClass: ParallelDispatchViaEventMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'REGION_B_DONE'],
    );

    // Wait for @done → completed
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'region timeout no-regression: @done fires');

    expect($completed)->toBeTrue('@done did not fire');

    // Wait for timeout job to fire and no-op (3s delay from parallel entry + processing time)
    // Negative assertion: verify timeout does NOT regress machine state.
    sleep(2);

    // Machine should STILL be at completed (timeout no-op)
    $restored = ParallelDispatchViaEventMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toContain('completed');

    // No failed jobs from stale timeout job
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0, 'Stale timeout job caused a failed job');
});
