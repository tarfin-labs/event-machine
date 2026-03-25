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

    // Enable parallel dispatch with region timeout
    config(['machine.parallel_dispatch.enabled' => true]);
    config(['machine.parallel_dispatch.queue' => 'default']);
    config(['machine.parallel_dispatch.region_timeout' => 5]);
});

afterEach(function (): void {
    config(['machine.parallel_dispatch.enabled' => false]);
    config(['machine.parallel_dispatch.region_timeout' => 0]);
});

// ═══════════════════════════════════════════════════════════════
//  Parallel Region Timeout — Real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: parallel region timeout fires @fail when region stalls', function (): void {
    // SlowRegionParallelMachine: both regions have entry actions (dispatched as ParallelRegionJobs).
    // Neither entry action completes the region → both stall → timeout fires @fail.
    $machine = SlowRegionParallelMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for entry actions to complete (ParallelRegionJobs via Horizon)
    $ready = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = SlowRegionParallelMachine::create(state: $rootEventId);

        return $restored->state->context->get('region_a_done') === true
            && $restored->state->context->get('region_b_done') === true;
    }, timeoutSeconds: 60, description: 'parallel region timeout: waiting for entry actions');

    expect($ready)->toBeTrue('Region entry actions did not complete');

    // Wait for timeout job to fire @fail (region_timeout=5s + processing time)
    $failed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'failed');
    }, timeoutSeconds: 60, description: 'parallel region timeout: waiting for @fail');

    expect($failed)->toBeTrue('Parallel region timeout did not fire @fail');

    // Assert: PARALLEL_REGION_TIMEOUT event recorded
    $timeoutEvent = DB::table('machine_events')
        ->where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.timeout%')
        ->first();
    expect($timeoutEvent)->not->toBeNull('PARALLEL_REGION_TIMEOUT event not recorded');
});

it('LocalQA: region timeout is no-op when all regions complete before timeout', function (): void {
    // ParallelDispatchViaEventMachine: both regions have entry actions
    $machine = ParallelDispatchViaEventMachine::create();
    $machine->send(['type' => 'START_PROCESSING']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for entry actions to complete
    $ready = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = ParallelDispatchViaEventMachine::create(state: $rootEventId);

        return $restored->state->context->get('region_a_result') !== null
            && $restored->state->context->get('region_b_result') !== null;
    }, timeoutSeconds: 60);

    expect($ready)->toBeTrue('Regions not ready');

    // Complete both regions via SendToMachineJob
    SendToMachineJob::dispatch(
        machineClass: ParallelDispatchViaEventMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'REGION_A_DONE'],
    );

    LocalQATestCase::waitFor(function () use ($rootEventId) {
        $events = DB::table('machine_events')
            ->where('root_event_id', $rootEventId)
            ->pluck('type');

        return $events->contains(fn ($t) => str_contains($t, 'region_a') && str_contains($t, 'done'));
    }, timeoutSeconds: 60);

    SendToMachineJob::dispatch(
        machineClass: ParallelDispatchViaEventMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'REGION_B_DONE'],
    );

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60);

    expect($completed)->toBeTrue('@done did not fire');

    // Negative assertion: verify timeout does NOT regress machine state.
    // sleep required — cannot waitFor absence. Timeout job fires after 5s delay.
    sleep(8);

    // Machine should still be at completed (timeout job should no-op)
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('completed');
});
