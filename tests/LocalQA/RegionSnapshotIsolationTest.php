<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

afterEach(function (): void {
    config(['machine.parallel_dispatch.enabled' => false]);
});

/*
 * Parallel regions see dispatch-time snapshot.
 *
 * When parallel dispatch is enabled, each region runs as a separate queue job.
 * Each region should see the context snapshot from the time the parallel state
 * was entered, NOT a context modified by a sibling region's action.
 * This ensures region isolation in dispatch mode.
 */

it('LocalQA: parallel dispatch regions start from same context snapshot', function (): void {
    config(['machine.parallel_dispatch.enabled' => true]);
    config(['machine.parallel_dispatch.queue' => 'default']);

    // Create machine and enter parallel state
    $machine = ParallelDispatchMachine::create();
    $machine->send(['type' => 'START_PROCESSING']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for parallel regions to complete
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        if (!$cs) {
            return false;
        }

        // Machine should settle after regions complete
        $restored = ParallelDispatchMachine::create(state: $rootEventId);
        $regionA  = $restored->state->context->get('region_a_result');
        $regionB  = $restored->state->context->get('region_b_result');

        return $regionA !== null && $regionB !== null;
    }, timeoutSeconds: 45);

    expect($completed)->toBeTrue('Parallel dispatch regions did not complete');

    // Verify machine state is consistent
    $restored = ParallelDispatchMachine::create(state: $rootEventId);

    // Both regions should have produced results
    expect($restored->state->context->get('region_a_result'))->not->toBeNull()
        ->and($restored->state->context->get('region_b_result'))->not->toBeNull();

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);

    // No failed jobs — region isolation did not cause crashes
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0, 'Region snapshot isolation caused failed jobs');
});
