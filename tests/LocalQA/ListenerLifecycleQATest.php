<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines\ListenerRetryMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines\ListenerExitTransitionMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Listener Lifecycle — Queued Listeners via Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: queued listener failure + retry does not corrupt context', function (): void {
    // ThrowOnceAction throws on first call, succeeds on retry
    // Uses separate ListenerRetryMachine to avoid cross-test static counter pollution
    $machine = ListenerRetryMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for the queued entry listener to complete (after retry)
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        return MachineEvent::where('root_event_id', $rootEventId)
            ->where('type', 'like', '%listen.queue.ThrowOnceAction.completed')
            ->exists();
    }, timeoutSeconds: 60, description: 'ThrowOnceAction listener completion after retry');

    expect($completed)->toBeTrue('Queued listener did not complete via Horizon after retry');

    // Assert: context set correctly on successful retry
    $restored = ListenerRetryMachine::create(state: $rootEventId);
    expect($restored->state->context->get('listener_ran'))->toBeTrue();
});

it('LocalQA: queued exit listener runs via Horizon when leaving state', function (): void {
    // Uses ListenerExitTransitionMachine (no ThrowOnceAction) for isolation
    $machine = ListenerExitTransitionMachine::create();
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
    $restored = ListenerExitTransitionMachine::create(state: $rootEventId);
    expect($restored->state->context->get('exit_listener_ran'))->toBeTrue();
});

it('LocalQA: queued transition listener runs via Horizon', function (): void {
    // Uses ListenerExitTransitionMachine (no ThrowOnceAction) for isolation
    $machine = ListenerExitTransitionMachine::create();
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
    $restored = ListenerExitTransitionMachine::create(state: $rootEventId);
    expect($restored->state->context->get('transition_listener_ran'))->toBeTrue();
});
