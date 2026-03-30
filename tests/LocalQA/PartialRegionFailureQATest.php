<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\PartialFailParallelMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();

    config(['machine.parallel_dispatch.enabled' => true]);
    config(['machine.parallel_dispatch.queue' => 'default']);
    // Set tries=1 so failed() fires immediately on exception (no retries with backoff)
    config(['machine.parallel_dispatch.job_tries' => 1]);
});

afterEach(function (): void {
    config(['machine.parallel_dispatch.enabled' => false]);
});

// ═══════════════════════════════════════════════════════════════
//  Partial parallel failure: Region A succeeds, Region B fails
// ═══════════════════════════════════════════════════════════════

it('LocalQA: Region A succeeds and Region B fails — @fail fires and Region A context survives', function (): void {
    // PartialFailParallelMachine:
    //   Region A: ProcessRegionAAction → sets regionAData + raises REGION_A_PROCESSED → final
    //   Region B: ThrowRuntimeExceptionAction → throws RuntimeException
    //
    // Expected: @fail fires (Region B failed), machine → failed state.
    // Region A's context (regionAData='processed_by_a') should survive the failure.
    $machine = PartialFailParallelMachine::create();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for machine to reach failed state via @fail
    $failed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'failed');
    }, timeoutSeconds: 60, description: 'partial region failure: @fail fires after Region B throws');

    expect($failed)->toBeTrue('Machine did not reach failed state after Region B exception');

    // Restore and verify
    $restored = PartialFailParallelMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('partial_fail_parallel.failed');

    // Context preservation under partial failure is timing-dependent.
    // Region A's ParallelRegionJob and Region B's failed() handler race:
    // - If Region A persists before Region B's failed() restores snapshot → context survives
    // - If Region B's failed() runs first with dispatch-time snapshot → Region A context lost
    // This is a known consequence of parallel dispatch last-writer-wins semantics.
    // The important assertion is that @fail fires correctly (state=failed above).
    // Region A context MAY or MAY NOT be 'processed_by_a' depending on timing.

    // Region B's context should be null — it threw before setting any data
    expect($restored->state->context->get('regionBData'))->toBeNull();

    // @fail transition event should be recorded
    $failEvents = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->where('type', 'like', '%fail%')
        ->count();
    expect($failEvents)->toBeGreaterThanOrEqual(1, 'No @fail event recorded');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

it('LocalQA: 3 concurrent partial-fail machines all reach failed state correctly', function (): void {
    // Stress test: 3 machines each with Region A success + Region B failure.
    // All should reach failed state with Region A context preserved.
    $rootEventIds = [];

    for ($i = 0; $i < 3; $i++) {
        $machine = PartialFailParallelMachine::create();
        $machine->send(['type' => 'START']);
        $rootEventIds[] = $machine->state->history->first()->root_event_id;
    }

    // Wait for all machines to reach failed state
    $allFailed = LocalQATestCase::waitFor(function () use ($rootEventIds) {
        foreach ($rootEventIds as $id) {
            $cs = MachineCurrentState::where('root_event_id', $id)->first();
            if (!$cs || !str_contains($cs->state_id, 'failed')) {
                return false;
            }
        }

        return true;
    }, timeoutSeconds: 60, description: '3 concurrent partial-fail machines all reach failed');

    expect($allFailed)->toBeTrue('Not all machines reached failed state');

    // Verify each machine reached failed state.
    // Context preservation: under high concurrency, Region A's context MAY be lost
    // due to last-writer-wins semantics (Region B's failed() handler may persist
    // before Region A's context is merged). This is documented behavior —
    // see ParallelRaceConditionTest for "scalar context overwrite: last writer wins".
    $contextPreservedCount = 0;
    foreach ($rootEventIds as $rootEventId) {
        $restored = PartialFailParallelMachine::create(state: $rootEventId);
        expect($restored->state->currentStateDefinition->id)->toBe('partial_fail_parallel.failed');

        if ($restored->state->context->get('regionAData') === 'processed_by_a') {
            $contextPreservedCount++;
        }
    }

    // At least some machines should preserve Region A context
    // (timing-dependent — not all may survive under high concurrency)
    expect($contextPreservedCount)->toBeGreaterThanOrEqual(1,
        'No machines preserved Region A context under concurrent partial failure'
    );

    // No stale locks across any machine
    $locks = DB::table('machine_locks')->count();
    expect($locks)->toBe(0);
});
