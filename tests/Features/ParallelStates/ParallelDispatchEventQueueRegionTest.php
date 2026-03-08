<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchWithRaiseMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

// ============================================================
// Bead: event-machine-1egd — region entry action raises
// ============================================================

it('region entry action raises single event → captured and processed', function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);

    // Sequential mode: entry action raises REGION_A_PROCESSED → auto-transitions to finished_a
    $machine = ParallelDispatchWithRaiseMachine::create();

    expect($machine->state->context->get('region_a_result'))->toBe('processed_by_a');
    // Region A should have transitioned to finished_a via raised event
    expect($machine->state->value)->toContain('parallel_raise.processing.region_a.finished_a');
});

it('region entry action raises event in dispatch mode → processed by job', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Job A runs entry action which raises REGION_A_PROCESSED
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_raise.processing.region_a',
        initialStateId: 'parallel_raise.processing.region_a.working_a',
    ))->handle();

    $restored = ParallelDispatchWithRaiseMachine::create(state: $rootEventId);
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    // Raised event should have transitioned region A to finished_a
    expect($restored->state->value)->toContain('parallel_raise.processing.region_a.finished_a');
});

it('region entry action raises NO events → context-only update works', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    // ParallelDispatchMachine entry actions only set context, no raise
    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working_a',
    ))->handle();

    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    // Region stays at initial (no raised event to transition)
    expect($restored->state->value)->toContain('parallel_dispatch.processing.region_a.working_a');
});

it('two regions raise different events → each job processes its own', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Job A raises REGION_A_PROCESSED (transitions region A to finished_a)
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_raise.processing.region_a',
        initialStateId: 'parallel_raise.processing.region_a.working_a',
    ))->handle();

    // Job B does NOT raise events (RegionBEntryAction only sets context)
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_raise.processing.region_b',
        initialStateId: 'parallel_raise.processing.region_b.working_b',
    ))->handle();

    $restored = ParallelDispatchWithRaiseMachine::create(state: $rootEventId);

    // Region A transitioned (via raised event)
    expect($restored->state->value)->toContain('parallel_raise.processing.region_a.finished_a');
    // Region B stayed at initial (no raised event)
    expect($restored->state->value)->toContain('parallel_raise.processing.region_b.working_b');

    // Both contexts merged
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    expect($restored->state->context->get('region_b_result'))->toBe('processed_by_b');
});
