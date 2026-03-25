<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

it('both jobs finishing sequentially succeed with consistent state', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Job A completes
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    ))->handle();

    // Job B completes (acquires lock after A releases)
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working',
    ))->handle();

    // Both results merged
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->context->get('regionAResult'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBResult'))->toBe('processed_by_b');
});

it('external event sent after jobs complete transitions correctly', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Both jobs complete
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    ))->handle();

    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working',
    ))->handle();

    // External events transition regions to final
    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_A_DONE');

    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_B_DONE');

    // onDone fires
    $final = ParallelDispatchMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_dispatch.completed');
});

it('external event before all jobs complete does not break state', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Only Job A completes
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    ))->handle();

    // External event transitions region A to final
    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_A_DONE');

    // Machine still in parallel (region B not done)
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->isInParallelState())->toBeTrue();

    // Job B then completes
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working',
    ))->handle();

    // Region B done event
    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_B_DONE');

    $final = ParallelDispatchMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_dispatch.completed');
});

it('job no-ops when machine already transitioned out of parallel by external event', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Job A runs first
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    ))->handle();

    // External events transition to completed (both regions done events + onDone)
    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_A_DONE');

    // Manually run region B entry and send done
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working',
    ))->handle();

    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_B_DONE');

    // Machine is now in completed state
    $final = ParallelDispatchMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_dispatch.completed');

    // Late-arriving job B should no-op (machine no longer in parallel)
    $lateJob = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working',
    );
    $lateJob->handle();

    // Still in completed state
    $stillFinal = ParallelDispatchMachine::create(state: $rootEventId);
    expect($stillFinal->state->currentStateDefinition->id)->toBe('parallel_dispatch.completed');
});
