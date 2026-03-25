<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\LocalQA\BurstChoirMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

afterEach(function (): void {
    LocalQATestCase::cleanTables();
});

// ═══════════════════════════════════════════════════════════════
//  Concurrent sends — no events lost
//
//  Multiple events sent rapidly via SendToMachineJob (Horizon).
//  Lock serialization ensures all events are processed in sequence.
//  Verify none are dropped by lock rejection.
// ═══════════════════════════════════════════════════════════════

it('LocalQA: rapid sequential sends all processed — none lost to lock contention', function (): void {
    // Create a BurstChoirMachine (idle → note_a → note_b → note_c → completed)
    $machine = BurstChoirMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Dispatch all 4 events via Horizon in rapid succession
    // Lock serialization should queue them, not drop them
    $events = ['A_SING', 'B_SING', 'C_SING', 'D_SING'];

    foreach ($events as $eventType) {
        SendToMachineJob::dispatch(
            machineClass: BurstChoirMachine::class,
            rootEventId: $rootEventId,
            event: ['type' => $eventType],
        );
    }

    // Wait for the machine to reach its final state
    $settled = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'rapid sequential sends all reach completed — no lost events');

    expect($settled)->toBeTrue('Machine did not reach completed state — events may have been lost');

    // Restore and verify all notes were sung
    $restored  = BurstChoirMachine::create(state: $rootEventId);
    $notesSung = $restored->state->context->get('notes_sung');

    expect($notesSung)->toBeArray();
    expect($notesSung)->toHaveCount(4);
    expect($notesSung)->toBe(['A_SING', 'B_SING', 'C_SING', 'D_SING']);

    // Verify machine is in the correct final state
    expect($restored->state->matches('completed'))->toBeTrue();

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

it('LocalQA: rapid sends with slight delays all processed correctly', function (): void {
    // Create machine
    $machine = BurstChoirMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Dispatch events with tiny delays to simulate realistic rapid-fire
    $events = ['A_SING', 'B_SING', 'C_SING', 'D_SING'];

    foreach ($events as $eventType) {
        SendToMachineJob::dispatch(
            machineClass: BurstChoirMachine::class,
            rootEventId: $rootEventId,
            event: ['type' => $eventType],
        );
        usleep(50_000); // 50ms between dispatches
    }

    // Wait for completion
    $settled = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'delayed sends all reach completed');

    expect($settled)->toBeTrue('Machine did not reach completed state with delayed sends');

    // Verify all events were processed
    $restored  = BurstChoirMachine::create(state: $rootEventId);
    $notesSung = $restored->state->context->get('notes_sung');

    expect($notesSung)->toHaveCount(4);
    expect($restored->state->matches('completed'))->toBeTrue();

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);
});

it('LocalQA: multiple machines handle concurrent sends independently', function (): void {
    // Create 3 independent machines
    $machines = [];
    for ($i = 0; $i < 3; $i++) {
        $machine = BurstChoirMachine::create();
        $machine->persist();
        $machines[] = [
            'rootEventId' => $machine->state->history->first()->root_event_id,
        ];
    }

    // Dispatch all 4 events to all 3 machines rapidly (interleaved)
    $events = ['A_SING', 'B_SING', 'C_SING', 'D_SING'];

    foreach ($events as $eventType) {
        foreach ($machines as $m) {
            SendToMachineJob::dispatch(
                machineClass: BurstChoirMachine::class,
                rootEventId: $m['rootEventId'],
                event: ['type' => $eventType],
            );
        }
    }

    // Wait for all 3 machines to complete
    $allCompleted = LocalQATestCase::waitFor(function () use ($machines) {
        foreach ($machines as $m) {
            $cs = MachineCurrentState::where('root_event_id', $m['rootEventId'])->first();
            if (!$cs || !str_contains($cs->state_id, 'completed')) {
                return false;
            }
        }

        return true;
    }, timeoutSeconds: 60, description: 'all 3 machines complete with interleaved concurrent sends');

    expect($allCompleted)->toBeTrue('Not all 3 machines reached completed state');

    // Verify each machine processed all 4 events
    foreach ($machines as $m) {
        $restored  = BurstChoirMachine::create(state: $m['rootEventId']);
        $notesSung = $restored->state->context->get('notes_sung');

        expect($notesSung)->toHaveCount(4, "Machine {$m['rootEventId']} lost events");
        expect($restored->state->matches('completed'))->toBeTrue();
    }

    // No stale locks anywhere
    $totalLocks = DB::table('machine_locks')
        ->whereIn('root_event_id', array_column($machines, 'rootEventId'))
        ->count();
    expect($totalLocks)->toBe(0);
});
