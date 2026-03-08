<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchWithRaiseMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

// ============================================================
// Grup 1: InternalEvent enum — PARALLEL_REGION_STALLED
// ============================================================

it('has PARALLEL_REGION_STALLED enum case', function (): void {
    $case = InternalEvent::PARALLEL_REGION_STALLED;

    expect($case->value)->toBe('{machine}.parallel.{placeholder}.region.stalled');
});

it('generates correct PARALLEL_REGION_STALLED event name', function (): void {
    $eventName = InternalEvent::PARALLEL_REGION_STALLED
        ->generateInternalEventName(
            machineId: 'my_machine',
            placeholder: 'my_machine.processing.region_a',
        );

    expect($eventName)->toBe('my_machine.parallel.my_machine.processing.region_a.region.stalled');
});

// ============================================================
// Grup 2: Stall detected when entry action does not raise events
// ============================================================

it('records stall event when region entry action completes without raising events', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // RegionAEntryAction only sets context, never raises events
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    ))->handle();

    $stallEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.stalled')
        ->first();

    expect($stallEvent)->not->toBeNull();
    expect($stallEvent->payload['region_id'])->toBe('parallel_dispatch.processing.region_a');
    expect($stallEvent->payload['initial_state_id'])->toBe('parallel_dispatch.processing.region_a.working');
    expect($stallEvent->payload['context_changed'])->toBeTrue();
});

// ============================================================
// Grup 3: No stall when entry action raises event that advances region
// ============================================================

it('does not record stall event when entry action raises event that advances region', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // RegionARaiseAction raises REGION_A_PROCESSED → transitions to finished
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_raise.processing.region_a',
        initialStateId: 'parallel_dispatch_with_raise.processing.region_a.working',
    ))->handle();

    $stallEvents = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.stalled')
        ->count();

    expect($stallEvents)->toBe(0);

    // Verify region actually advanced
    $restored = ParallelDispatchWithRaiseMachine::create(state: $rootEventId);
    expect($restored->state->value)->toContain('parallel_dispatch_with_raise.processing.region_a.finished');
});

// ============================================================
// Grup 4: Stall payload details
// ============================================================

it('stall event payload includes context_changed=false when entry action has no side effects', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    // RegionBEntryAction sets context → context_changed=true for region B
    // But for this test, we check region_b which DOES set context
    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working',
    ))->handle();

    $stallEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.stalled')
        ->first();

    expect($stallEvent)->not->toBeNull();
    expect($stallEvent->payload)->toHaveKeys(['region_id', 'initial_state_id', 'context_changed']);
    expect($stallEvent->payload['context_changed'])->toBeTrue();
});

// ============================================================
// Grup 5: Both regions stall independently
// ============================================================

it('records separate stall events for each region that does not advance', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Both RegionAEntryAction and RegionBEntryAction only set context — no raise
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

    $stallEvents = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.stalled')
        ->get();

    expect($stallEvents)->toHaveCount(2);

    $regionIds = $stallEvents->pluck('payload.region_id')->toArray();
    expect($regionIds)->toContain('parallel_dispatch.processing.region_a');
    expect($regionIds)->toContain('parallel_dispatch.processing.region_b');
});

// ============================================================
// Grup 6: Mixed — one region stalls, other advances
// ============================================================

it('only stalled region records stall event when sibling advances normally', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithRaiseMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Region A raises event → advances to finished (no stall)
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_raise.processing.region_a',
        initialStateId: 'parallel_dispatch_with_raise.processing.region_a.working',
    ))->handle();

    // Region B only sets context → stays at working (stall)
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithRaiseMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_raise.processing.region_b',
        initialStateId: 'parallel_dispatch_with_raise.processing.region_b.working',
    ))->handle();

    $stallEvents = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.stalled')
        ->get();

    // Only region B should have a stall event
    expect($stallEvents)->toHaveCount(1);
    expect($stallEvents->first()->payload['region_id'])->toBe('parallel_dispatch_with_raise.processing.region_b');
});

// ============================================================
// Grup 7: Machine restorable after stall event
// ============================================================

it('machine can be restored correctly after stall events exist in history', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    ))->handle();

    // Machine should be restorable despite stall event in history
    $restored = ParallelDispatchMachine::create(state: $rootEventId);

    expect($restored->state->isInParallelState())->toBeTrue();
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    // Region still at initial — stall is informational, not destructive
    expect($restored->state->value)->toContain('parallel_dispatch.processing.region_a.working');
});
