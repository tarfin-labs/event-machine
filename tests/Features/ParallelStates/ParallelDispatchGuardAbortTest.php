<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchGuardAbortMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SimulateConcurrentModificationAction;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
    SimulateConcurrentModificationAction::$onExecute           = null;
    SimulateConcurrentModificationAction::$shouldModifyContext = true;
});

// ============================================================
// Grup 1: InternalEvent enum — PARALLEL_REGION_GUARD_ABORT
// ============================================================

it('has PARALLEL_REGION_GUARD_ABORT enum case', function (): void {
    $case = InternalEvent::PARALLEL_REGION_GUARD_ABORT;

    expect($case->value)->toBe('{machine}.parallel.{placeholder}.region.guard_abort');
});

it('generates correct PARALLEL_REGION_GUARD_ABORT event name', function (): void {
    $eventName = InternalEvent::PARALLEL_REGION_GUARD_ABORT
        ->generateInternalEventName(
            machineId: 'my_machine',
            placeholder: 'my_machine.processing.region_a',
        );

    expect($eventName)->toBe('my_machine.parallel.my_machine.processing.region_a.region.guard_abort');
});

// ============================================================
// Grup 2: Under-lock guard — "machine left parallel state"
// ============================================================

it('records parallel_dispatch_guard_abort event when machine exits parallel between action and lock', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchGuardAbortMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // During entry action (lockless phase), simulate a concurrent operation
    // that transitions the machine out of parallel state.
    SimulateConcurrentModificationAction::$onExecute = function () use ($rootEventId): void {
        // Simulate: all other work completed, machine moved to 'completed'
        $lastEvent = MachineEvent::where('root_event_id', $rootEventId)
            ->orderBy('sequence_number', 'desc')
            ->first();

        // Create a new event representing the machine leaving parallel state
        MachineEvent::create([
            'sequence_number' => $lastEvent->sequence_number + 1,
            'created_at'      => now(),
            'machine_id'      => $lastEvent->machine_id,
            'machine_value'   => ['parallel_dispatch_guard_abort.completed'],
            'root_event_id'   => $rootEventId,
            'source'          => 'internal',
            'type'            => 'parallel_dispatch_guard_abort.parallel.processing.done',
            'version'         => 1,
            'payload'         => null,
            'context'         => $lastEvent->context,
        ]);
    };

    // Run Job A — entry action modifies context, then concurrent modification
    // makes machine exit parallel → under-lock guard fires → abort recorded
    (new ParallelRegionJob(
        machineClass: ParallelDispatchGuardAbortMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_guard_abort.processing.region_a',
        initialStateId: 'parallel_dispatch_guard_abort.processing.region_a.working',
    ))->handle();

    // Verify abort event was recorded (concurrent event + abort event = +2)
    $abortEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.guard_abort')
        ->first();

    expect($abortEvent)->not->toBeNull();
    expect($abortEvent->type)->toContain('region.guard_abort');
    expect($abortEvent->payload['reason'])->toBe('machine left parallel state');
    expect($abortEvent->payload['work_was_discarded'])->toBeTrue();
    expect($abortEvent->payload['discarded_context'])->toContain('concurrentResult');
    expect($abortEvent->source->value)->toBe('internal');
});

// ============================================================
// Grup 3: Under-lock guard — "region already advanced"
// ============================================================

it('records parallel_dispatch_guard_abort event when region advances between action and lock', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchGuardAbortMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // During entry action (lockless phase), simulate a concurrent operation
    // that advances region A to finished.
    SimulateConcurrentModificationAction::$onExecute = function () use ($rootEventId): void {
        $lastEvent = MachineEvent::where('root_event_id', $rootEventId)
            ->orderBy('sequence_number', 'desc')
            ->first();

        // Machine is still in parallel state, but region A advanced to finished
        $currentValue = $lastEvent->machine_value;
        $newValue     = array_map(
            fn (string $v) => str_replace('working', 'finished', $v),
            $currentValue,
        );

        MachineEvent::create([
            'sequence_number' => $lastEvent->sequence_number + 1,
            'created_at'      => now(),
            'machine_id'      => $lastEvent->machine_id,
            'machine_value'   => $newValue,
            'root_event_id'   => $rootEventId,
            'source'          => 'internal',
            'type'            => 'parallel_dispatch_guard_abort.parallel.processing.region_a.region.enter',
            'version'         => 1,
            'payload'         => null,
            'context'         => $lastEvent->context,
        ]);
    };

    (new ParallelRegionJob(
        machineClass: ParallelDispatchGuardAbortMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_guard_abort.processing.region_a',
        initialStateId: 'parallel_dispatch_guard_abort.processing.region_a.working',
    ))->handle();

    $abortEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.guard_abort')
        ->first();

    expect($abortEvent)->not->toBeNull();
    expect($abortEvent->payload['reason'])->toBe('region already advanced');
    expect($abortEvent->payload['work_was_discarded'])->toBeTrue();
    expect($abortEvent->payload['discarded_context'])->toContain('concurrentResult');
});

