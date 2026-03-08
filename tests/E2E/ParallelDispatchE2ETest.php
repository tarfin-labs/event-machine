<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EBasicMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EThreeRegionMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;
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
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    expect($restored->state->context->get('region_b_result'))->toBe('processed_by_b');
});

it('completes three-region parallel lifecycle via dispatch', function (): void {
    $machine = E2EThreeRegionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $restored = E2EThreeRegionMachine::create(state: $rootEventId);

    expect($restored->state->currentStateDefinition->id)->toBe('e2e_three_region.completed');
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    expect($restored->state->context->get('region_b_result'))->toBe('processed_by_b');
    expect($restored->state->context->get('region_c_result'))->toBe('processed_by_c');
});

it('handles region that raises event to auto-complete alongside region that does not', function (): void {
    // ParallelDispatchWithRaiseMachine: region_a raises event (auto-completes), region_b doesn't
    $machine = ParallelDispatchWithRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $machine->dispatchPendingParallelJobs();

    $restored = ParallelDispatchWithRaiseMachine::create(state: $rootEventId);

    // Region A: entry action raised REGION_A_PROCESSED → transitioned to finished_a
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    // Region B: entry action set context but no raise → stays at working_b, no auto-complete
    expect($restored->state->context->get('region_b_result'))->toBe('processed_by_b');
    // onDone should NOT have fired (region_b not at final)
    expect($restored->state->currentStateDefinition->id)->not->toBe('parallel_raise.completed');
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
    expect($restored1->state->context->get('region_a_result'))
        ->toBe($restored2->state->context->get('region_a_result'));
});
