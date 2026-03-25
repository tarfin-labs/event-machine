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

// ═══════════════════════════════════════════════════════════════
//  Bead 6: 4 distinct events dispatched simultaneously, all
//  processed in order (Choir pattern). Each event advances the
//  machine one step. Lock serialization ensures correct ordering.
// ═══════════════════════════════════════════════════════════════

it('LocalQA: 4 burst events all processed in sequence via lock serialization (choir pattern)', function (): void {
    $machine = BurstChoirMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Dispatch all 4 events in rapid succession via Horizon
    // Lock serialization should ensure they execute in queue order
    $events = [
        ['type' => 'A_SING'],
        ['type' => 'B_SING'],
        ['type' => 'C_SING'],
        ['type' => 'D_SING'],
    ];

    foreach ($events as $event) {
        SendToMachineJob::dispatch(
            machineClass: BurstChoirMachine::class,
            rootEventId: $rootEventId,
            event: $event,
        );
    }

    // Wait for machine to reach completed state (all 4 events processed)
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'burst choir machine completes all 4 events');

    expect($completed)->toBeTrue('Burst choir did not complete — events may have been lost or deadlocked');

    // Verify the machine is in the final state
    $restored = BurstChoirMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('burst_choir.completed');

    // Verify all 4 notes were recorded in context
    $notesSung = $restored->state->context->get('notes_sung');
    expect($notesSung)->toBe(['A_SING', 'B_SING', 'C_SING', 'D_SING']);

    // No stale locks
    $staleLocks = DB::table('machine_locks')
        ->where('root_event_id', $rootEventId)
        ->count();
    expect($staleLocks)->toBe(0);

    // No failed jobs
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0);
});

it('LocalQA: burst events on multiple machines do not interfere (choir isolation)', function (): void {
    // Create 3 machines
    $machines = [];
    for ($i = 0; $i < 3; $i++) {
        $m = BurstChoirMachine::create();
        $m->persist();
        $machines[] = $m->state->history->first()->root_event_id;
    }

    // Dispatch all 4 events to each machine simultaneously
    foreach ($machines as $rootEventId) {
        foreach (['A_SING', 'B_SING', 'C_SING', 'D_SING'] as $type) {
            SendToMachineJob::dispatch(
                machineClass: BurstChoirMachine::class,
                rootEventId: $rootEventId,
                event: ['type' => $type],
            );
        }
    }

    // Wait for all machines to complete
    $allCompleted = LocalQATestCase::waitFor(function () use ($machines) {
        foreach ($machines as $rootEventId) {
            $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
            if (!$cs || !str_contains($cs->state_id, 'completed')) {
                return false;
            }
        }

        return true;
    }, timeoutSeconds: 60, description: 'all 3 choir machines complete without interference');

    expect($allCompleted)->toBeTrue('Not all choir machines completed — cross-machine interference');

    // Verify each machine has its own complete set of notes
    foreach ($machines as $rootEventId) {
        $restored = BurstChoirMachine::create(state: $rootEventId);
        expect($restored->state->context->get('notes_sung'))
            ->toBe(['A_SING', 'B_SING', 'C_SING', 'D_SING']);
    }

    // No stale locks
    $staleLocks = DB::table('machine_locks')->count();
    expect($staleLocks)->toBe(0);
});
