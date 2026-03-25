<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EActionCounterMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

afterEach(function (): void {
    config(['machine.parallel_dispatch.enabled' => false]);
});

// ═══════════════════════════════════════════════════════════════
//  Entry/exit actions execute exactly once per transition
// ═══════════════════════════════════════════════════════════════

it('LocalQA: entry/exit actions execute exactly once per transition under concurrent sends', function (): void {
    // Create a machine in idle state (entry_count = 1 after entering idle)
    $machine = E2EActionCounterMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Verify initial state: entry action ran once for idle
    expect($machine->state->context->get('entry_count'))->toBe(1);
    expect($machine->state->context->get('exit_count'))->toBe(0);

    // Dispatch 3 sequential transitions via Horizon:
    // idle → processing (PROCESS) → idle (COMPLETE) → processing (PROCESS)
    // Each transition should increment entry/exit counters exactly once.
    $events = [
        ['type' => 'PROCESS'],   // idle → processing: exit_count=1, entry_count=2
        ['type' => 'COMPLETE'],  // processing → idle: exit_count=2, entry_count=3
        ['type' => 'PROCESS'],   // idle → processing: exit_count=3, entry_count=4
    ];

    foreach ($events as $event) {
        SendToMachineJob::dispatch(
            machineClass: E2EActionCounterMachine::class,
            rootEventId: $rootEventId,
            event: $event,
        );
        // Small delay between dispatches to ensure ordering via lock serialization
        usleep(300_000);
    }

    // Wait for machine to reach processing state after 3 events
    $settled = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = E2EActionCounterMachine::create(state: $rootEventId);

        // After PROCESS → COMPLETE → PROCESS, should be in processing with entry_count=4
        return $restored->state->context->get('entry_count') >= 4;
    }, timeoutSeconds: 45);

    expect($settled)->toBeTrue('Machine did not process all 3 events');

    // Restore final state and verify exact counts
    $restored = E2EActionCounterMachine::create(state: $rootEventId);

    // 4 entries: idle(initial) + processing(1st) + idle(return) + processing(2nd)
    expect($restored->state->context->get('entry_count'))->toBe(4);

    // 3 exits: idle→processing + processing→idle + idle→processing
    expect($restored->state->context->get('exit_count'))->toBe(3);

    // Verify via MachineEvent count — each entry action produces an internal event
    $entryEvents = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->where('type', 'like', '%state.entry%')
        ->count();

    // 4 state entries
    expect($entryEvents)->toBe(4, "Expected 4 state.entry events, got {$entryEvents}");

    $exitEvents = MachineEvent::query()
        ->where('root_event_id', $rootEventId)
        ->where('type', 'like', '%state.exit%')
        ->count();

    // 3 state exits
    expect($exitEvents)->toBe(3, "Expected 3 state.exit events, got {$exitEvents}");

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

it('LocalQA: rapid concurrent sends to same machine — action count matches event count', function (): void {
    // Create machine
    $machine = E2EActionCounterMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Dispatch PROCESS followed immediately by FINISH — two rapid sends
    // Lock serialization should ensure both are processed in sequence
    SendToMachineJob::dispatch(
        machineClass: E2EActionCounterMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'PROCESS'],
    );

    SendToMachineJob::dispatch(
        machineClass: E2EActionCounterMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'FINISH'],
    );

    // Wait for machine to reach completed state
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 45);

    expect($completed)->toBeTrue('Machine did not reach completed state');

    // Restore and verify exact counts
    $restored = E2EActionCounterMachine::create(state: $rootEventId);

    // 3 entries: idle(initial) + processing(PROCESS) + completed(FINISH)
    expect($restored->state->context->get('entry_count'))->toBe(3);

    // 2 exits: idle→processing + processing→completed
    expect($restored->state->context->get('exit_count'))->toBe(2);

    // Final state
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_action_counter.completed');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});
