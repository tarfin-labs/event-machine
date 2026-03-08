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

it('reconstructed machine starts with empty event queue', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Reconstruct machine (same as what ParallelRegionJob does)
    $restored = ParallelDispatchMachine::create(state: $rootEventId);

    // Event queue should be empty (not carried over from Phase 1)
    expect($restored->definition->eventQueue->count())->toBe(0);
});

it('each job captures only its own raised events', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Job A raises REGION_A_PROCESSED → only that event is processed
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_raise.processing.region_a',
        initialStateId: 'parallel_raise.processing.region_a.working_a',
    ))->handle();

    // Job B does not raise → no events captured
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_raise.processing.region_b',
        initialStateId: 'parallel_raise.processing.region_b.working_b',
    ))->handle();

    $restored = ParallelDispatchWithRaiseMachine::create(state: $rootEventId);

    // Region A transitioned (its raised event was processed), Region B stayed
    expect($restored->state->value)->toContain('parallel_raise.processing.region_a.finished_a');
    expect($restored->state->value)->toContain('parallel_raise.processing.region_b.working_b');
});
