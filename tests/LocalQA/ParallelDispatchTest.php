<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchViaEventMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();

    // Enable parallel dispatch for these tests
    config(['machine.parallel_dispatch.enabled' => true]);
    config(['machine.parallel_dispatch.queue' => 'default']);
});

afterEach(function (): void {
    config(['machine.parallel_dispatch.enabled' => false]);
});

// ═══════════════════════════════════════════════════════════════
//  Parallel regions via real Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: parallel region entry actions dispatch and run via Horizon', function (): void {
    // Use ViaEvent machine — send() dispatches ParallelRegionJob
    $machine = ParallelDispatchViaEventMachine::create();
    $machine->send(['type' => 'START_PROCESSING']);

    // send() dispatches pending parallel jobs after persist
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for Horizon to process ParallelRegionJobs (entry actions set context)
    $entryActionsRan = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = ParallelDispatchViaEventMachine::create(state: $rootEventId);
        $regionA  = $restored->state->context->get('region_a_result');
        $regionB  = $restored->state->context->get('region_b_result');

        return $regionA !== null && $regionB !== null;
    }, timeoutSeconds: 60);

    expect($entryActionsRan)->toBeTrue('Region entry actions did not run via Horizon');
});

it('LocalQA: parallel regions complete via events → @done fires', function (): void {
    $machine = ParallelDispatchViaEventMachine::create();
    $machine->send(['type' => 'START_PROCESSING']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for entry actions to complete first
    $ready = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = ParallelDispatchViaEventMachine::create(state: $rootEventId);

        return $restored->state->context->get('region_a_result') !== null;
    }, timeoutSeconds: 60);

    expect($ready)->toBeTrue('Regions not ready');

    // Complete both regions via external events
    SendToMachineJob::dispatch(
        machineClass: ParallelDispatchViaEventMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'REGION_A_DONE'],
    );

    sleep(1);

    SendToMachineJob::dispatch(
        machineClass: ParallelDispatchViaEventMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'REGION_B_DONE'],
    );

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60);

    expect($completed)->toBeTrue('Parallel @done did not fire');

    $locks = DB::table('machine_locks')->count();
    expect($locks)->toBe(0);
});

it('LocalQA: concurrent region completions — locking preserves state', function (): void {
    $machine = ParallelDispatchViaEventMachine::create();
    $machine->send(['type' => 'START_PROCESSING']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    $ready = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = ParallelDispatchViaEventMachine::create(state: $rootEventId);

        return $restored->state->context->get('region_a_result') !== null;
    }, timeoutSeconds: 60);

    expect($ready)->toBeTrue();

    // Dispatch BOTH simultaneously
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

    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60);

    expect($completed)->toBeTrue('Concurrent completions did not resolve');

    $restored = ParallelDispatchViaEventMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_dispatch_via_event.completed');

    $locks = DB::table('machine_locks')->count();
    expect($locks)->toBe(0);
});
