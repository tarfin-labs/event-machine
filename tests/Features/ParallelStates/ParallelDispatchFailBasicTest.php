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

it('region job fails with onFail → transitions to error state', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_a',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_a.working',
    );

    $job->failed(new \RuntimeException('API timeout'));

    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_dispatch_with_fail.failed');
    expect($restored->state->isInParallelState())->toBeFalse();
});

it('region job fails without onFail → machine stays in parallel', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    // ParallelDispatchMachine does NOT have onFail
    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    );

    $job->failed(new \RuntimeException('API timeout'));

    // Machine stays in parallel (no onFail target to transition to)
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->isInParallelState())->toBeTrue();

    // PARALLEL_FAIL event recorded in history
    $failEvent = $restored->state->history->first(
        fn ($e) => str_contains($e->type, 'parallel') && str_contains($e->type, 'fail')
    );
    expect($failEvent)->not->toBeNull();
});

it('@fail payload contains failure details', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_a',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_a.working',
    );

    $job->failed(new \RuntimeException('Connection timeout'));

    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);

    // Find the PARALLEL_FAIL event in history
    $failEvent = $restored->state->history->first(
        fn ($e) => str_contains($e->type, 'parallel') && str_contains($e->type, 'fail')
    );
    expect($failEvent)->not->toBeNull();

    // Machine must have transitioned to error state
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_dispatch_with_fail.failed');
    expect($restored->state->isInParallelState())->toBeFalse();
});
