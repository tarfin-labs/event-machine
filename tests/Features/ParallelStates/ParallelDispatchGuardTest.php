<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchWithFailMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

// ============================================================
// Bead: event-machine-nxra — job guard checks (stale state no-ops)
// ============================================================

it('job no-ops when machine no longer in parallel state (pre-lock guard)', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Simulate failure transition — machine leaves parallel
    $jobA = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail.processing.region_a',
        initialStateId: 'parallel_fail.processing.region_a.working_a',
    );
    $jobA->failed(new \RuntimeException('Trigger onFail'));

    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_fail.error');

    $historyBefore = MachineEvent::where('root_event_id', $rootEventId)->count();

    // Late job B — pre-lock guard catches: isInParallelState() === false
    $jobB = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail.processing.region_b',
        initialStateId: 'parallel_fail.processing.region_b.working_b',
    );
    $jobB->handle();

    // No new events written
    $historyAfter = MachineEvent::where('root_event_id', $rootEventId)->count();
    expect($historyAfter)->toBe($historyBefore);
});

it('job no-ops when region already advanced past initial state', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Run job and advance region A past initial state
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working_a',
    ))->handle();

    // Send event to advance region A to final (past initial)
    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_A_DONE');

    $historyBefore = MachineEvent::where('root_event_id', $rootEventId)->count();

    // Late retry — region A is now at finished_a, not working_a
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working_a',
    ))->handle();

    // No new events — guard caught: region no longer at initial
    $historyAfter = MachineEvent::where('root_event_id', $rootEventId)->count();
    expect($historyAfter)->toBe($historyBefore);
});

it('job no-ops under lock when machine left parallel between action and lock', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Run region A
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working_a',
    ))->handle();

    // Run region B
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working_b',
    ))->handle();

    // Transition to completed (machine leaves parallel)
    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_A_DONE');
    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_B_DONE');

    $final = ParallelDispatchMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_dispatch.completed');

    $historyBefore = MachineEvent::where('root_event_id', $rootEventId)->count();

    // Late job — under-lock guard catches stale state
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working_a',
    ))->handle();

    $historyAfter = MachineEvent::where('root_event_id', $rootEventId)->count();
    expect($historyAfter)->toBe($historyBefore);
});
