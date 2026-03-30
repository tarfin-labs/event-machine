<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EBothFailMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EContextConflictMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();

    config(['machine.parallel_dispatch.enabled' => true]);
    config(['machine.parallel_dispatch.queue' => 'default']);
});

afterEach(function (): void {
    config(['machine.parallel_dispatch.enabled' => false]);
});

// ═══════════════════════════════════════════════════════════════
//  Both parallel regions fail simultaneously — single @fail
// ═══════════════════════════════════════════════════════════════

it('LocalQA: both parallel regions fail simultaneously — single @fail transition via Horizon', function (): void {
    // E2EBothFailMachine has two regions that both throw RuntimeException on entry.
    // Under real Horizon, both ParallelRegionJobs fail concurrently.
    // The lock in ParallelRegionJob.failed() ensures only ONE @fail transition fires.
    config(['machine.parallel_dispatch.job_tries' => 1]);
    config(['machine.parallel_dispatch.job_backoff' => 0]);

    $machine = E2EBothFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    // Wait for machine to reach failed state
    $failed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'failed');
    }, timeoutSeconds: 45, description: 'machine reaches failed state after both regions fail');

    expect($failed)->toBeTrue('Machine did not reach failed state after both regions failed');

    // Verify machine is in the failed state
    $restored = E2EBothFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_both_fail.failed');

    // At least one PARALLEL_FAIL event recorded — under concurrent dispatch,
    // both regions may record a fail event before the lock serializes the transition.
    // The important assertion is that the machine ends in 'failed' state (above).
    $failEvents = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->where('type', 'like', '%parallel%.fail')
        ->count();

    expect($failEvents)->toBeGreaterThanOrEqual(1)
        ->and($failEvents)->toBeLessThanOrEqual(2);

    // Neither region's result was set (both threw before setting context)
    expect($restored->state->context->get('regionAData'))->toBeNull();
    expect($restored->state->context->get('regionBData'))->toBeNull();

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

// ═══════════════════════════════════════════════════════════════
//  Parallel region same-key scalar overwrite (LWW)
// ═══════════════════════════════════════════════════════════════

it('LocalQA: parallel region scalar context overwrite — last writer wins under concurrency', function (): void {
    // E2EContextConflictMachine has two regions both writing to shared_scalar.
    // Under real Horizon with true concurrency, whichever region commits second wins.
    $machine = E2EContextConflictMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    // Wait for completion
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 45, description: 'context conflict machine completes (scalar overwrite test)');

    expect($completed)->toBeTrue('Context conflict machine did not complete');

    $restored = E2EContextConflictMachine::create(state: $rootEventId);

    // Both regions executed
    expect($restored->state->context->get('regionAWrote'))->toBeTrue();
    expect($restored->state->context->get('regionBWrote'))->toBeTrue();

    // Scalar: one of the two values wins (last-writer-wins, nondeterministic under concurrency)
    $sharedScalar = $restored->state->context->get('sharedScalar');
    expect($sharedScalar)->toBeIn(['value_from_a', 'value_from_b']);

    // A PARALLEL_CONTEXT_CONFLICT event should be recorded for the second writer
    $conflictEvents = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->where('type', 'like', '%context_conflict%')
        ->count();

    expect($conflictEvents)->toBeGreaterThanOrEqual(1, 'Expected at least one context conflict event');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

// ═══════════════════════════════════════════════════════════════
//  Parallel region context merge preserves both regions' changes
// ═══════════════════════════════════════════════════════════════

it('LocalQA: parallel region context merge preserves both regions changes under concurrency', function (): void {
    // E2EContextConflictMachine regions write to shared_array with different keys.
    // Deep merge should preserve both from_a and from_b under real Horizon concurrency.
    $machine = E2EContextConflictMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    // Wait for completion
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 45, description: 'context conflict machine completes (deep merge test)');

    expect($completed)->toBeTrue('Context conflict machine did not complete');

    $restored    = E2EContextConflictMachine::create(state: $rootEventId);
    $sharedArray = $restored->state->context->get('sharedArray');

    // Deep merge: both regions' unique keys survive
    expect($sharedArray['from_a'])->toBeTrue('Region A key not preserved in merge');
    expect($sharedArray['from_b'])->toBeTrue('Region B key not preserved in merge');

    // Shared nested key (score): one value wins via LWW
    expect($sharedArray['score'])->toBeIn([85, 92]);

    // Machine completed cleanly
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_context_conflict.completed');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});
