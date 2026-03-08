<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchWithFailMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

it('single region job failure leaves machine in parallel state when no onFail', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Region A succeeds
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working_a',
    ))->handle();

    // Region B "fails" (no retry, just don't run handle)
    // Machine stays in parallel state with partial progress
    $restored = ParallelDispatchMachine::create(state: $rootEventId);

    expect($restored->state->isInParallelState())->toBeTrue();
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    expect($restored->state->context->get('region_b_result'))->toBeNull();
});

it('job failure does NOT corrupt other region state', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Region A succeeds first
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail.processing.region_a',
        initialStateId: 'parallel_fail.processing.region_a.working_a',
    ))->handle();

    // Verify A's context persisted
    $afterA = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($afterA->state->context->get('region_a_result'))->toBe('processed_by_a');

    // Region B fails
    $jobB = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail.processing.region_b',
        initialStateId: 'parallel_fail.processing.region_b.working_b',
    );
    $jobB->failed(new \RuntimeException('Region B API timeout'));

    // Machine transitioned to error, but region A's context is preserved
    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_fail.error');
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
});

it('all region jobs fail leaves machine in error state with onFail', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Region A fails
    $jobA = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail.processing.region_a',
        initialStateId: 'parallel_fail.processing.region_a.working_a',
    );
    $jobA->failed(new \RuntimeException('Region A failure'));

    // Machine in error state
    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_fail.error');

    // Region B also fails — should no-op (machine already left parallel)
    $jobB = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail.processing.region_b',
        initialStateId: 'parallel_fail.processing.region_b.working_b',
    );
    $jobB->failed(new \RuntimeException('Region B failure'));

    // Still in error state
    $final = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_fail.error');
});