// ============================================================
// Grup 4: Abort event payload details
// ============================================================

it('abort event has correct version and incrementing sequence_number', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchGuardAbortMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Get the last sequence number before abort
    $lastSeqBefore = MachineEvent::where('root_event_id', $rootEventId)
        ->max('sequence_number');

    SimulateConcurrentModificationAction::$onExecute = function () use ($rootEventId): void {
        $lastEvent = MachineEvent::where('root_event_id', $rootEventId)
            ->orderBy('sequence_number', 'desc')
            ->first();

        MachineEvent::create([
            'sequence_number' => $lastEvent->sequence_number + 1,
            'created_at'      => now(),
            'machine_id'      => $lastEvent->machine_id,
            'machine_value'   => ['parallel_dispatch_guard_abort.completed'],
            'root_event_id'   => $rootEventId,
            'source'          => 'internal',
            'type'            => 'parallel_dispatch_guard_abort.parallel.processing.done',
            'version'         => 1,
            'payload'         => null,
            'context'         => $lastEvent->context,
        ]);
    };

    (new ParallelRegionJob(
        machineClass: ParallelDispatchGuardAbortMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_guard_abort.processing.region_a',
        initialStateId: 'parallel_dispatch_guard_abort.processing.region_a.working',
    ))->handle();

    $abortEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.guard_abort')
        ->first();

    // version must be 1 (not 0 or 2)
    expect($abortEvent->version)->toBe(1);

    // sequence_number must be greater than previous events
    expect($abortEvent->sequence_number)->toBeGreaterThan($lastSeqBefore);
});

it('abort event records discarded raised event count', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchGuardAbortMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    SimulateConcurrentModificationAction::$onExecute = function () use ($rootEventId): void {
        $lastEvent = MachineEvent::where('root_event_id', $rootEventId)
            ->orderBy('sequence_number', 'desc')
            ->first();

        MachineEvent::create([
            'sequence_number' => $lastEvent->sequence_number + 1,
            'created_at'      => now(),
            'machine_id'      => $lastEvent->machine_id,
            'machine_value'   => ['parallel_dispatch_guard_abort.completed'],
            'root_event_id'   => $rootEventId,
            'source'          => 'internal',
            'type'            => 'parallel_dispatch_guard_abort.parallel.processing.done',
            'version'         => 1,
            'payload'         => null,
            'context'         => $lastEvent->context,
        ]);
    };

    (new ParallelRegionJob(
        machineClass: ParallelDispatchGuardAbortMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_guard_abort.processing.region_a',
        initialStateId: 'parallel_dispatch_guard_abort.processing.region_a.working',
    ))->handle();

    $abortEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.guard_abort')
        ->first();

    expect($abortEvent->payload)->toHaveKeys([
        'reason',
        'discarded_context',
        'discarded_events',
        'work_was_discarded',
    ]);
    expect($abortEvent->payload['discarded_events'])->toBeInt();
});

it('abort event preserves full context snapshot', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchGuardAbortMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    SimulateConcurrentModificationAction::$onExecute = function () use ($rootEventId): void {
        $lastEvent = MachineEvent::where('root_event_id', $rootEventId)
            ->orderBy('sequence_number', 'desc')
            ->first();

        MachineEvent::create([
            'sequence_number' => $lastEvent->sequence_number + 1,
            'created_at'      => now(),
            'machine_id'      => $lastEvent->machine_id,
            'machine_value'   => ['parallel_dispatch_guard_abort.completed'],
            'root_event_id'   => $rootEventId,
            'source'          => 'internal',
            'type'            => 'parallel_dispatch_guard_abort.parallel.processing.done',
            'version'         => 1,
            'payload'         => null,
            'context'         => $lastEvent->context,
        ]);
    };

    (new ParallelRegionJob(
        machineClass: ParallelDispatchGuardAbortMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_guard_abort.processing.region_a',
        initialStateId: 'parallel_dispatch_guard_abort.processing.region_a.working',
    ))->handle();

    $abortEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.guard_abort')
        ->first();

    // Abort event should have the full context (unchanged, from fresh machine state)
    expect($abortEvent->context)->toBeArray();
    expect($abortEvent->context)->toHaveKey('data');
    expect($abortEvent->context['data'])->toHaveKeys(['concurrentResult', 'regionBResult']);
});

