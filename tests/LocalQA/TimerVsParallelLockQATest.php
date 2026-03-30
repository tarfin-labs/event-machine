<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
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
    config(['machine.parallel_dispatch.region_timeout' => 0]); // no region timeout
});

afterEach(function (): void {
    config(['machine.parallel_dispatch.enabled' => false]);
});

// ═══════════════════════════════════════════════════════════════
//  SendToMachineJob during active parallel region processing
// ═══════════════════════════════════════════════════════════════

it('LocalQA: SendToMachineJob arrives during parallel region processing — retries via lock and eventually processes', function (): void {
    // Scenario:
    // 1. Machine enters parallel state (ParallelRegionJobs dispatched)
    // 2. While regions are processing (lock held), external event arrives via SendToMachineJob
    // 3. SendToMachineJob gets MachineAlreadyRunningException → release(1)
    // 4. After regions complete, send both REGION_A_DONE and REGION_B_DONE to complete parallel
    // 5. External event is retried and can be processed (or release(2) if state changed)
    //
    // This tests lock contention between ParallelRegionJob and SendToMachineJob.
    $machine = ParallelDispatchViaEventMachine::create();
    $machine->send(['type' => 'START_PROCESSING']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for parallel regions to start (entry actions dispatched and running)
    $regionsStarted = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = ParallelDispatchViaEventMachine::create(state: $rootEventId);

        return $restored->state->context->get('regionAData') !== null
            || $restored->state->context->get('regionBData') !== null;
    }, timeoutSeconds: 60, description: 'timer vs parallel lock: regions started');

    expect($regionsStarted)->toBeTrue('Parallel regions did not start');

    // Now dispatch external events to complete the parallel state.
    // These arrive while lock may still be held by parallel region processing.
    // They should retry via release(1) on MachineAlreadyRunningException.
    SendToMachineJob::dispatch(
        machineClass: ParallelDispatchViaEventMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'REGION_A_DONE'],
    );

    SendToMachineJob::dispatch(
        machineClass: ParallelDispatchViaEventMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'REGION_B_DONE'],
    );

    // Wait for machine to reach completed state
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'timer vs parallel lock: machine completes after lock release');

    expect($completed)->toBeTrue('Machine did not reach completed — events lost to lock contention');

    // Verify machine is correctly in completed state
    $restored = ParallelDispatchViaEventMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toContain('completed');

    // Both regions should have produced their context data
    expect($restored->state->context->get('regionAData'))->not->toBeNull();
    expect($restored->state->context->get('regionBData'))->not->toBeNull();

    // No stale locks
    $locksCleared = LocalQATestCase::waitFor(function () use ($rootEventId) {
        return DB::table('machine_locks')->where('root_event_id', $rootEventId)->count() === 0;
    }, timeoutSeconds: 10, description: 'timer vs parallel lock: locks cleared');

    expect($locksCleared)->toBeTrue('Stale lock after parallel + external event');

    // No failed jobs
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0, 'Lock contention between parallel region and external event caused failure');
});

it('LocalQA: timer sweep fires during stalled parallel state — retries via lock without corruption', function (): void {
    // SlowRegionParallelMachine: Region B stalls (no event raised).
    // After regions dispatch, we simulate a timer sweep by dispatching
    // an event via SendToMachineJob while the machine is locked.
    //
    // The event should retry via release (lock contention or wrong state)
    // and NOT corrupt the parallel state.
    $machine = SlowRegionParallelMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for regions to start
    $regionsReady = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = SlowRegionParallelMachine::create(state: $rootEventId);

        return $restored->state->context->get('regionADone') === true;
    }, timeoutSeconds: 60, description: 'timer vs parallel lock: Region A started');

    expect($regionsReady)->toBeTrue('Region A did not start');

    // Dispatch an event that the machine doesn't handle in parallel state.
    // This simulates a timer event arriving during parallel processing.
    // It should release(2) on NoTransitionDefinitionFoundException.
    SendToMachineJob::dispatch(
        machineClass: SlowRegionParallelMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'NONEXISTENT_TIMER_EVENT'],
    );

    // The NONEXISTENT_TIMER_EVENT will keep retrying via release(2).
    // Meanwhile, complete the machine manually to verify no corruption.
    usleep(500_000);

    // Complete Region A (it may already be done via raise in entry action)
    SendToMachineJob::dispatch(
        machineClass: SlowRegionParallelMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'REGION_A_DONE'],
    );

    SendToMachineJob::dispatch(
        machineClass: SlowRegionParallelMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'REGION_B_DONE'],
    );

    // Wait for machine to reach a final state
    $settled = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && (str_contains($cs->state_id, 'completed') || str_contains($cs->state_id, 'failed'));
    }, timeoutSeconds: 60, description: 'timer vs parallel lock: machine settles after noise event');

    expect($settled)->toBeTrue('Machine did not settle after timer noise during parallel');

    // Verify context is not corrupted
    $restored = SlowRegionParallelMachine::create(state: $rootEventId);
    expect($restored->state->context->get('regionADone'))->toBeTrue('Region A context corrupted');
    expect($restored->state->context->get('regionBDone'))->toBeTrue('Region B context corrupted');

    // No stale locks
    $locksCleared = LocalQATestCase::waitFor(function () use ($rootEventId) {
        return DB::table('machine_locks')->where('root_event_id', $rootEventId)->count() === 0;
    }, timeoutSeconds: 10, description: 'timer vs parallel lock: locks cleared');

    expect($locksCleared)->toBeTrue('Stale lock after timer noise during parallel');
});
