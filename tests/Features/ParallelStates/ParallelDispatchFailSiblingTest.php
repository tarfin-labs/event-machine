<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchWithFailMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

it('@fail cancels sibling job that has not started yet', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Region A fails → machine transitions to error
    $jobA = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_a',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_a.working',
    );
    $jobA->failed(new RuntimeException('Region A failure'));

    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_dispatch_with_fail.failed');

    // Region B job starts late — pre-lock guard detects machine left parallel
    $jobB = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_b',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_b.working',
    );
    $jobB->handle();

    // Region B entry action never ran (context not updated)
    $final = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_dispatch_with_fail.failed');
    expect($final->state->context->get('regionBData'))->toBeNull();
});

it('@fail cancels sibling job that finished entry action', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Region A fails first → machine transitions to error
    $jobA = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_a',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_a.working',
    );
    $jobA->failed(new RuntimeException('Region A failure'));

    $historyBefore = MachineEvent::where('root_event_id', $rootEventId)->count();

    // Region B's failed() also fires — machine already left parallel → no-op
    $jobB = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_b',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_b.working',
    );
    $jobB->failed(new RuntimeException('Region B also failed'));

    $historyAfter = MachineEvent::where('root_event_id', $rootEventId)->count();
    expect($historyAfter)->toBe($historyBefore);

    // Still in error state
    $final = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_dispatch_with_fail.failed');
});

it('@fail with sibling waiting for lock → sibling acquires and no-ops', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Region A fails → holds lock → processes @fail → releases
    $jobA = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_a',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_a.working',
    );
    $jobA->failed(new RuntimeException('Region A failure'));

    // Region B acquires lock (now free) → reloads → not in parallel → no-op
    $jobB = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_b',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_b.working',
    );
    $jobB->handle();

    $final = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_dispatch_with_fail.failed');
});
