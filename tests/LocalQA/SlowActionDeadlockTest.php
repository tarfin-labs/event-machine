<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\LocalQA\SlowActionMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  Bead 5: Slow action (sleep) blocks concurrent event, no
//  deadlock. One event triggers slow action, second event sent
//  concurrently. Verify no deadlock, second event waits or is
//  rejected gracefully.
// ═══════════════════════════════════════════════════════════════

it('LocalQA: slow action blocks concurrent event without deadlock', function (): void {
    $machine = SlowActionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Dispatch PROCESS event via Horizon — triggers 3-second sleep action
    SendToMachineJob::dispatch(
        machineClass: SlowActionMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'PROCESS'],
    );

    // Small delay to let worker pick up the PROCESS job
    usleep(500_000);

    // Dispatch INTERRUPT concurrently — should either be rejected or queued
    SendToMachineJob::dispatch(
        machineClass: SlowActionMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'INTERRUPT'],
    );

    // Wait for the machine to settle (slow action completes ~3s + queue overhead)
    $settled = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        // Machine should be in processing (slow action completed) or interrupted
        return $cs && (
            str_contains($cs->state_id, 'processing')
            || str_contains($cs->state_id, 'interrupted')
        );
    }, timeoutSeconds: 45);

    expect($settled)->toBeTrue('Machine did not settle — possible deadlock');

    // Verify no stale locks remain (deadlock would leave locks behind)
    $staleLocks = DB::table('machine_locks')
        ->where('root_event_id', $rootEventId)
        ->count();

    expect($staleLocks)->toBe(0);

    // Verify no failed jobs (deadlock/timeout would cause job failures)
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0);

    // Verify machine state is consistent (not corrupted by concurrent access)
    $restored = SlowActionMachine::create(state: $rootEventId);
    expect($restored->state)->not->toBeNull()
        ->and($restored->state->currentStateDefinition->id)->toBeIn([
            'slow_action.processing',
            'slow_action.interrupted',
        ]);
});

it('LocalQA: slow action does not cause worker timeout or job failure', function (): void {
    $machine = SlowActionMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Dispatch PROCESS event — the 3-second sleep should complete within worker timeout
    SendToMachineJob::dispatch(
        machineClass: SlowActionMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'PROCESS'],
    );

    // Wait for processing to complete
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'processing');
    }, timeoutSeconds: 45);

    expect($completed)->toBeTrue('Slow action did not complete — possible timeout');

    // Verify the action actually ran (context was updated)
    $restored = SlowActionMachine::create(state: $rootEventId);
    expect($restored->state->context->get('processed'))->toBeTrue();

    // No failed jobs
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0);
});
