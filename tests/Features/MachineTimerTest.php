<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Tarfinlabs\EventMachine\Scheduling\MachineTimer;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

it('register returns a SchedulingEvent', function (): void {
    $event = MachineTimer::register(AfterTimerMachine::class);

    expect($event)->toBeInstanceOf(SchedulingEvent::class);
});

it('register sets correct command with --class', function (): void {
    $event = MachineTimer::register(AfterTimerMachine::class);

    expect($event->command)
        ->toContain('machine:process-timers')
        ->toContain('--class='.AfterTimerMachine::class);
});

it('register applies everyMinute as default', function (): void {
    $event = MachineTimer::register(AfterTimerMachine::class);

    expect($event->expression)->toBe('* * * * *');
});

it('register applies withoutOverlapping', function (): void {
    $event = MachineTimer::register(AfterTimerMachine::class);

    expect($event->withoutOverlapping)->toBeTrue();
});

it('register applies runInBackground', function (): void {
    $event = MachineTimer::register(AfterTimerMachine::class);

    expect($event->runInBackground)->toBeTrue();
});

it('register supports frequency override via fluent chaining', function (): void {
    $event = MachineTimer::register(AfterTimerMachine::class)
        ->everyFiveMinutes();

    expect($event->expression)->toBe('*/5 * * * *');
});

it('register supports environments chaining', function (): void {
    $event = MachineTimer::register(AfterTimerMachine::class)
        ->everyMinute()
        ->environments(['production', 'staging']);

    expect($event)->toBeInstanceOf(SchedulingEvent::class);
});

it('multiple register calls create separate scheduler entries', function (): void {
    /** @var Schedule $schedule */
    $schedule = resolve(Schedule::class);
    $before   = count($schedule->events());

    MachineTimer::register(AfterTimerMachine::class);
    MachineTimer::register(TrafficLightsMachine::class);

    $after = count($schedule->events());

    expect($after - $before)->toBe(2);
});

it('register with timer-less machine does not throw', function (): void {
    // TrafficLightsMachine has no @after/@every timers.
    // register() should not throw — it just registers a scheduler entry.
    // The machine:process-timers command handles the no-op at runtime.
    $event = MachineTimer::register(TrafficLightsMachine::class);

    expect($event)->toBeInstanceOf(SchedulingEvent::class);
});
