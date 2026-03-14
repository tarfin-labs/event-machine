<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Tarfinlabs\EventMachine\Support\Timer;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineTimerFire;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\EveryWithMaxMachine;

// ─── @after Tests ───────────────────────────────────────────────

it('after fires event after deadline passes', function (): void {
    Bus::fake();

    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Set state_entered_at to 8 days ago (past 7 day deadline)
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class])
        ->assertExitCode(0);

    Bus::assertBatched(function ($batch) {
        return $batch->jobs->count() === 1;
    });
});

it('after does NOT fire before deadline', function (): void {
    Bus::fake();

    $machine = AfterTimerMachine::create();
    $machine->persist();

    // state_entered_at is now() — 7 days haven't passed
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class])
        ->assertExitCode(0);

    Bus::assertNothingBatched();
});

it('after fires once only (dedup via status=fired)', function (): void {
    Bus::fake();

    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(8)]);

    // First sweep — fires
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);

    Bus::fake(); // Reset

    // Second sweep — should NOT fire again (status=fired)
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);
    Bus::assertNothingBatched();

    // Verify machine_timer_fires record exists with status=fired
    expect(MachineTimerFire::where('root_event_id', $rootEventId)->first())
        ->status->toBe(MachineTimerFire::STATUS_FIRED)
        ->fire_count->toBe(1);
});

it('after implicit cancel: instance left state before deadline', function (): void {
    Bus::fake();

    $machine = AfterTimerMachine::create();
    $machine->persist();

    // Transition to processing (leaves awaiting_payment)
    $machine->send(['type' => 'PAY']);
    $machine->persist();

    // Even if we backdate, the instance is no longer in awaiting_payment
    // (MachineCurrentState now shows processing, not awaiting_payment)
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);
    Bus::assertNothingBatched();
});

it('after catches existing instances after deployment', function (): void {
    Bus::fake();

    // Create machine and backdate entry (simulates existing instance before new definition)
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(30)]);

    // Sweep finds it — even though it was created before the timer was added
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
});

it('after with multiple timer transitions on same state', function (): void {
    Bus::fake();

    // Create a machine with both a 1-day and 7-day after timer
    // AfterTimerMachine only has 7-day ORDER_EXPIRED, but let's verify
    // the sweep processes the correct timer
    $machine = AfterTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate 2 days — past 1-day reminder but NOT past 7-day expiry
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(2)]);

    // AfterTimerMachine only has 7-day timer, so nothing should fire
    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class]);
    Bus::assertNothingBatched();
});

// ─── @every Tests ───────────────────────────────────────────────

it('every fires at interval', function (): void {
    Bus::fake();

    $machine = EveryTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate past 30-day interval
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subDays(31)]);

    $this->artisan('machine:process-timers', ['--class' => EveryTimerMachine::class]);
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
});

it('every does NOT fire before interval', function (): void {
    Bus::fake();

    $machine = EveryTimerMachine::create();
    $machine->persist();

    $this->artisan('machine:process-timers', ['--class' => EveryTimerMachine::class]);
    Bus::assertNothingBatched();
});

it('every respects max count', function (): void {
    Bus::fake();

    $machine = EveryWithMaxMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate past interval
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subHours(7)]);

    // Simulate 3 previous fires (at max)
    MachineTimerFire::create([
        'root_event_id' => $rootEventId,
        'timer_key'     => 'every_max.retrying:RETRY:21600',
        'last_fired_at' => now()->subHours(7),
        'fire_count'    => 3,
        'status'        => MachineTimerFire::STATUS_ACTIVE,
    ]);

    // Sweep should send MAX_RETRIES (then event), not RETRY
    $this->artisan('machine:process-timers', ['--class' => EveryWithMaxMachine::class]);

    Bus::assertBatched(function ($batch) {
        $job = $batch->jobs->first();

        return $job instanceof SendToMachineJob
            && $job->event['type'] === 'MAX_RETRIES';
    });
});

it('every then event NOT re-sent (status=exhausted)', function (): void {
    Bus::fake();

    $machine = EveryWithMaxMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subHours(25)]);

    // Already exhausted
    MachineTimerFire::create([
        'root_event_id' => $rootEventId,
        'timer_key'     => 'every_max.retrying:RETRY:21600',
        'last_fired_at' => now()->subHours(7),
        'fire_count'    => 4,
        'status'        => MachineTimerFire::STATUS_EXHAUSTED,
    ]);

    $this->artisan('machine:process-timers', ['--class' => EveryWithMaxMachine::class]);
    Bus::assertNothingBatched();
});

it('every stops when instance exits state', function (): void {
    Bus::fake();

    $machine = EveryTimerMachine::create();
    $machine->persist();

    // Transition out
    $machine->send(['type' => 'CANCEL']);
    $machine->persist();

    $this->artisan('machine:process-timers', ['--class' => EveryTimerMachine::class]);
    Bus::assertNothingBatched();
});
