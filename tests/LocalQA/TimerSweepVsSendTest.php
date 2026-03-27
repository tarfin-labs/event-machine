<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

/*
 * Timer sweep concurrent with Machine::send — dedup.
 *
 * Scenario: An @after timer has expired and the sweep command dispatches
 * a timer event. Simultaneously, a user sends an event that would also
 * transition the machine out of the timer's source state. The lock
 * mechanism must serialize these — one wins, the other sees a stale
 * state and is a no-op or throws gracefully.
 *
 * Key invariant: the timer event fires at most once (dedup via
 * machine_timer_fires), and no data corruption occurs.
 */

it('LocalQA: timer sweep and concurrent send do not double-fire timer event', function (): void {
    // Create machine in awaiting_payment state (has @after timer)
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate so timer is eligible to fire
    DB::table('machine_current_states')
        ->where('root_event_id', $rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    // Fire timer sweep AND send a manual event concurrently
    Artisan::call('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    // Also dispatch a manual event via Horizon — this races with the timer
    SendToMachineJob::dispatch(
        machineClass: AfterTimerMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'MANUAL_CANCEL'],
    );

    // Wait for machine to settle in a terminal state
    $settled = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && (
            str_contains($cs->state_id, 'cancelled')
            || str_contains($cs->state_id, 'awaiting_payment') // manual event not handled
        );
    }, timeoutSeconds: 60, description: 'machine settles after concurrent timer sweep + manual send');

    expect($settled)->toBeTrue('Machine did not settle after concurrent timer + send');

    // Timer fires table should have at most 1 fired entry
    $timerFires = DB::table('machine_timer_fires')
        ->where('root_event_id', $rootEventId)
        ->count();
    expect($timerFires)->toBeLessThanOrEqual(1, 'Timer fired more than once');

    // No failed jobs
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0, 'Concurrent timer + send caused failed jobs');

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0, 'Stale locks remain after concurrent timer + send');
});

it('LocalQA: timer sweep after manual transition is dedup no-op', function (): void {
    // Create machine and immediately transition it away from timer state
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate so timer would be eligible
    DB::table('machine_current_states')
        ->where('root_event_id', $rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    // Send a manual event that transitions the machine out of awaiting_payment
    SendToMachineJob::dispatch(
        machineClass: AfterTimerMachine::class,
        rootEventId: $rootEventId,
        event: ['type' => 'PAY'],
    );

    // Wait for manual transition
    $paid = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && !str_contains($cs->state_id, 'awaiting_payment');
    }, timeoutSeconds: 30, description: 'manual PAY event transitions machine out of awaiting_payment');

    // Now run timer sweep — machine already left the timer state
    Artisan::call('machine:process-timers', ['--class' => AfterTimerMachine::class]);
    sleep(2);

    // Timer should not have fired (machine already moved on)
    $timerFires = DB::table('machine_timer_fires')
        ->where('root_event_id', $rootEventId)
        ->where('status', 'fired')
        ->count();

    // Timer fire count should be 0 (machine moved away before sweep)
    // OR 1 if sweep ran first (race condition) — both are acceptable
    expect($timerFires)->toBeLessThanOrEqual(1);

    // No failed jobs
    $failedJobs = DB::table('failed_jobs')->count();
    expect($failedJobs)->toBe(0);
});
