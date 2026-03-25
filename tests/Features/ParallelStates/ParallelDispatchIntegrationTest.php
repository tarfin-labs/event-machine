<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchWithFailMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

it('full lifecycle: create → dispatch → jobs complete → onDone → next state', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    // 1. Create machine — entry actions deferred, pendingParallelDispatches populated
    $machine = ParallelDispatchMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    expect($machine->definition->pendingParallelDispatches)->toHaveCount(2);
    expect($machine->state->context->get('regionAResult'))->toBeNull();
    expect($machine->state->context->get('regionBResult'))->toBeNull();

    // 2. Simulate dispatching jobs (normally done by dispatchPendingParallelJobs)
    $jobA = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    );

    $jobB = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working',
    );

    // 3. Jobs run entry actions and persist context
    $jobA->handle();
    $jobB->handle();

    // 4. Both contexts merged
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->context->get('regionAResult'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBResult'))->toBe('processed_by_b');

    // 5. Machine still in parallel (entry actions done, but regions not at final states)
    expect($restored->state->isInParallelState())->toBeTrue();

    // 6. Send events to transition regions to final
    $restored->send('REGION_A_DONE');
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    $restored->send('REGION_B_DONE');

    // 7. onDone fires → machine transitions to completed
    $final = ParallelDispatchMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_dispatch.completed');
});

it('sequential fallback when dispatch disabled', function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);

    $machine = ParallelDispatchMachine::create();

    // Entry actions ran synchronously
    expect($machine->state->context->get('regionAResult'))->toBe('processed_by_a');
    expect($machine->state->context->get('regionBResult'))->toBe('processed_by_b');
    expect($machine->definition->pendingParallelDispatches)->toBe([]);
});

it('Bus::fake verifies dispatch from Machine::create with enabled config', function (): void {
    Bus::fake();
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $machine->dispatchPendingParallelJobs();

    Bus::assertDispatched(ParallelRegionJob::class, 2);
    Bus::assertDispatched(ParallelRegionJob::class, fn (ParallelRegionJob $job): bool => $job->regionId === 'parallel_dispatch.processing.region_a');
    Bus::assertDispatched(ParallelRegionJob::class, fn (ParallelRegionJob $job): bool => $job->regionId === 'parallel_dispatch.processing.region_b');
});

it('context merge preserves keys set by first job when second job runs', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Job A runs first, sets region_a_result
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    ))->handle();

    // Verify A's context is persisted
    $afterA = ParallelDispatchMachine::create(state: $rootEventId);
    expect($afterA->state->context->get('regionAResult'))->toBe('processed_by_a');
    expect($afterA->state->context->get('regionBResult'))->toBeNull();

    // Job B runs second, sets region_b_result
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working',
    ))->handle();

    // Both keys preserved
    $afterBoth = ParallelDispatchMachine::create(state: $rootEventId);
    expect($afterBoth->state->context->get('regionAResult'))->toBe('processed_by_a');
    expect($afterBoth->state->context->get('regionBResult'))->toBe('processed_by_b');
});

it('context merge works regardless of job completion order', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Job B runs FIRST this time
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working',
    ))->handle();

    // Then Job A
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    ))->handle();

    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->context->get('regionAResult'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBResult'))->toBe('processed_by_b');
});

it('compound onDone fires within parallel region after transition', function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);

    // Use the dispatch machine in sequential mode — test compound onDone still works
    $machine = ParallelDispatchMachine::create();

    // Both regions at initial states
    expect($machine->state->isInParallelState())->toBeTrue();

    // Send events to reach final states
    $machine->send('REGION_A_DONE');
    $machine->send('REGION_B_DONE');

    // Should transition to completed via onDone
    expect($machine->state->currentStateDefinition->id)->toBe('parallel_dispatch.completed');
});

it('onDone target state entry actions fire after all regions complete', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Run both jobs
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

    // Transition both regions to final
    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_A_DONE');

    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_B_DONE');

    // Verify final state reached
    $final = ParallelDispatchMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_dispatch.completed');
    expect($final->state->currentStateDefinition->type->value)->toBe('final');
});

it('failed job triggers onFail while sibling completes normally', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Region A fails
    $jobA = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_a',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_a.working',
    );
    $jobA->failed(new RuntimeException('API timeout'));

    // Machine should be in error state
    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_dispatch_with_fail.failed');

    // Region B's job should no-op (machine left parallel)
    $jobB = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_b',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_b.working',
    );
    $jobB->handle();

    // Still in error state
    $final = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_dispatch_with_fail.failed');
});
