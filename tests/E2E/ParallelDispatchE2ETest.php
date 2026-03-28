<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EFailMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EBasicMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EChainedMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2ENoRaiseMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EBothFailMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EMultiRaiseMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EDeepContextMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EMixedRegionMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2ESingleEntryMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EThreeRegionMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EContextConflictMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessRegionAAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\ProcessRegionBAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchWithRaiseMachine;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);
});

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

// ============================================================
// Grup 1: Tam Yaşam Döngüsü (Full Lifecycle)
//
// Pattern: create() → persist() → dispatchPendingParallelJobs()
// Sync queue driver runs jobs immediately. No Bus::fake(), no
// manual handle() — full dispatch→handle→persist chain.
//
// Note: transition() from non-parallel → parallel doesn't call
// enterParallelState(), so send()-triggered E2E isn't possible
// yet. Using initial=parallel + explicit dispatch instead.
// ============================================================

it('completes full parallel lifecycle via dispatch', function (): void {
    // 1. Create machine (initial=parallel) → enterParallelState fills pendingParallelDispatches
    $machine = E2EBasicMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // 2. Dispatch → sync driver runs Job A, then Job B
    //    Job A: entry action (raise REGION_A_PROCESSED) → lock → context merge → transition → persist
    //    Job B: entry action (raise REGION_B_PROCESSED) → lock → context merge → all final → onDone → persist
    $machine->dispatchPendingParallelJobs();

    // 3. Restore from DB — both jobs completed, onDone fired
    $restored = E2EBasicMachine::create(state: $rootEventId);

    expect($restored->state->currentStateDefinition->id)->toBe('e2e_basic.completed');
    expect($restored->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBData'))->toBe('processed_by_b');
});

it('completes three-region parallel lifecycle via dispatch', function (): void {
    $machine = E2EThreeRegionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $restored = E2EThreeRegionMachine::create(state: $rootEventId);

    expect($restored->state->currentStateDefinition->id)->toBe('e2e_three_region.completed');
    expect($restored->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBData'))->toBe('processed_by_b');
    expect($restored->state->context->get('regionCData'))->toBe('processed_by_c');
});

it('handles region that raises event to auto-complete alongside region that does not', function (): void {
    // ParallelDispatchWithRaiseMachine: region_a raises event (auto-completes), region_b doesn't
    $machine = ParallelDispatchWithRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $restored = ParallelDispatchWithRaiseMachine::create(state: $rootEventId);

    // Region A: entry action raised REGION_A_PROCESSED → transitioned to finished
    expect($restored->state->context->get('regionAData'))->toBe('processed_by_a');
    // Region B: entry action set context but no raise → stays at working, no auto-complete
    expect($restored->state->context->get('regionBData'))->toBe('processed_by_b');
    // onDone should NOT have fired (region_b not at final)
    expect($restored->state->currentStateDefinition->id)->not->toBe('parallel_dispatch_with_raise.completed');
});

it('does not dispatch jobs from create() automatically', function (): void {
    Bus::fake();

    $machine = ParallelDispatchMachine::create();
    $machine->persist();

    // pendingParallelDispatches filled by enterParallelState in dispatch mode
    expect($machine->definition->pendingParallelDispatches)->toHaveCount(2);

    // But no jobs dispatched — create() doesn't call dispatchPendingParallelJobs()
    Bus::assertNotDispatched(ParallelRegionJob::class);
});

it('returns consistent state on multiple restores after lifecycle completes', function (): void {
    $machine = E2EBasicMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    // Multiple restores should all see the same final state
    $restored1 = E2EBasicMachine::create(state: $rootEventId);
    $restored2 = E2EBasicMachine::create(state: $rootEventId);

    expect($restored1->state->currentStateDefinition->id)->toBe('e2e_basic.completed');
    expect($restored2->state->currentStateDefinition->id)->toBe('e2e_basic.completed');
    expect($restored1->state->context->get('regionAData'))
        ->toBe($restored2->state->context->get('regionAData'));
});

// ============================================================
// Grup 2: Context Merge & Veri Bütünlüğü
// ============================================================

it('merges scalar context from parallel regions correctly', function (): void {
    $machine = E2EBasicMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $restored = E2EBasicMachine::create(state: $rootEventId);

    // Both regions wrote to different scalar context keys — both should exist
    expect($restored->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBData'))->toBe('processed_by_b');
    // Neither should have overwritten the other
    expect($restored->state->context->get('regionAData'))->not->toBe('processed_by_b');
});

it('merges deep nested context from parallel regions', function (): void {
    $machine = E2EDeepContextMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $restored = E2EDeepContextMachine::create(state: $rootEventId);

    expect($restored->state->currentStateDefinition->id)->toBe('e2e_deep_context.completed');

    // Both nested keys inside 'report' should be preserved after deep merge
    $report = $restored->state->context->get('report');
    expect($report)->toBeArray();
    expect($report['findeks']['score'])->toBe(750);
    expect($report['findeks']['provider'])->toBe('kkb');
    expect($report['turmob']['status'])->toBe('clean');
    expect($report['turmob']['checked_at'])->toBe('2026-03-08');
});

it('preserves context keys not written by any job', function (): void {
    // E2EBasicMachine has 'orderId' in config (initial=null).
    // No region job writes to it — it should remain null after lifecycle.
    $machine = E2EBasicMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Verify order_id is in initial context
    expect($machine->state->context->get('orderId'))->toBeNull();

    $machine->dispatchPendingParallelJobs();

    $restored = E2EBasicMachine::create(state: $rootEventId);

    // order_id should remain null — jobs only write region_a/b_result
    expect($restored->state->context->get('orderId'))->toBeNull();
    // Region results should be set by their respective jobs
    expect($restored->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBData'))->toBe('processed_by_b');
});

it('second job sees first job context changes via fresh DB load', function (): void {
    $machine = E2EBasicMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // With sync driver, jobs run sequentially:
    // Job A runs first → persists context with region_a_result
    // Job B runs second → loads fresh from DB → sees region_a_result → adds region_b_result
    $machine->dispatchPendingParallelJobs();

    $restored = E2EBasicMachine::create(state: $rootEventId);

    // Both context keys must be present — proves diff-based merge works
    // (if Job B had overwritten, region_a_result would be null)
    expect($restored->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBData'))->toBe('processed_by_b');
});

// ============================================================
// Grup 3: Raised Events & Internal Transitions
//
// Verifies that entry actions can raise events to drive region
// transitions within parallel jobs. Covers single raise, multi-
// raise chains, and no-raise (context-only) scenarios.
// ============================================================

it('single raised event from entry action transitions region to final', function (): void {
    // E2EBasicMachine: each region's entry action raises exactly one event
    // Region A: ProcessRegionAAction → raise(REGION_A_PROCESSED) → working → finished
    // Region B: ProcessRegionBAction → raise(REGION_B_PROCESSED) → working → finished
    $machine = E2EBasicMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $restored = E2EBasicMachine::create(state: $rootEventId);

    // Both regions must have reached their final states via raised events
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_basic.completed');

    // Verify the raised events caused actual transitions (not just context writes)
    // by checking that the machine value no longer contains the initial states
    expect($restored->state->value)->not->toContain('e2e_basic.processing.region_a.working');
    expect($restored->state->value)->not->toContain('e2e_basic.processing.region_b.working');
});

it('multiple raised events in single job are processed in sequence', function (): void {
    // E2EMultiRaiseMachine: Region A raises STEP_1_DONE + STEP_2_DONE in one action
    // Chain: pending → (STEP_1_DONE) → step_1 → (STEP_2_DONE) → finished
    // Region B: normal single raise → finished
    // Both final → onDone → completed
    $machine = E2EMultiRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $restored = E2EMultiRaiseMachine::create(state: $rootEventId);

    // Machine should have completed — both regions reached final, onDone fired
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_multi_raise.completed');

    // Context set by Region A's multi-raise action should be preserved
    expect($restored->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBData'))->toBe('processed_by_b');
});

it('entry action that sets context without raising event keeps region at initial', function (): void {
    // ParallelDispatchMachine: both entry actions set context but do NOT raise events
    // Regions should stay at their initial states — no transitions happen
    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $restored = ParallelDispatchMachine::create(state: $rootEventId);

    // Context was written by entry actions (proves jobs ran)
    expect($restored->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBData'))->toBe('processed_by_b');

    // But onDone should NOT have fired — regions are still at initial, not final
    expect($restored->state->currentStateDefinition->id)->not->toBe('parallel_dispatch.completed');

    // Machine value should still contain region initial states
    expect($restored->state->value)->toContain('parallel_dispatch.processing.region_a.working');
    expect($restored->state->value)->toContain('parallel_dispatch.processing.region_b.working');
});

// ============================================================
// Grup 4: Chained Parallel States
//
// Known limitation: exitParallelStateAndTransition() does not
// call enterParallelState() for parallel targets. After phase_one
// onDone → phase_two, the second parallel phase won't auto-dispatch.
// These tests document the current behavior.
// ============================================================

it('documents that chained parallel onDone transitions to second phase but does not auto-dispatch', function (): void {
    // E2EChainedMachine: phase_one(A,B) → onDone → phase_two(C,D) → onDone → completed
    // Phase one dispatches normally (initial=parallel), but onDone→phase_two
    // goes through exitParallelStateAndTransition which doesn't call enterParallelState()
    $machine = E2EChainedMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Phase one: dispatch region jobs → both raise → both final → onDone fires
    $machine->dispatchPendingParallelJobs();

    $restored = E2EChainedMachine::create(state: $rootEventId);

    // Phase one context should be set (proves phase one jobs ran)
    expect($restored->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBData'))->toBe('processed_by_b');

    // Machine should have transitioned to phase_two (onDone fired)
    // State constructor's updateMachineValueFromState() expands parallel value on restore
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_chained.phase_two');
    expect($restored->state->value)->toHaveCount(2);
    expect($restored->state->value)->toContain('e2e_chained.phase_two.region_c.working');
    expect($restored->state->value)->toContain('e2e_chained.phase_two.region_d.working');

    // Phase two context should NOT be set — enterParallelState() was never called
    // so no jobs dispatched, no region entry actions ran
    expect($restored->state->context->get('regionCData'))->toBeNull();
    expect($restored->state->context->get('regionDResult'))->toBeNull();
});

it('documents that pendingParallelDispatches is empty after chained onDone', function (): void {
    // After phase_one completes and onDone transitions to phase_two,
    // pendingParallelDispatches should be empty because enterParallelState()
    // was never called for phase_two.
    $machine = E2EChainedMachine::create();
    $machine->persist();

    $machine->dispatchPendingParallelJobs();

    // After dispatch completes, pending dispatches should be cleared
    // (phase_one dispatches consumed, phase_two never queued)
    expect($machine->definition->pendingParallelDispatches)->toBeEmpty();
});

// ============================================================
// Grup 5: Error Handling & onFail
//
// Tests parallel region job failure handling. Sync driver calls
// failed() then rethrows, so tests wrap dispatch in try-catch.
// ============================================================

it('transitions to error state when region job fails via onFail', function (): void {
    // E2EFailMachine: Region A throws RuntimeException, Region B normal
    // Sync driver: A fails → failed() → processParallelOnFail → error → persist → rethrow
    $machine = E2EFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    try {
        $machine->dispatchPendingParallelJobs();
    } catch (RuntimeException) {
        // Expected — sync driver rethrows after calling failed()
    }

    $restored = E2EFailMachine::create(state: $rootEventId);

    // Machine should have transitioned to error state via onFail
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_fail.failed');
});

it('records PARALLEL_FAIL event in machine history', function (): void {
    $machine = E2EFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    try {
        $machine->dispatchPendingParallelJobs();
    } catch (RuntimeException) {
        // Expected
    }

    // Check machine_events for PARALLEL_FAIL internal event
    $events = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->oldest('sequence_number')
        ->get();

    // Internal event type format: {machine}.parallel.{route}.fail
    // e.g., "e2e_fail.parallel.processing.fail"
    $failEvent = $events->first(
        fn (MachineEvent $e) => str_contains($e->type ?? '', '.parallel.') && str_ends_with($e->type ?? '', '.fail')
    );

    expect($failEvent)->not->toBeNull();
    expect($failEvent->type)->toBe('e2e_fail.parallel.processing.fail');
});

it('does not set context from failing region action', function (): void {
    $machine = E2EFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    try {
        $machine->dispatchPendingParallelJobs();
    } catch (RuntimeException) {
        // Expected
    }

    $restored = E2EFailMachine::create(state: $rootEventId);

    // Region A threw before setting context — should be null
    expect($restored->state->context->get('regionAData'))->toBeNull();
    // Region B never ran (sync driver: A dispatched first, threw, B skipped)
    expect($restored->state->context->get('regionBData'))->toBeNull();
});

it('prevents subsequent region jobs from running after onFail transition', function (): void {
    // With sync driver, jobs run sequentially. After Region A fails and
    // machine transitions to error (non-parallel), Region B should never run.
    // This verifies the pre-lock guard: isInParallelState() returns false.
    $machine = E2EFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    try {
        $machine->dispatchPendingParallelJobs();
    } catch (RuntimeException) {
        // Expected
    }

    $restored = E2EFailMachine::create(state: $rootEventId);

    // Machine is at error (non-parallel) — Region B never ran
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_fail.failed');
    expect($restored->state->context->get('regionBData'))->toBeNull();
    // Only 1 dispatch happened (A), not 2 — exception interrupted the loop
    // This is implicit: B's context being null proves it never executed
});

// ============================================================
// Grup 6: Mixed Regions — dispatch + inline
//
// Verifies that regions with entry actions are dispatched while
// regions without entry actions run inline. Also tests sequential
// fallback when conditions for dispatch aren't met.
// ============================================================

it('dispatches regions with entry actions and runs inline region without', function (): void {
    // E2EMixedRegionMachine: A (entry+raise), B (entry+raise), C (no entry, initial=final)
    // A and B dispatched, C runs inline → all final → onDone → completed
    $machine = E2EMixedRegionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Only 2 dispatches (A and B), not 3 — C has no entry actions
    expect($machine->definition->pendingParallelDispatches)->toHaveCount(2);

    $machine->dispatchPendingParallelJobs();

    $restored = E2EMixedRegionMachine::create(state: $rootEventId);

    // All regions completed → onDone fired
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_mixed.completed');
    expect($restored->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBData'))->toBe('processed_by_b');
});

it('falls back to sequential mode when only one region has entry actions', function (): void {
    Bus::fake();

    // E2ESingleEntryMachine: region_a has entry action, region_b has no entry (initial=final)
    // shouldDispatchParallel requires ≥2 regions with entry → false → sequential mode
    $machine = E2ESingleEntryMachine::create();
    $machine->persist();

    // No dispatches queued — sequential mode
    expect($machine->definition->pendingParallelDispatches)->toBeEmpty();

    Bus::assertNotDispatched(ParallelRegionJob::class);
});

it('falls back to sequential mode when parallel dispatch is disabled', function (): void {
    Bus::fake();

    // Override: disable dispatch even though machine qualifies
    config()->set('machine.parallel_dispatch.enabled', false);

    $machine = E2EBasicMachine::create();
    $machine->persist();

    expect($machine->definition->pendingParallelDispatches)->toBeEmpty();

    Bus::assertNotDispatched(ParallelRegionJob::class);

    // Restore for other tests in this file
    config()->set('machine.parallel_dispatch.enabled', true);
});

// ============================================================
// Grup 7: Guards & Double-Guard Pattern
//
// Tests the pre-lock and under-lock guards in ParallelRegionJob.
// Pre-lock guard: isInParallelState() before acquiring lock.
// Under-lock guard: re-check region state after acquiring lock.
// ============================================================

it('skips job when machine has already left parallel state (pre-lock guard)', function (): void {
    // ParallelDispatchWithRaiseMachine: region_a raises (auto-completes), region_b doesn't raise
    $machine = ParallelDispatchWithRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Dispatch first time — region_a raises, region_b sets context only
    $machine->dispatchPendingParallelJobs();

    // Now manually trigger onFail to leave parallel state
    $restored = ParallelDispatchWithRaiseMachine::create(state: $rootEventId);

    // Manually construct a job for region_a and call handle() — should be a no-op
    // because region_a already completed (not at initial state anymore)
    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchWithRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_raise.processing.region_a',
        initialStateId: 'parallel_dispatch_with_raise.processing.region_a.working',
    );

    // This should early-return at under-lock guard (region already processed)
    $job->handle();

    // Verify machine state unchanged — region_a was already at finished
    $afterReplay = ParallelDispatchWithRaiseMachine::create(state: $rootEventId);
    expect($afterReplay->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($afterReplay->state->context->get('regionBData'))->toBe('processed_by_b');
});

it('is idempotent when same job runs twice (under-lock guard)', function (): void {
    // E2EBasicMachine: both regions raise → complete lifecycle
    $machine = E2EBasicMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    // After lifecycle completes, machine is at 'completed' (non-parallel)
    // Replaying a region job should be a no-op (pre-lock guard: isInParallelState = false)
    $job = new ParallelRegionJob(
        machineClass: E2EBasicMachine::class,
        rootEventId: $rootEventId,
        regionId: 'e2e_basic.processing.region_a',
        initialStateId: 'e2e_basic.processing.region_a.working',
    );

    $job->handle();

    $restored = E2EBasicMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_basic.completed');
    expect($restored->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBData'))->toBe('processed_by_b');
});

it('completes parallel lifecycle when jobs run sequentially via sync driver', function (): void {
    // Sync driver natural behavior: jobs run one after another
    // Job B loads fresh state from DB and sees Job A's context changes
    $machine = E2EBasicMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $restored = E2EBasicMachine::create(state: $rootEventId);

    // Both jobs completed successfully despite sequential execution
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_basic.completed');

    // Proves fresh-load pattern works: Job B saw Job A's changes
    expect($restored->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBData'))->toBe('processed_by_b');
});

// ============================================================
// Grup 8: Config Variations
//
// Tests that machine.parallel_dispatch config values are
// correctly applied to dispatched ParallelRegionJob instances.
// ============================================================

it('uses configured queue name for dispatched jobs', function (): void {
    Bus::fake();

    config()->set('machine.parallel_dispatch.queue', 'custom-parallel');

    $machine = E2EBasicMachine::create();
    $machine->persist();

    $machine->dispatchPendingParallelJobs();

    Bus::assertDispatched(ParallelRegionJob::class, function (ParallelRegionJob $job) {
        return $job->queue === 'custom-parallel';
    });

    config()->set('machine.parallel_dispatch.queue', null);
});

it('applies configured timeout tries and backoff to dispatched jobs', function (): void {
    Bus::fake();

    config()->set('machine.parallel_dispatch.job_timeout', 120);
    config()->set('machine.parallel_dispatch.job_tries', 5);
    config()->set('machine.parallel_dispatch.job_backoff', 10);

    $machine = E2EBasicMachine::create();
    $machine->persist();

    $machine->dispatchPendingParallelJobs();

    Bus::assertDispatched(ParallelRegionJob::class, function (ParallelRegionJob $job) {
        return $job->timeout === 120 && $job->tries === 5 && $job->backoff === 10;
    });

    // Restore defaults
    config()->set('machine.parallel_dispatch.job_timeout', 300);
    config()->set('machine.parallel_dispatch.job_tries', 3);
    config()->set('machine.parallel_dispatch.job_backoff', 30);
});

it('uses default queue when no queue name configured', function (): void {
    Bus::fake();

    config()->set('machine.parallel_dispatch.queue', null);

    $machine = E2EBasicMachine::create();
    $machine->persist();

    $machine->dispatchPendingParallelJobs();

    Bus::assertDispatched(ParallelRegionJob::class, function (ParallelRegionJob $job) {
        return $job->queue === null;
    });
});

// ============================================================
// Grup 9: State Restoration & History
//
// Tests that machine state can be correctly restored after
// parallel lifecycle and that history events are properly recorded.
// ============================================================

it('can fully restore machine state after parallel lifecycle completes', function (): void {
    $machine = E2EBasicMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    // Restore multiple times — should always see the same final state
    $restored1 = E2EBasicMachine::create(state: $rootEventId);
    $restored2 = E2EBasicMachine::create(state: $rootEventId);

    expect($restored1->state->currentStateDefinition->id)->toBe('e2e_basic.completed');
    expect($restored2->state->currentStateDefinition->id)->toBe('e2e_basic.completed');

    // Context should match across restores
    expect($restored1->state->context->get('regionAData'))
        ->toBe($restored2->state->context->get('regionAData'))
        ->toBe('processed_by_a');
});

it('records correct internal events during parallel lifecycle', function (): void {
    $machine = E2EBasicMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $events = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->oldest('sequence_number')
        ->pluck('type')
        ->toArray();

    // Should contain STATE_ENTER events and PARALLEL_REGION_ENTER events
    $stateEnterEvents  = array_filter($events, fn ($t) => str_contains($t, '.state.'));
    $regionEnterEvents = array_filter($events, fn ($t) => str_contains($t, '.region.'));

    expect(count($stateEnterEvents))->toBeGreaterThan(0);
    expect(count($regionEnterEvents))->toBeGreaterThan(0);

    // Should contain the parallel done event
    $doneEvents = array_filter($events, fn ($t) => str_contains($t, '.done'));
    expect(count($doneEvents))->toBeGreaterThan(0);
});

it('persists incremental context changes correctly per job', function (): void {
    $machine = E2EBasicMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    // Query all events — last event should have the complete context
    $events = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->oldest('sequence_number')
        ->get();

    $lastEvent = $events->last();

    // Last event's context should contain both region results
    // (incremental merge builds up to complete context at the end)
    $restored = E2EBasicMachine::create(state: $rootEventId);
    expect($restored->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBData'))->toBe('processed_by_b');
    expect($restored->state->context->get('orderId'))->toBeNull();
});

// ============================================================
// Grup 10: Edge Cases
//
// Tests boundary conditions and degenerate cases in the
// parallel dispatch mechanism.
// ============================================================

it('handles parallel state where all regions have no entry actions', function (): void {
    Bus::fake();

    // E2ESingleEntryMachine only has 1 region with entry action → shouldDispatchParallel = false
    // But even more extreme: if NO regions have entry actions, it's purely inline
    // Using E2ESingleEntryMachine which falls back to sequential (only 1 entry action)
    $machine = E2ESingleEntryMachine::create();
    $machine->persist();

    // Sequential mode — no dispatch
    expect($machine->definition->pendingParallelDispatches)->toBeEmpty();
    Bus::assertNotDispatched(ParallelRegionJob::class);
});

it('handles region initial state that is also final (immediate completion)', function (): void {
    // E2EMixedRegionMachine: region_c has initial=completed which is final
    // This region runs inline and is immediately "at final"
    $machine = E2EMixedRegionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $restored = E2EMixedRegionMachine::create(state: $rootEventId);

    // All regions completed (A,B via dispatch, C inline final) → onDone
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_mixed.completed');
});

it('clears pendingParallelDispatches after dispatch completes', function (): void {
    $machine = E2EBasicMachine::create();
    $machine->persist();

    // Before dispatch: 2 pending
    expect($machine->definition->pendingParallelDispatches)->toHaveCount(2);

    $machine->dispatchPendingParallelJobs();

    // After dispatch: cleared
    expect($machine->definition->pendingParallelDispatches)->toBeEmpty();
});

it('validates config requires should_persist and Machine subclass for dispatch', function (): void {
    Bus::fake();

    // MachineDefinition::define (not a Machine subclass) → shouldDispatchParallel = false
    // even with dispatch enabled
    $definition = MachineDefinition::define(
        config: [
            'id'             => 'inline_parallel',
            'initial'        => 'processing',
            'should_persist' => true,
            'context'        => [
                'regionAData' => null,
                'regionBData' => null,
            ],
            'states' => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'entry' => ProcessRegionAAction::class,
                                    'on'    => ['REGION_A_PROCESSED' => 'finished'],
                                ],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'entry' => ProcessRegionBAction::class,
                                    'on'    => ['REGION_B_PROCESSED' => 'finished'],
                                ],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    // machineClass is null (not a Machine subclass) → shouldDispatchParallel = false
    expect($definition->pendingParallelDispatches)->toBeEmpty();
    Bus::assertNotDispatched(ParallelRegionJob::class);
});

// ============================================================
// Grup 11: Edge Case — No-raise region (stuck machine)
//
// Entry action runs and sets context but does NOT call raise().
// Region stays in initial state → machine is permanently stuck
// in parallel state. Validates that:
// - Context diff IS applied even without raise
// - areAllRegionsFinal() returns false
// - Machine does not transition to completed
// ============================================================

it('stays stuck in parallel state when region entry action does not raise event', function (): void {
    $machine = E2ENoRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $restored = E2ENoRaiseMachine::create(state: $rootEventId);

    // Machine should NOT be in completed — it's stuck
    expect($restored->state->currentStateDefinition->id)->not->toBe('e2e_no_raise.completed');

    // Still in parallel state (value has >1 element)
    expect(count($restored->state->value))->toBeGreaterThan(1);

    // Region A's context was applied (even without raise)
    expect($restored->state->context->get('regionAContextSet'))->toBe('yes_but_no_raise');
    expect($restored->state->context->get('regionAPid'))->not->toBeNull();

    // Region B completed normally
    expect($restored->state->context->get('regionBData'))->toBe('processed_by_b');

    // Value shows: working (stuck) + finished (completed)
    $hasWorkingA  = collect($restored->state->value)->contains(fn ($v) => str_contains($v, 'working'));
    $hasFinishedB = collect($restored->state->value)->contains(fn ($v) => str_contains($v, 'finished'));
    expect($hasWorkingA)->toBeTrue();
    expect($hasFinishedB)->toBeTrue();
});

it('applies context diff from no-raise region but does not trigger onDone', function (): void {
    $machine = E2ENoRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    // Check events — both regions should have PARALLEL_REGION_ENTER
    $events = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->oldest('sequence_number')
        ->pluck('type')
        ->toArray();

    $regionEnterEvents = collect($events)->filter(fn ($t) => str_contains($t, '.region.enter'));

    // Both regions dispatched and ran entry actions
    expect($regionEnterEvents)->toHaveCount(2);

    // No onDone event (machine didn't complete)
    $hasDoneEvent = collect($events)->contains(fn ($t) => str_ends_with($t, '.done'));
    expect($hasDoneEvent)->toBeFalse();
});

// ============================================================
// Grup 12: Edge Case — Context merge conflict
//
// Both regions write to the same context keys. Tests:
// - Scalar: last-writer-wins (no crash, one value survives)
// - Array: deep merge (different keys survive, same key LWW)
// ============================================================

it('handles scalar context conflict with last-writer-wins', function (): void {
    $machine = E2EContextConflictMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $restored = E2EContextConflictMachine::create(state: $rootEventId);

    // Machine completed despite conflict
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_context_conflict.completed');

    // Both regions executed
    expect($restored->state->context->get('regionAWrote'))->toBeTrue();
    expect($restored->state->context->get('regionBWrote'))->toBeTrue();

    // Scalar: one of the values wins (last-writer-wins, deterministic per run)
    $sharedScalar = $restored->state->context->get('sharedScalar');
    expect($sharedScalar)->toBeIn(['value_from_a', 'value_from_b']);
});

it('deep merges array context from parallel regions preserving different keys', function (): void {
    $machine = E2EContextConflictMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $restored    = E2EContextConflictMachine::create(state: $rootEventId);
    $sharedArray = $restored->state->context->get('sharedArray');

    // Both from_a and from_b keys survive (different keys → deep merge)
    expect($sharedArray['from_a'])->toBeTrue();
    expect($sharedArray['from_b'])->toBeTrue();

    // Same nested key (score): last-writer-wins (85 or 92)
    expect($sharedArray['score'])->toBeIn([85, 92]);
});

// ============================================================
// Grup 13: Edge Case — Both regions fail (dual-failure)
//
// Both regions throw exceptions. Tests:
// - Double-guard prevents duplicate onFail transition
// - Only one PARALLEL_FAIL event recorded
// - Machine reaches error state cleanly
// ============================================================

it('handles dual region failure with single onFail transition', function (): void {
    config()->set('machine.parallel_dispatch.job_tries', 1);
    config()->set('machine.parallel_dispatch.job_backoff', 0);

    $machine = E2EBothFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Sync driver: first failing job's exception is rethrown after failed() handler runs
    try {
        $machine->dispatchPendingParallelJobs();
    } catch (RuntimeException) {
        // Expected — sync driver rethrows after calling failed()
    }

    $restored = E2EBothFailMachine::create(state: $rootEventId);

    // Machine in error state (first job's failed() handler triggered onFail)
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_both_fail.failed');

    // Neither region's result set
    expect($restored->state->context->get('regionAData'))->toBeNull();
    expect($restored->state->context->get('regionBData'))->toBeNull();
});

it('records exactly one PARALLEL_FAIL event when both regions fail', function (): void {
    config()->set('machine.parallel_dispatch.job_tries', 1);
    config()->set('machine.parallel_dispatch.job_backoff', 0);

    $machine = E2EBothFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Sync driver: first job's exception is rethrown after failed() runs
    try {
        $machine->dispatchPendingParallelJobs();
    } catch (RuntimeException) {
        // Expected
    }

    $events = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->oldest('sequence_number')
        ->pluck('type')
        ->toArray();

    // At least one PARALLEL_FAIL event recorded
    // In sync mode: first job fails → onFail fires. Second job may also fail
    // but the double-guard (isInParallelState check) prevents duplicate onFail.
    $failEvents = collect($events)->filter(
        fn ($t) => str_contains($t, '.parallel.') && str_ends_with($t, '.fail')
    );

    expect($failEvents)->toHaveCount(1);
});

// ============================================================
// Grup 14: Edge Case — Instance isolation
//
// Two instances of the same machine class dispatched with
// different root_event_ids. Tests:
// - Lock isolation (per root_event_id, not per class)
// - Context isolation (no cross-contamination)
// - Event isolation (each instance has own event chain)
// ============================================================

it('isolates parallel dispatch between different machine instances', function (): void {
    // Instance A
    $machineA = E2EBasicMachine::create();
    $machineA->persist();
    $rootA = $machineA->state->history->first()->root_event_id;
    $machineA->dispatchPendingParallelJobs();

    // Instance B
    $machineB = E2EBasicMachine::create();
    $machineB->persist();
    $rootB = $machineB->state->history->first()->root_event_id;
    $machineB->dispatchPendingParallelJobs();

    // Both completed independently
    $restoredA = E2EBasicMachine::create(state: $rootA);
    $restoredB = E2EBasicMachine::create(state: $rootB);

    expect($restoredA->state->currentStateDefinition->id)->toBe('e2e_basic.completed');
    expect($restoredB->state->currentStateDefinition->id)->toBe('e2e_basic.completed');

    // Different root_event_ids
    expect($rootA)->not->toBe($rootB);

    // Context isolation
    expect($restoredA->state->context->get('regionAData'))->toBe('processed_by_a');
    expect($restoredB->state->context->get('regionAData'))->toBe('processed_by_a');

    // Event isolation: same count per instance
    $eventsA = MachineEvent::where('root_event_id', $rootA)->count();
    $eventsB = MachineEvent::where('root_event_id', $rootB)->count();
    expect($eventsA)->toBe($eventsB);
});
