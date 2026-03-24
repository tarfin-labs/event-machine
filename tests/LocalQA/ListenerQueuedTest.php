<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines\ListenerQueuedMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Queued Entry Listener — Full Pipeline via Horizon
// ═══════════════════════════════════════════════════════════════

it('LocalQA: queued entry listener runs via Horizon and records internal events', function (): void {
    // Create machine and trigger initial state entry (sync + queued listeners fire)
    $machine = ListenerQueuedMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Sync listener should have run immediately
    expect($machine->state->context->get('sync_listener_ran'))->toBeTrue();

    // Verify dispatched event is in DB
    $dispatchedEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%listen.queue.QueuedMarkerAction.dispatched')
        ->first();
    expect($dispatchedEvent)->not->toBeNull('Dispatched event not found in machine_events');

    // Wait for Horizon to process ListenerJob → worker runs QueuedMarkerAction
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        return MachineEvent::where('root_event_id', $rootEventId)
            ->where('type', 'like', '%listen.queue.QueuedMarkerAction.completed')
            ->exists();
    }, timeoutSeconds: 60);

    expect($completed)->toBeTrue('Queued listener did not complete via Horizon within 30s');

    // Verify started event also exists
    $startedEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%listen.queue.QueuedMarkerAction.started')
        ->first();
    expect($startedEvent)->not->toBeNull('Started event not found');

    // Verify timestamps: dispatched < started < completed
    $completedEvent = MachineEvent::where('root_event_id', $rootEventId)
        ->where('type', 'like', '%listen.queue.QueuedMarkerAction.completed')
        ->first();

    expect($dispatchedEvent->created_at->lessThanOrEqualTo($startedEvent->created_at))->toBeTrue()
        ->and($startedEvent->created_at->lessThanOrEqualTo($completedEvent->created_at))->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════
//  Mixed Sync + Queued — End-to-End
// ═══════════════════════════════════════════════════════════════

it('LocalQA: sync listener modifies context immediately while queued runs later', function (): void {
    $machine = ListenerQueuedMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Sync ran immediately
    expect($machine->state->context->get('sync_listener_ran'))->toBeTrue();
    // Queued NOT yet (worker hasn't run)
    expect($machine->state->context->get('queued_listener_ran'))->toBeFalse();

    // Wait for Horizon
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        return MachineEvent::where('root_event_id', $rootEventId)
            ->where('type', 'like', '%listen.queue.QueuedMarkerAction.completed')
            ->exists();
    }, timeoutSeconds: 60);

    expect($completed)->toBeTrue('Queued listener did not complete');

    // Restore machine — queued listener should have modified context
    $restored = ListenerQueuedMachine::create(state: $rootEventId);
    expect($restored->state->context->get('queued_listener_ran'))->toBeTrue();
});
