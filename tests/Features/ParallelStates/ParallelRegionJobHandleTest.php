<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\AsdMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

// ============================================================
// Bead: event-machine-uhph — machine reconstruction + guards
// ============================================================

it('reconstructs machine from rootEventId in handle()', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working_a',
    );

    // Should not throw — machine reconstructed successfully
    $job->handle();

    // Verify context was modified by entry action
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
});

it('no-ops when machine is not in parallel state', function (): void {
    // Create a simple non-parallel machine
    $machine = AsdMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    $job = new ParallelRegionJob(
        machineClass: AsdMachine::class,
        rootEventId: $rootEventId,
        regionId: 'asd.processing.region_a',
        initialStateId: 'asd.processing.region_a.working',
    );

    // Should return early — not in parallel state
    $job->handle();

    // Machine state unchanged
    $restored = AsdMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('machine.state_a');
});

it('no-ops when region not found in idMap', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.nonexistent_region',
        initialStateId: 'parallel_dispatch.processing.nonexistent_region.working',
    );

    // Should return early — region not in idMap
    $job->handle();

    // Context unchanged
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->context->get('region_a_result'))->toBeNull();
});

it('no-ops when region no longer at initial state', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Advance region_a out of its initial state
    $machine->send('REGION_A_DONE');

    $historyCountBefore = \Tarfinlabs\EventMachine\Models\MachineEvent::where('root_event_id', $rootEventId)->count();

    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working_a',
    );

    // Should return early — region already advanced
    $job->handle();

    // No new events should have been added
    $historyCountAfter = \Tarfinlabs\EventMachine\Models\MachineEvent::where('root_event_id', $rootEventId)->count();
    expect($historyCountAfter)->toBe($historyCountBefore);
});

// ============================================================
// Bead: event-machine-g158 — entry action execution + side effect capture
// ============================================================

it('runs entry action and modifies context', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working_a',
    );

    $job->handle();

    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
});

it('computeContextDiff detects added keys', function (): void {
    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: 'test',
        regionId: 'test.region',
        initialStateId: 'test.region.initial',
    );

    $reflection = new ReflectionMethod($job, 'computeContextDiff');
    $diff       = $reflection->invoke($job, ['a' => 1], ['a' => 1, 'b' => 2]);

    expect($diff)->toBe(['b' => 2]);
});

it('computeContextDiff detects changed values', function (): void {
    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: 'test',
        regionId: 'test.region',
        initialStateId: 'test.region.initial',
    );

    $reflection = new ReflectionMethod($job, 'computeContextDiff');
    $diff       = $reflection->invoke($job, ['a' => 1], ['a' => 2]);

    expect($diff)->toBe(['a' => 2]);
});

it('computeContextDiff ignores unchanged keys', function (): void {
    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: 'test',
        regionId: 'test.region',
        initialStateId: 'test.region.initial',
    );

    $reflection = new ReflectionMethod($job, 'computeContextDiff');
    $diff       = $reflection->invoke($job, ['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2]);

    expect($diff)->toBe([]);
});

// ============================================================
// Bead: event-machine-gkj6 — lock + fresh reload + merge + onDone check
// ============================================================

it('both region jobs complete and context is merged', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Run region A job
    $jobA = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working_a',
    );
    $jobA->handle();

    // Run region B job
    $jobB = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working_b',
    );
    $jobB->handle();

    // Both contexts should be merged
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    expect($restored->state->context->get('region_b_result'))->toBe('processed_by_b');
});

it('last job triggers onDone when all regions reach final via events', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Run both entry action jobs first
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working_a',
    ))->handle();

    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working_b',
    ))->handle();

    // Now send events to transition to final states
    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_A_DONE');

    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_B_DONE');

    // Should have transitioned to 'completed' via onDone
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_dispatch.completed');
});

it('first job does not trigger onDone when only partially final', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Only run region A job
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working_a',
    ))->handle();

    // Machine should still be in parallel state
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->isInParallelState())->toBeTrue();
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    expect($restored->state->context->get('region_b_result'))->toBeNull();
});
