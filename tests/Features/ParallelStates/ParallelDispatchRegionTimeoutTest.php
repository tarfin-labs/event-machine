<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionTimeoutJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchWithFailMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
    config()->set('machine.parallel_dispatch.region_timeout', 0);
});

// ============================================================
// Group 1: InternalEvent enum — PARALLEL_REGION_TIMEOUT
// ============================================================

it('has PARALLEL_REGION_TIMEOUT enum case', function (): void {
    $case = InternalEvent::PARALLEL_REGION_TIMEOUT;

    expect($case->value)->toBe('{machine}.parallel.{placeholder}.region.timeout');
});

it('generates correct PARALLEL_REGION_TIMEOUT event name', function (): void {
    $eventName = InternalEvent::PARALLEL_REGION_TIMEOUT
        ->generateInternalEventName(
            machineId: 'my_machine',
            placeholder: 'my_machine.processing',
        );

    expect($eventName)->toBe('my_machine.parallel.my_machine.processing.region.timeout');
});

// ============================================================
// Group 2: Config — region_timeout
// ============================================================

it('defaults region_timeout to 0 (disabled)', function (): void {
    expect(config('machine.parallel_dispatch.region_timeout'))->toBe(0);
});

it('can set region_timeout via config', function (): void {
    config()->set('machine.parallel_dispatch.region_timeout', 120);

    expect(config('machine.parallel_dispatch.region_timeout'))->toBe(120);
});

// ============================================================
// Group 3: Timeout job does nothing when regions already completed
// ============================================================

it('timeout job is no-op when all regions are already final', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Advance both regions to final via events
    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    $restored->send(['type' => 'REGION_A_DONE']);
    $restored->send(['type' => 'REGION_B_DONE']);

    // Machine should have completed (all regions final → @done → completed)
    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)
        ->toBe('parallel_dispatch_with_fail.completed');

    // Now fire the timeout job — should be a no-op
    $eventCountBefore = MachineEvent::where('root_event_id', $rootEventId)->count();

    (new ParallelRegionTimeoutJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        parallelStateId: 'parallel_dispatch_with_fail.processing',
    ))->handle();

    $eventCountAfter = MachineEvent::where('root_event_id', $rootEventId)->count();

    // No new events should have been created
    expect($eventCountAfter)->toBe($eventCountBefore);
});

// ============================================================
// Group 4: Timeout job triggers @fail when regions are stuck
// ============================================================

it('timeout job triggers @fail when regions have not completed', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Run region A — it only sets context, does NOT raise events (stalls)
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_a',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_a.working',
    ))->handle();

    // Region B not even run — both regions stuck
    // Fire the timeout job
    config()->set('machine.parallel_dispatch.region_timeout', 60);

    (new ParallelRegionTimeoutJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        parallelStateId: 'parallel_dispatch_with_fail.processing',
    ))->handle();

    // Machine should have transitioned to 'failed' via @fail
    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)
        ->toBe('parallel_dispatch_with_fail.failed');
});

// ============================================================
// Group 5: Timeout event payload contains correct details
// ============================================================

it('records PARALLEL_REGION_TIMEOUT event with stalled region details', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);
    config()->set('machine.parallel_dispatch.region_timeout', 120);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Don't run any region jobs — both regions are stuck at initial

    (new ParallelRegionTimeoutJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        parallelStateId: 'parallel_dispatch_with_fail.processing',
    ))->handle();

    $timeoutEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.timeout')
        ->first();

    expect($timeoutEvent)->not->toBeNull();
    expect($timeoutEvent->payload['parallel_state_id'])->toBe('parallel_dispatch_with_fail.processing');
    expect($timeoutEvent->payload['timeout_seconds'])->toBe(120);
    expect($timeoutEvent->payload['stalled_regions'])->toBeArray();
    expect($timeoutEvent->payload['stalled_regions'])->toContain('parallel_dispatch_with_fail.processing.region_a');
    expect($timeoutEvent->payload['stalled_regions'])->toContain('parallel_dispatch_with_fail.processing.region_b');
});

// ============================================================
// Group 6: Timeout job is idempotent
// ============================================================

