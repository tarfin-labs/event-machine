<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineTimerFire;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryTimerMachine;

// ─── machine:timer-status ───────────────────────────────────────

it('E2E: timer-status shows machine instances', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();

    $this->artisan('machine:timer-status')
        ->assertExitCode(0);
});

it('E2E: timer-status shows fired timers', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    $this->artisan('machine:timer-status')
        ->assertExitCode(0);

    // Verify fire record exists
    expect(MachineTimerFire::where('root_event_id', $rootEventId)->first()->status)
        ->toBe(MachineTimerFire::STATUS_FIRED);
});

it('E2E: timer-status filters by class', function (): void {
    // Create both machine types
    $after = AfterTimerMachine::create();
    $after->persist();

    $every = EveryTimerMachine::create();
    $every->persist();

    $this->artisan('machine:timer-status', ['--class' => AfterTimerMachine::class])
        ->assertExitCode(0);
});

// ─── Backpressure ───────────────────────────────────────────────

it('E2E: sweep skips when backpressure threshold exceeded', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    // Set threshold to -1 (always exceeded since Queue::size() >= 0)
    config(['machine.timers.backpressure_threshold' => -1]);

    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class])
        ->assertExitCode(0);

    // Machine NOT transitioned (sweep skipped)
    $restored = AfterTimerMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('after_timer.awaiting_payment');

    // Reset threshold
    config(['machine.timers.backpressure_threshold' => 10000]);

    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);

    // Now it transitions
    $restored = AfterTimerMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('after_timer.cancelled');
});
