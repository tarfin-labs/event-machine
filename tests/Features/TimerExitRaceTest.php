<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\Models\MachineTimerFire;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryTimerMachine;

// ═══════════════════════════════════════════════════════════════
//  Timer Exit Race: state exits before timer fires
//
//  When a machine transitions out of a timer-configured state
//  before the timer sweep runs, the timer must NOT fire.
//  The timer dedup mechanism relies on machine_current_states
//  changing (state_id no longer matches) so the sweep query
//  won't find the instance.
// ═══════════════════════════════════════════════════════════════

it('after timer does not fire when state exited before sweep', function (): void {
    Bus::fake();

    // Create machine in awaiting_payment (has ORDER_EXPIRED after 7 days)
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate to past the 7-day deadline
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    // NOW transition the machine out of awaiting_payment before the sweep runs
    $machine->send(['type' => 'PAY']);

    // Machine is now in 'processing' — no longer in awaiting_payment
    expect($machine->state->matches('processing'))->toBeTrue();

    // The machine_current_states row should now point to 'processing', not 'awaiting_payment'
    $cs = MachineCurrentState::forInstance($rootEventId)->first();
    expect($cs->state_id)->toContain('processing');

    // Run the timer sweep — should find NO eligible instances
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class])
        ->assertExitCode(0);

    // No timer fire record should exist
    $fire = MachineTimerFire::where('root_event_id', $rootEventId)->first();
    expect($fire)->toBeNull();

    // No jobs should have been dispatched
    Bus::assertNothingBatched();
});

it('every timer does not fire when state exited before sweep', function (): void {
    Bus::fake();

    // Create machine in 'active' (has BILLING every 30 days)
    $machine = EveryTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate past the 30-day interval
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(31)]);

    // Transition out of active state before sweep
    $machine->send(['type' => 'CANCEL']);

    // Machine is now in 'cancelled' — no longer in 'active'
    expect($machine->state->matches('cancelled'))->toBeTrue();

    $cs = MachineCurrentState::forInstance($rootEventId)->first();
    expect($cs->state_id)->toContain('cancelled');

    // Run the timer sweep — should find NO eligible instances
    $this->artisan('machine:process-timers', ['--class' => EveryTimerMachine::class])
        ->assertExitCode(0);

    // No timer fire record
    $fire = MachineTimerFire::where('root_event_id', $rootEventId)->first();
    expect($fire)->toBeNull();

    Bus::assertNothingBatched();
});

it('after timer dedup prevents double-fire even if sweep runs concurrently', function (): void {
    Bus::fake();

    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate past deadline
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    // First sweep — should fire
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class])
        ->assertExitCode(0);

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);

    // Fire record exists with status 'fired'
    $fire = MachineTimerFire::where('root_event_id', $rootEventId)->first();
    expect($fire)->not->toBeNull();
    expect($fire->status)->toBe(MachineTimerFire::STATUS_FIRED);

    Bus::fake(); // reset

    // Second sweep — dedup should prevent re-fire
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class])
        ->assertExitCode(0);

    Bus::assertNothingBatched();

    // Fire count should still be 1
    $fire->refresh();
    expect($fire->fire_count)->toBe(1);
});

it('TestMachine advanceTimers does not fire when state has no timer', function (): void {
    // After paying, the machine is in 'processing' which has no timer
    AfterTimerMachine::test()
        ->send('PAY')
        ->assertState('processing')
        ->advanceTimers(Timer::days(30))
        ->assertState('processing')  // unchanged — no timer on 'processing'
        ->assertTimerNotFired('ORDER_EXPIRED');
});

it('TestMachine: timer fires on correct state then ignored after exit', function (): void {
    // Full lifecycle: timer fires while in awaiting_payment
    // Then verify re-entering is not possible (machine is in cancelled)
    $test = AfterTimerMachine::test()
        ->assertState('awaiting_payment')
        ->assertHasTimer('ORDER_EXPIRED')
        ->advanceTimers(Timer::days(8))
        ->assertState('cancelled')
        ->assertTimerFired('ORDER_EXPIRED')
        ->assertFinished();

    // Machine is now in final state — timer should not affect anything further
    expect($test)->not->toBeNull();
});
