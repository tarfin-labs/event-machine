<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scheduling;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;

/**
 * Registers timer sweep commands with the Laravel Scheduler.
 *
 * Usage in routes/console.php:
 *
 *   MachineTimer::register(OrderMachine::class);
 *   MachineTimer::register(BillingMachine::class)->everyFiveMinutes();
 */
class MachineTimer
{
    /**
     * Register a timer sweep for a machine class.
     *
     * Defaults: everyMinute, withoutOverlapping, runInBackground.
     * Override frequency by chaining on the returned SchedulingEvent.
     *
     * @param  string  $machineClass  FQCN of the machine class with timer transitions.
     *
     * @return SchedulingEvent Laravel scheduling event for fluent chaining.
     */
    public static function register(string $machineClass): SchedulingEvent
    {
        /** @var Schedule $schedule */
        $schedule = resolve(Schedule::class);

        return $schedule->command("machine:process-timers --class={$machineClass}")
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();
    }
}
