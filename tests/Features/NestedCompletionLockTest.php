<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\ChainedJobParentMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors\QueuedListenerJobParentMachine;

// ─── Nested completion self-deadlock (sync queue) ─────────────────
//
// Regression tests for the process-local lock registry. ChildMachineCompletionJob
// and ListenerJob acquired machine locks without registering them in
// Machine::$heldLockIds, so on QUEUE_CONNECTION=sync any job dispatched inline
// while a completion job held the parent's lock (the next delegation's
// completion, a queued listener) blocked on a lock held by its own call
// stack — burning the full lock timeout and resolving as a spurious @fail.
// Registration now lives in MachineLockManager::acquire() and
// MachineLockHandle::release(), so every acquirer participates in re-entrancy
// detection by construction.

it('completes a chained delegation whose @done invokes another child inline', function (): void {
    $machine = ChainedJobParentMachine::create();
    $machine->send(['type' => 'START']);

    // step_one's completion job holds the parent lock while its persist()
    // runs step_two's ChildJobJob inline; step_two's completion job must
    // detect the held lock (re-entrant) instead of deadlocking for 30s
    // and resolving as @fail.
    expect($machine->state->value)->toBe(['chained_job_parent.completed']);

    $rootEventId = $machine->state->history->first()->root_event_id;
    $restored    = ChainedJobParentMachine::create(state: $rootEventId);

    expect($restored->state->value)->toBe(['chained_job_parent.completed'])
        // No stale re-entrancy entries may survive the chain — a leftover
        // entry would silently skip locking for this machine in a long-lived
        // process (Octane, queue workers).
        ->and(Machine::$heldLockIds)->toBe([]);
});

it('runs a queued listener dispatched while a completion job holds the parent lock', function (): void {
    $machine = QueuedListenerJobParentMachine::create();
    $machine->send(['type' => 'START']);

    $rootEventId = $machine->state->history->first()->root_event_id;
    $restored    = QueuedListenerJobParentMachine::create(state: $rootEventId);

    // One queued entry listener per state entry: idle, invoking, completed.
    // The `completed` listener is flushed inside the completion job's
    // persist() while the parent lock is held — it must run re-entrantly
    // instead of timing out and being silently dropped.
    expect($restored->state->value)->toBe(['queued_listener_job_parent.completed'])
        ->and($restored->state->context->get('listenerRuns'))->toBe(3)
        ->and(Machine::$heldLockIds)->toBe([]);
});

// ─── Lock registry bookkeeping ───────────────────────────────

it('registers acquired locks in the process-local registry and deregisters on release', function (): void {
    $handle = MachineLockManager::acquire('lock-registry-001', context: 'test');

    expect(Machine::$heldLockIds)->toHaveKey('lock-registry-001');

    $handle->release();

    expect(Machine::$heldLockIds)->not->toHaveKey('lock-registry-001');
});

it('release only deregisters its own root event id', function (): void {
    $handleA = MachineLockManager::acquire('lock-registry-00A', context: 'test');
    $handleB = MachineLockManager::acquire('lock-registry-00B', context: 'test');

    $handleA->release();

    expect(Machine::$heldLockIds)->not->toHaveKey('lock-registry-00A')
        ->and(Machine::$heldLockIds)->toHaveKey('lock-registry-00B');

    $handleB->release();
});
