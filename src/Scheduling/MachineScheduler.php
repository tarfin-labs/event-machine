<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scheduling;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Tarfinlabs\EventMachine\Exceptions\InvalidScheduleDefinitionException;
use Tarfinlabs\EventMachine\Exceptions\MachineDefinitionNotFoundException;

/**
 * Registers machine schedule entries with the Laravel Scheduler.
 *
 * Usage in routes/console.php:
 *
 *   MachineScheduler::register(ApplicationMachine::class, 'CHECK_EXPIRY')
 *       ->dailyAt('00:10')
 *       ->environments(['production'])
 *       ->onOneServer();
 *
 * Returns Laravel's SchedulingEvent so ALL fluent methods are available.
 */
class MachineScheduler
{
    /**
     * Register a scheduled event for a machine class.
     *
     * @param  string  $machineClass  FQCN of the machine class
     * @param  string  $eventType  The event type to dispatch (e.g. 'CHECK_EXPIRY')
     *
     * @return SchedulingEvent Laravel scheduling event for fluent chaining
     */
    public static function register(string $machineClass, string $eventType): SchedulingEvent
    {
        try {
            $definition = $machineClass::definition();
        } catch (\Throwable $e) {
            throw MachineDefinitionNotFoundException::failedToLoad($machineClass, $e);
        }

        if ($definition->parsedSchedules === null || !isset($definition->parsedSchedules[$eventType])) {
            throw InvalidScheduleDefinitionException::undefinedEvent($eventType);
        }

        /** @var Schedule $schedule */
        $schedule = resolve(Schedule::class);

        return $schedule->command('machine:process-scheduled', [
            '--class' => $machineClass,
            '--event' => $eventType,
        ]);
    }
}
