<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchWithFailMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

it('completed region context preserved when sibling fails', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Region B completes first (sets region_b_result)
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_b',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_b.working',
    ))->handle();

    // Verify B's context persisted
    $afterB = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($afterB->state->context->get('regionBResult'))->toBe('processed_by_b');

    // Region A fails → @fail fires
    $jobA = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_a',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_a.working',
    );
    $jobA->failed(new RuntimeException('Region A failed'));

    // Machine in error state
    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_dispatch_with_fail.failed');

    // Region B's context is preserved despite Region A's failure
    expect($restored->state->context->get('regionBResult'))->toBe('processed_by_b');
});

it('both regions complete context then one fails → all context preserved', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Both regions complete entry actions
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_a',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_a.working',
    ))->handle();

    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_b',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_b.working',
    ))->handle();

    // Both contexts set
    $afterBoth = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($afterBoth->state->context->get('regionAResult'))->toBe('processed_by_a');
    expect($afterBoth->state->context->get('regionBResult'))->toBe('processed_by_b');

    // Now simulate a later failure (e.g., from a subsequent transition job)
    $failJob = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_a',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_a.working',
    );
    $failJob->failed(new RuntimeException('Late failure'));

    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_dispatch_with_fail.failed');

    // Both region contexts preserved
    expect($restored->state->context->get('regionAResult'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBResult'))->toBe('processed_by_b');
});