// ============================================================
// Grup 5: Benign abort (no context diff, no raised events)
// ============================================================

it('abort event records work_was_discarded=false when entry action had no side effects', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    // Disable context modification — action runs but produces no diff
    SimulateConcurrentModificationAction::$shouldModifyContext = false;

    $machine = ParallelDispatchGuardAbortMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // During entry action (lockless phase), simulate a concurrent operation
    // that transitions the machine out of parallel state.
    // Action produces NO context diff (shouldModifyContext=false), so
    // work_was_discarded should be false.
    SimulateConcurrentModificationAction::$onExecute = function () use ($rootEventId): void {
        $lastEvent = MachineEvent::where('root_event_id', $rootEventId)
            ->orderBy('sequence_number', 'desc')
            ->first();

        MachineEvent::create([
            'sequence_number' => $lastEvent->sequence_number + 1,
            'created_at'      => now(),
            'machine_id'      => $lastEvent->machine_id,
            'machine_value'   => ['parallel_dispatch_guard_abort.completed'],
            'root_event_id'   => $rootEventId,
            'source'          => 'internal',
            'type'            => 'parallel_dispatch_guard_abort.parallel.processing.done',
            'version'         => 1,
            'payload'         => null,
            'context'         => $lastEvent->context,
        ]);
    };

    (new ParallelRegionJob(
        machineClass: ParallelDispatchGuardAbortMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_guard_abort.processing.region_a',
        initialStateId: 'parallel_dispatch_guard_abort.processing.region_a.working',
    ))->handle();

    $abortEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.guard_abort')
        ->first();

    expect($abortEvent)->not->toBeNull();
    expect($abortEvent->payload['reason'])->toBe('machine left parallel state');
    expect($abortEvent->payload['work_was_discarded'])->toBeFalse();
    expect($abortEvent->payload['discarded_context'])->toBe([]);
    expect($abortEvent->payload['discarded_events'])->toBe(0);
});

// ============================================================
// Grup 6: Machine restoration after abort event
// ============================================================

it('machine can be restored correctly after a parallel_dispatch_guard_abort event exists in history', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchGuardAbortMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Simulate concurrent exit during entry action
    SimulateConcurrentModificationAction::$onExecute = function () use ($rootEventId): void {
        $lastEvent = MachineEvent::where('root_event_id', $rootEventId)
            ->orderBy('sequence_number', 'desc')
            ->first();

        MachineEvent::create([
            'sequence_number' => $lastEvent->sequence_number + 1,
            'created_at'      => now(),
            'machine_id'      => $lastEvent->machine_id,
            'machine_value'   => ['parallel_dispatch_guard_abort.completed'],
            'root_event_id'   => $rootEventId,
            'source'          => 'internal',
            'type'            => 'parallel_dispatch_guard_abort.parallel.processing.done',
            'version'         => 1,
            'payload'         => null,
            'context'         => $lastEvent->context,
        ]);
    };

    (new ParallelRegionJob(
        machineClass: ParallelDispatchGuardAbortMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_guard_abort.processing.region_a',
        initialStateId: 'parallel_dispatch_guard_abort.processing.region_a.working',
    ))->handle();

    // Verify abort event exists
    $abortEvents = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%region.guard_abort')
        ->count();
    expect($abortEvents)->toBe(1);

    // Machine should still be restorable from DB
    $restored = ParallelDispatchGuardAbortMachine::create(state: $rootEventId);

    // Machine should be at 'completed' (the state set by concurrent modification)
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_dispatch_guard_abort.completed');
    expect($restored->state->isInParallelState())->toBeFalse();
});

// ============================================================
// Grup 7: Existing guard tests still work (no abort for pre-lock guards)
// ============================================================

it('pre-lock guard no-op does NOT record abort event (no lock, no abort)', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Run both jobs, advance both regions, complete machine
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

    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_A_DONE');
    $machine = ParallelDispatchMachine::create(state: $rootEventId);
    $machine->send('REGION_B_DONE');

    // Machine at completed state
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_dispatch.completed');

    // Late job — pre-lock guard catches (isInParallelState=false), returns early
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    ))->handle();

    // NO parallel_dispatch_guard_abort event should exist — pre-lock guards don't record
    $abortEvents = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%parallel_dispatch_guard_abort%')
        ->count();
    expect($abortEvents)->toBe(0);
});
