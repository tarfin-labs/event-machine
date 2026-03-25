<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines\ListenerRetryMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines\ListenerExitOnlyMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines\ListenerTransitionOnlyMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Listener Lifecycle — Queued Listeners via Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: queued entry listener completes via Horizon (ThrowOnceAction retry)', function (): void {
    // ThrowOnceAction throws on $callCount===1, succeeds otherwise.
    // In persistent Horizon workers, the static counter accumulates across tests.
    // On first-ever run: throws once → Horizon retries → succeeds.
    // On subsequent runs: counter > 1 → succeeds immediately.
    // Either way, the .completed event must appear.
    $machine = ListenerRetryMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for the queued entry listener to complete (immediately or after retry)
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        return MachineEvent::where('root_event_id', $rootEventId)
            ->where('type', 'like', '%listen.queue.ThrowOnceAction.completed')
            ->exists();
    }, timeoutSeconds: 60, description: 'ThrowOnceAction listener completion');

    expect($completed)->toBeTrue('Queued entry listener did not complete via Horizon');

    // Assert: context was set by the action (on successful execution)
    $restored = ListenerRetryMachine::create(state: $rootEventId);
    expect($restored->state->context->get('listener_ran'))->toBeTrue();
});

it('LocalQA: queued exit listener runs via Horizon when leaving state', function (): void {
    // Uses ListenerExitOnlyMachine — ONLY exit listener, no concurrent transition listener.
    // This prevents lost-update from concurrent ListenerJobs.
    $machine = ListenerExitOnlyMachine::create();
    $machine->send(['type' => 'ACTIVATE']);
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for the queued exit listener to complete
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        return MachineEvent::where('root_event_id', $rootEventId)
            ->where('type', 'like', '%listen.queue.QueuedExitAction.completed')
            ->exists();
    }, timeoutSeconds: 60, description: 'QueuedExitAction exit listener completion');

    expect($completed)->toBeTrue('Queued exit listener did not complete via Horizon');

    // Assert: exit action's context modifications persisted
    $restored = ListenerExitOnlyMachine::create(state: $rootEventId);
    expect($restored->state->context->get('exit_listener_ran'))->toBeTrue();
});

it('LocalQA: queued transition listener runs via Horizon', function (): void {
    // Uses ListenerTransitionOnlyMachine — ONLY transition listener, no concurrent exit listener.
    $machine = ListenerTransitionOnlyMachine::create();
    $machine->send(['type' => 'ACTIVATE']);
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for the queued transition listener to complete
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        return MachineEvent::where('root_event_id', $rootEventId)
            ->where('type', 'like', '%listen.queue.QueuedTransitionAction.completed')
            ->exists();
    }, timeoutSeconds: 60, description: 'QueuedTransitionAction transition listener completion');

    expect($completed)->toBeTrue('Queued transition listener did not complete via Horizon');

    // Assert: transition listener ran after state change
    $restored = ListenerTransitionOnlyMachine::create(state: $rootEventId);
    expect($restored->state->context->get('transition_listener_ran'))->toBeTrue();
});