it('timeout job firing twice does not cause errors', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);
    config()->set('machine.parallel_dispatch.region_timeout', 60);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // First timeout fires — triggers @fail → machine moves to 'failed'
    (new ParallelRegionTimeoutJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        parallelStateId: 'parallel_dispatch_with_fail.processing',
    ))->handle();

    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)
        ->toBe('parallel_dispatch_with_fail.failed');

    // Second timeout fires — should be a no-op (machine already left parallel state)
    $eventCountBefore = MachineEvent::where('root_event_id', $rootEventId)->count();

    (new ParallelRegionTimeoutJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        parallelStateId: 'parallel_dispatch_with_fail.processing',
    ))->handle();

    $eventCountAfter = MachineEvent::where('root_event_id', $rootEventId)->count();

    expect($eventCountAfter)->toBe($eventCountBefore);
});

// ============================================================
// Group 7: Timeout does nothing when no @fail configured
// ============================================================

it('timeout records event but machine stays in parallel state when no @fail configured', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);
    config()->set('machine.parallel_dispatch.region_timeout', 60);

    // ParallelDispatchMachine has @done but no @fail
    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    (new ParallelRegionTimeoutJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        parallelStateId: 'parallel_dispatch.processing',
    ))->handle();

    // Timeout event should be recorded
    $timeoutEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.timeout')
        ->first();

    expect($timeoutEvent)->not->toBeNull();

    // PARALLEL_FAIL event should also be recorded (processParallelOnFail always records it)
    $failEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%parallel.%fail')
        ->first();

    expect($failEvent)->not->toBeNull();

    // Machine should still be in parallel state (no @fail target to transition to)
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->isInParallelState())->toBeTrue();
});

// ============================================================
// Group 8: Timeout with partial completion
// ============================================================

it('timeout only lists incomplete regions in stalled_regions payload', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);
    config()->set('machine.parallel_dispatch.region_timeout', 60);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Manually advance region_a to final by sending an event
    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    $restored->send(['type' => 'REGION_A_DONE']);

    // Now region_a is final, region_b is still at working
    // Fire timeout
    (new ParallelRegionTimeoutJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        parallelStateId: 'parallel_dispatch_with_fail.processing',
    ))->handle();

    $timeoutEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.timeout')
        ->first();

    expect($timeoutEvent)->not->toBeNull();
    // Only region_b should be listed as stalled
    expect($timeoutEvent->payload['stalled_regions'])->toBe(['parallel_dispatch_with_fail.processing.region_b']);
});

// ============================================================
// Group 9: Timeout dispatch integration — dispatchPendingParallelJobs
// ============================================================

it('does not dispatch timeout job when region_timeout is 0', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);
    config()->set('machine.parallel_dispatch.region_timeout', 0);

    \Illuminate\Support\Facades\Queue::fake();

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();

    $machine->definition->pendingParallelDispatches = [
        [
            'region_id'        => 'parallel_dispatch_with_fail.processing.region_a',
            'initial_state_id' => 'parallel_dispatch_with_fail.processing.region_a.working',
        ],
    ];

    $machine->dispatchPendingParallelJobs();

    \Illuminate\Support\Facades\Queue::assertPushed(ParallelRegionJob::class);
    \Illuminate\Support\Facades\Queue::assertNotPushed(ParallelRegionTimeoutJob::class);
});

it('dispatches timeout job when region_timeout is configured', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);
    config()->set('machine.parallel_dispatch.region_timeout', 120);

    \Illuminate\Support\Facades\Queue::fake();

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();

    $machine->definition->pendingParallelDispatches = [
        [
            'region_id'        => 'parallel_dispatch_with_fail.processing.region_a',
            'initial_state_id' => 'parallel_dispatch_with_fail.processing.region_a.working',
        ],
        [
            'region_id'        => 'parallel_dispatch_with_fail.processing.region_b',
            'initial_state_id' => 'parallel_dispatch_with_fail.processing.region_b.working',
        ],
    ];

    $machine->dispatchPendingParallelJobs();

    \Illuminate\Support\Facades\Queue::assertPushed(ParallelRegionJob::class, 2);
    // Only ONE timeout job per parallel state, not per region
    \Illuminate\Support\Facades\Queue::assertPushed(ParallelRegionTimeoutJob::class, 1);
});

// ============================================================
// Group 10: Machine restorable after timeout event
// ============================================================

it('machine can be restored correctly after timeout event in history', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);
    config()->set('machine.parallel_dispatch.region_timeout', 60);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    (new ParallelRegionTimeoutJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        parallelStateId: 'parallel_dispatch_with_fail.processing',
    ))->handle();

    // Machine should be restorable
    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);

    expect($restored->state->currentStateDefinition->id)
        ->toBe('parallel_dispatch_with_fail.failed');
});
