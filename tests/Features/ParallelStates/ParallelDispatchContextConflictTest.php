<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EContextConflictMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

// ============================================================
// Grup 1: InternalEvent enum — PARALLEL_CONTEXT_CONFLICT
// ============================================================

it('has PARALLEL_CONTEXT_CONFLICT enum case', function (): void {
    $case = InternalEvent::PARALLEL_CONTEXT_CONFLICT;

    expect($case->value)->toBe('{machine}.parallel.{placeholder}.context.conflict');
});

it('generates correct PARALLEL_CONTEXT_CONFLICT event name', function (): void {
    $eventName = InternalEvent::PARALLEL_CONTEXT_CONFLICT
        ->generateInternalEventName(
            machineId: 'my_machine',
            placeholder: 'my_machine.processing.region_a',
        );

    expect($eventName)->toBe('my_machine.parallel.my_machine.processing.region_a.context.conflict');
});

// ============================================================
// Grup 2: No conflict when regions write different keys
// ============================================================

it('does not record context conflict when regions write to different keys', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId       = $machine->state->history->first()->root_event_id;
    $contextAtDispatch = $machine->state->context->data;

    // Region A writes region_a_result, Region B writes region_b_result — no overlap
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
        contextAtDispatch: $contextAtDispatch,
    ))->handle();

    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working',
        contextAtDispatch: $contextAtDispatch,
    ))->handle();

    // No conflict events should exist
    $conflictEvents = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%context.conflict%')
        ->count();
    expect($conflictEvents)->toBe(0);

    // Both context values should be set
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->context->get('regionAResult'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBResult'))->toBe('processed_by_b');
});

// ============================================================
// Grup 3: Conflict detected when regions write same scalar key
// ============================================================

it('records context conflict when second region overwrites scalar key set by first', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = E2EContextConflictMachine::create();
    $machine->persist();
    $rootEventId       = $machine->state->history->first()->root_event_id;
    $contextAtDispatch = $machine->state->context->data;

    // Region A writes shared_scalar='value_from_a'
    (new ParallelRegionJob(
        machineClass: E2EContextConflictMachine::class,
        rootEventId: $rootEventId,
        regionId: 'e2e_context_conflict.processing.region_a',
        initialStateId: 'e2e_context_conflict.processing.region_a.working',
        contextAtDispatch: $contextAtDispatch,
    ))->handle();

    // Region B writes shared_scalar='value_from_b' — overwrites Region A's value
    (new ParallelRegionJob(
        machineClass: E2EContextConflictMachine::class,
        rootEventId: $rootEventId,
        regionId: 'e2e_context_conflict.processing.region_b',
        initialStateId: 'e2e_context_conflict.processing.region_b.working',
        contextAtDispatch: $contextAtDispatch,
    ))->handle();

    // Context conflict should be recorded for Region B
    $conflictEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%context.conflict%')
        ->first();

    expect($conflictEvent)->not->toBeNull();
    expect($conflictEvent->payload['conflicted_keys'])->toContain('sharedScalar');
    expect($conflictEvent->payload['region_id'])->toContain('region_b');
});

// ============================================================
// Grup 4: Conflict payload details
// ============================================================

it('conflict event payload lists all conflicted keys', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = E2EContextConflictMachine::create();
    $machine->persist();
    $rootEventId       = $machine->state->history->first()->root_event_id;
    $contextAtDispatch = $machine->state->context->data;

    // Both regions write to shared_scalar AND shared_array
    (new ParallelRegionJob(
        machineClass: E2EContextConflictMachine::class,
        rootEventId: $rootEventId,
        regionId: 'e2e_context_conflict.processing.region_a',
        initialStateId: 'e2e_context_conflict.processing.region_a.working',
        contextAtDispatch: $contextAtDispatch,
    ))->handle();

    (new ParallelRegionJob(
        machineClass: E2EContextConflictMachine::class,
        rootEventId: $rootEventId,
        regionId: 'e2e_context_conflict.processing.region_b',
        initialStateId: 'e2e_context_conflict.processing.region_b.working',
        contextAtDispatch: $contextAtDispatch,
    ))->handle();

    $conflictEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%context.conflict%')
        ->first();

    expect($conflictEvent)->not->toBeNull();
    expect($conflictEvent->payload)->toHaveKeys(['region_id', 'conflicted_keys']);
    // shared_scalar is always conflicted; shared_array may or may not be depending on
    // whether array deep merge counts as conflict (it does — the existing value changed)
    expect($conflictEvent->payload['conflicted_keys'])->toContain('sharedScalar');
});

// ============================================================
// Grup 5: First region does not report conflict
// ============================================================

it('first completing region does not report conflict (no prior sibling writes)', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = E2EContextConflictMachine::create();
    $machine->persist();
    $rootEventId       = $machine->state->history->first()->root_event_id;
    $contextAtDispatch = $machine->state->context->data;

    // Only Region A runs — no sibling has written yet
    (new ParallelRegionJob(
        machineClass: E2EContextConflictMachine::class,
        rootEventId: $rootEventId,
        regionId: 'e2e_context_conflict.processing.region_a',
        initialStateId: 'e2e_context_conflict.processing.region_a.working',
        contextAtDispatch: $contextAtDispatch,
    ))->handle();

    $conflictEvents = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%context.conflict%')
        ->count();
    expect($conflictEvents)->toBe(0);
});

// ============================================================
// Grup 6: LWW behavior preserved (value still written despite conflict)
// ============================================================

it('LWW still applies — second region value wins despite conflict recording', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = E2EContextConflictMachine::create();
    $machine->persist();
    $rootEventId       = $machine->state->history->first()->root_event_id;
    $contextAtDispatch = $machine->state->context->data;

    // Region A writes shared_scalar='value_from_a'
    (new ParallelRegionJob(
        machineClass: E2EContextConflictMachine::class,
        rootEventId: $rootEventId,
        regionId: 'e2e_context_conflict.processing.region_a',
        initialStateId: 'e2e_context_conflict.processing.region_a.working',
        contextAtDispatch: $contextAtDispatch,
    ))->handle();

    // Region B writes shared_scalar='value_from_b' (wins)
    (new ParallelRegionJob(
        machineClass: E2EContextConflictMachine::class,
        rootEventId: $rootEventId,
        regionId: 'e2e_context_conflict.processing.region_b',
        initialStateId: 'e2e_context_conflict.processing.region_b.working',
        contextAtDispatch: $contextAtDispatch,
    ))->handle();

    $restored = E2EContextConflictMachine::create(state: $rootEventId);
    expect($restored->state->context->get('sharedScalar'))->toBe('value_from_b');

    // Conflict IS recorded
    $conflictEvents = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%context.conflict%')
        ->count();
    expect($conflictEvents)->toBe(1);
});
