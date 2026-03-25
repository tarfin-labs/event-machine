<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ScheduledMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ExpiredApplicationsResolver;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
    ExpiredApplicationsResolver::$ids = null;
});

/*
 * Scheduled event fires while machine is processing a user-sent event.
 *
 * Scenario: A Machine::send() is dispatched to Horizon. While Horizon
 * processes it, a schedule sweep also dispatches an event to the same
 * machine. The lock mechanism should serialize access — one succeeds
 * first, the other retries or fails gracefully.
 */

it('LocalQA: scheduled event concurrent with send does not corrupt machine', function (): void {
    // Create a machine
    $machine = ScheduledMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    ExpiredApplicationsResolver::setUp([$rootEventId]);

    // Fire a user event via Horizon (DAILY_REPORT is a self-transition)
    SendToMachineJob::dispatch(
        machineClass: ScheduledMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'DAILY_REPORT'],
    );

    // Immediately fire the schedule sweep — it also dispatches to Horizon
    Artisan::call('machine:process-scheduled', [
        '--class' => ScheduledMachine::class,
        '--event' => 'CHECK_EXPIRY',
    ]);

    // Wait for processing to complete — machine should reach expired
    // (CHECK_EXPIRY transitions to expired state)
    $settled = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && (
            str_contains($cs->state_id, 'expired')
            || str_contains($cs->state_id, 'active') // DAILY_REPORT keeps it active, CHECK_EXPIRY transitions
        );
    }, timeoutSeconds: 45);

    expect($settled)->toBeTrue('Machine did not settle after concurrent send + schedule');

    // Machine should be in a consistent state (no corruption)
    $restored = ScheduledMachine::create(state: $rootEventId);
    expect($restored->state)->not->toBeNull();
    expect($restored->state->currentStateDefinition)->not->toBeNull();

    // No failed jobs — concurrent access handled gracefully
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0, 'Concurrent send + schedule caused failed jobs');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0, 'Stale locks remain after concurrent send + schedule');
});
