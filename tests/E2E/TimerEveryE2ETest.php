<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineTimerFire;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryWithMaxMachine;

// ─── @every Basic Pipeline ──────────────────────────────────────

it('E2E: @every fires and runs action via real pipeline', function (): void {
    $machine = EveryTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(31)]);

    $this->artisan('machine:process-timers', ['--class' => EveryTimerMachine::class])
        ->assertExitCode(0);

    $restored = EveryTimerMachine::create(state: $rootEventId);

    expect($restored->state->currentStateDefinition->id)->toBe('every_timer.active')
        ->and($restored->state->context->get('billingCount'))->toBe(1);

    $fire = MachineTimerFire::where('root_event_id', $rootEventId)->first();
    expect($fire)->not->toBeNull()
        ->and($fire->fire_count)->toBe(1)
        ->and($fire->status)->toBe(MachineTimerFire::STATUS_ACTIVE);
});

// ─── @every Multiple Fires ──────────────────────────────────────

it('E2E: @every fires multiple times across sweep cycles', function (): void {
    $machine = EveryTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Cycle 1
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(31)]);

    $this->artisan('machine:process-timers', ['--class' => EveryTimerMachine::class]);

    $restored = EveryTimerMachine::create(state: $rootEventId);
    expect($restored->state->context->get('billingCount'))->toBe(1);

    // Cycle 2: backdate last_fired_at
    MachineTimerFire::where('root_event_id', $rootEventId)
        ->update(['last_fired_at' => now()->subDays(31)]);

    $this->artisan('machine:process-timers', ['--class' => EveryTimerMachine::class]);

    $restored = EveryTimerMachine::create(state: $rootEventId);
    expect($restored->state->context->get('billingCount'))->toBe(2);

    // Cycle 3
    MachineTimerFire::where('root_event_id', $rootEventId)
        ->update(['last_fired_at' => now()->subDays(31)]);

    $this->artisan('machine:process-timers', ['--class' => EveryTimerMachine::class]);

    $restored = EveryTimerMachine::create(state: $rootEventId);
    expect($restored->state->context->get('billingCount'))->toBe(3);

    // Verify fire_count
    $fire = MachineTimerFire::where('root_event_id', $rootEventId)->first();
    expect($fire->fire_count)->toBe(3);
});

// ─── @every Max/Then ────────────────────────────────────────────

it('E2E: @every max/then fires then event exactly once', function (): void {
    $machine = EveryWithMaxMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // 3 retry cycles
    for ($i = 1; $i <= 3; $i++) {
        MachineCurrentState::forInstance($rootEventId)
            ->update(['state_entered_at' => now()->subHours(7)]);
        MachineTimerFire::where('root_event_id', $rootEventId)
            ->update(['last_fired_at' => now()->subHours(7)]);

        $this->artisan('machine:process-timers', ['--class' => EveryWithMaxMachine::class]);

        $restored = EveryWithMaxMachine::create(state: $rootEventId);
        expect($restored->state->context->get('retryCount'))->toBe($i);
    }

    // Cycle 4: max reached → MAX_RETRIES sent → machine transitions to failed
    MachineTimerFire::where('root_event_id', $rootEventId)
        ->update(['last_fired_at' => now()->subHours(7)]);

    $this->artisan('machine:process-timers', ['--class' => EveryWithMaxMachine::class]);

    $restored = EveryWithMaxMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('every_max.failed');

    // Verify exhausted
    $fire = MachineTimerFire::where('root_event_id', $rootEventId)->first();
    expect($fire->status)->toBe(MachineTimerFire::STATUS_EXHAUSTED);

    // Cycle 5: nothing happens (exhausted)
    $this->artisan('machine:process-timers', ['--class' => EveryWithMaxMachine::class]);

    $stillFailed = EveryWithMaxMachine::create(state: $rootEventId);
    expect($stillFailed->state->currentStateDefinition->id)->toBe('every_max.failed');
});

// ─── @every Interval Reset ──────────────────────────────────────

it('E2E: @every interval resets from last fire not state entry', function (): void {
    $machine = EveryTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // State entered 60 days ago
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(60)]);

    // First fire
    $this->artisan('machine:process-timers', ['--class' => EveryTimerMachine::class]);

    $restored = EveryTimerMachine::create(state: $rootEventId);
    expect($restored->state->context->get('billingCount'))->toBe(1);

    // Immediate second sweep — should NOT fire (last_fired_at = now, interval not passed)
    $this->artisan('machine:process-timers', ['--class' => EveryTimerMachine::class]);

    $restored = EveryTimerMachine::create(state: $rootEventId);
    expect($restored->state->context->get('billingCount'))->toBe(1); // still 1

    // Backdate last_fired_at — should fire again
    MachineTimerFire::where('root_event_id', $rootEventId)
        ->update(['last_fired_at' => now()->subDays(31)]);

    $this->artisan('machine:process-timers', ['--class' => EveryTimerMachine::class]);

    $restored = EveryTimerMachine::create(state: $rootEventId);
    expect($restored->state->context->get('billingCount'))->toBe(2);
});

// ─── @every Stops on Exit ───────────────────────────────────────

it('E2E: @every stops when machine exits state', function (): void {
    $machine = EveryTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // First fire
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(31)]);

    $this->artisan('machine:process-timers', ['--class' => EveryTimerMachine::class]);

    $restored = EveryTimerMachine::create(state: $rootEventId);
    expect($restored->state->context->get('billingCount'))->toBe(1);

    // Exit state
    $restored->send(['type' => 'CANCEL']);
    $restored->persist();

    // Backdate and sweep — should NOT fire (machine left state)
    MachineTimerFire::where('root_event_id', $rootEventId)
        ->update(['last_fired_at' => now()->subDays(31)]);

    $this->artisan('machine:process-timers', ['--class' => EveryTimerMachine::class]);

    $final = EveryTimerMachine::create(state: $rootEventId);
    expect($final->state->context->get('billingCount'))->toBe(1); // still 1
    expect($final->state->currentStateDefinition->id)->toBe('every_timer.cancelled');
});
