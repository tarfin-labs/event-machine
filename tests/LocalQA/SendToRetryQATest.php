<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EActionCounterMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  SendToMachineJob retry — event is NOT lost after release(2)
// ═══════════════════════════════════════════════════════════════

it('LocalQA: SendToMachineJob retries on NoTransitionDefinitionFoundException and eventually processes event', function (): void {
    // E2EActionCounterMachine: idle → PROCESS → processing → COMPLETE → completed
    //
    // Bug we fixed: COMPLETE dispatched while machine still in idle would throw
    // NoTransitionDefinitionFoundException → Log::warning → event silently lost.
    // Fix: release(2) → retry → machine now in processing → COMPLETE succeeds.
    $machine = E2EActionCounterMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Dispatch PROCESS and COMPLETE nearly simultaneously.
    // COMPLETE will likely arrive while machine is still in idle (processed by PROCESS job).
    // The release(2) fix should retry COMPLETE after machine reaches processing.
    SendToMachineJob::dispatch(
        machineClass: E2EActionCounterMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'PROCESS'],
    );

    // Dispatch COMPLETE immediately — no delay. This tests the retry path.
    SendToMachineJob::dispatch(
        machineClass: E2EActionCounterMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'FINISH'],
    );

    // Wait for machine to reach completed state
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'SendToMachineJob retry: FINISH event processed after PROCESS completes');

    expect($completed)->toBeTrue('FINISH event was lost — SendToMachineJob retry did not work');

    // Verify full transition chain: idle → processing → completed
    $restored = E2EActionCounterMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('e2e_action_counter.completed');

    // 3 entries: idle(initial) + processing(PROCESS) + completed(FINISH)
    expect($restored->state->context->get('entryCount'))->toBe(3);

    // 2 exits: idle→processing + processing→completed
    expect($restored->state->context->get('exitCount'))->toBe(2);

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

it('LocalQA: 5 rapid events to same machine — all processed via retry, none lost', function (): void {
    // Stress test: dispatch 5 events that alternate idle↔processing.
    // With high concurrency, some will hit NoTransitionDefinitionFoundException
    // and must retry via release(2). None should be lost.
    $machine = E2EActionCounterMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // idle → processing → idle → processing → idle
    $events = [
        ['type' => 'PROCESS'],   // idle → processing
        ['type' => 'COMPLETE'],  // processing → idle
        ['type' => 'PROCESS'],   // idle → processing
        ['type' => 'COMPLETE'],  // processing → idle
        ['type' => 'PROCESS'],   // idle → processing
    ];

    foreach ($events as $event) {
        SendToMachineJob::dispatch(
            machineClass: E2EActionCounterMachine::class,
            rootEventId: $rootEventId,
            event: $event,
        );
    }

    // Wait for all 5 transitions to be processed
    // After 5 events: entry_count = 1(initial idle) + 5 = 6
    $allProcessed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $restored = E2EActionCounterMachine::create(state: $rootEventId);

        return $restored->state->context->get('entryCount') >= 6;
    }, timeoutSeconds: 60, description: '5 rapid events all processed via retry');

    expect($allProcessed)->toBeTrue('Not all 5 events were processed — some were lost');

    $restored = E2EActionCounterMachine::create(state: $rootEventId);

    // 6 entries: idle(initial) + processing + idle + processing + idle + processing
    expect($restored->state->context->get('entryCount'))->toBe(6);

    // 5 exits: idle→proc + proc→idle + idle→proc + proc→idle + idle→proc
    expect($restored->state->context->get('exitCount'))->toBe(5);

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});
