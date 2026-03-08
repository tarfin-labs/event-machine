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

it('region entry action raises single event → captured and processed', function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);

    // Sequential mode: entry action raises REGION_A_PROCESSED → auto-transitions to finished
    $machine = ParallelDispatchWithRaiseMachine::create();

    expect($machine->state->context->get('region_a_result'))->toBe('processed_by_a');
    // Region A should have transitioned to finished via raised event
    expect($machine->state->value)->toContain('parallel_dispatch_with_raise.processing.region_a.finished');
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
        regionId: 'parallel_dispatch_with_raise.processing.region_a',
        initialStateId: 'parallel_dispatch_with_raise.processing.region_a.working',
    ))->handle();

    $restored = ParallelDispatchWithRaiseMachine::create(state: $rootEventId);
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    // Raised event should have transitioned region A to finished
    expect($restored->state->value)->toContain('parallel_dispatch_with_raise.processing.region_a.finished');
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
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    ))->handle();

    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    // Region stays at initial (no raised event to transition)
    expect($restored->state->value)->toContain('parallel_dispatch.processing.region_a.working');
});

it('two regions raise different events → each job processes its own', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Job A raises REGION_A_PROCESSED (transitions region A to finished)
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_raise.processing.region_a',
        initialStateId: 'parallel_dispatch_with_raise.processing.region_a.working',
    ))->handle();

    // Job B does NOT raise events (RegionBEntryAction only sets context)
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_raise.processing.region_b',
        initialStateId: 'parallel_dispatch_with_raise.processing.region_b.working',
    ))->handle();

    $restored = ParallelDispatchWithRaiseMachine::create(state: $rootEventId);

    // Region A transitioned (via raised event)
    expect($restored->state->value)->toContain('parallel_dispatch_with_raise.processing.region_a.finished');
    // Region B stayed at initial (no raised event)
    expect($restored->state->value)->toContain('parallel_dispatch_with_raise.processing.region_b.working');

    // Both contexts merged
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    expect($restored->state->context->get('region_b_result'))->toBe('processed_by_b');
});
