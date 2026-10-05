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
        ->toContain("--class='".AfterTimerMachine::class."'");
});

it('register shell-escapes a namespaced class name', function (): void {
    // The scheduler runs this command line through /bin/sh. Unquoted, sh strips the
    // backslashes and the sweep looks for "AppMachinesFooBarMachine" — and fails unseen.
    $event = MachineTimer::register('App\\Machines\\Foo\\BarMachine');

    expect($event->command)
        ->toEndWith("machine:process-timers --class='App\\Machines\\Foo\\BarMachine'")
        ->and($event->buildCommand())->toContain("--class='App\\Machines\\Foo\\BarMachine'");
});

it('register gives each machine class its own overlap mutex', function (): void {
    $first  = MachineTimer::register(AfterTimerMachine::class);
    $second = MachineTimer::register(TrafficLightsMachine::class);
    $again  = MachineTimer::register(AfterTimerMachine::class);

    expect($first->mutexName())->not->toBe($second->mutexName())
        ->and($again->mutexName())->toBe($first->mutexName());
});

it('register keeps withoutOverlapping and runInBackground after a frequency override', function (): void {
    $event = MachineTimer::register(AfterTimerMachine::class)->everyFiveMinutes();

    expect($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->command)->toContain("--class='".AfterTimerMachine::class."'");
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
