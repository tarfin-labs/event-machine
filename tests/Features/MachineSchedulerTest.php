<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Scheduling\MachineScheduler;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScheduledMachines\ScheduledMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsMachine;

it('register returns a SchedulingEvent', function (): void {
    $event = MachineScheduler::register(ScheduledMachine::class, 'CHECK_EXPIRY');

    expect($event)->toBeInstanceOf(SchedulingEvent::class);
});

it('register sets the correct command with class and event options', function (): void {
    $event = MachineScheduler::register(ScheduledMachine::class, 'CHECK_EXPIRY');

    expect($event->command)
        ->toContain('machine:process-scheduled')
        ->toContain("--class='".ScheduledMachine::class."'")
        ->toContain("--event='CHECK_EXPIRY'");
});

it('register supports fluent chaining with dailyAt', function (): void {
    $event = MachineScheduler::register(ScheduledMachine::class, 'CHECK_EXPIRY')
        ->dailyAt('00:10');

    expect($event)->toBeInstanceOf(SchedulingEvent::class)
        ->and($event->expression)->toBe('10 0 * * *');
});

it('register supports environments chaining', function (): void {
    $event = MachineScheduler::register(ScheduledMachine::class, 'CHECK_EXPIRY')
        ->dailyAt('11:00')
        ->environments(['production', 'staging']);

    expect($event)->toBeInstanceOf(SchedulingEvent::class);
});

it('register throws for undefined event type', function (): void {
    MachineScheduler::register(ScheduledMachine::class, 'TYPO_EVENT');
})->throws(InvalidArgumentException::class, 'TYPO_EVENT');

it('register throws for machine with no schedules', function (): void {
    MachineScheduler::register(TrafficLightsMachine::class, 'INC');
})->throws(InvalidArgumentException::class, 'INC');
