<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\SimpleChildMachine;

it('command exits successfully with valid class', function (): void {
    Bus::fake();

    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class])
        ->assertExitCode(0);
});

it('command fails without --class flag', function (): void {
    $this->artisan('machine:process-timers')
        ->assertExitCode(1);
});

it('command skips sweep when queue backpressure exceeded', function (): void {
    Bus::fake();
    Queue::fake();

    // Set threshold very low
    config(['machine.timers.backpressure_threshold' => 0]);

    // Queue::size() returns 0 with fake, but threshold is 0, so 0 > 0 is false
    // Let's set threshold to -1 to trigger
    config(['machine.timers.backpressure_threshold' => -1]);

    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class])
        ->assertExitCode(0);

    Bus::assertNothingBatched();
});

it('command respects timer_batch_size config', function (): void {
    Bus::fake();

    // Create multiple machines past deadline
    for ($i = 0; $i < 5; $i++) {
        $machine = AfterTimerMachine::create();
        $machine->persist();
    }

    // Backdate all
    MachineCurrentState::query()
        ->update(['state_entered_at' => now()->subDays(8)]);

    // Set batch size to 2
    config(['machine.timers.batch_size' => 2]);

    $this->artisan('machine:process-timers', ['--class' => AfterTimerMachine::class])
        ->assertExitCode(0);

    // Should batch only 2 (limited by batch_size)
    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);
});

it('command handles no timer-configured machines gracefully', function (): void {
    Bus::fake();

    // SimpleChildMachine has no timer config
    $this->artisan('machine:process-timers', [
        '--class' => SimpleChildMachine::class,
    ])->assertExitCode(0);

    Bus::assertNothingBatched();
});
