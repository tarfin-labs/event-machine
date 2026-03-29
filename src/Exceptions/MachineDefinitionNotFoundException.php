<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

class MachineDefinitionNotFoundException extends RuntimeException
{
    public static function build(): self
    {
        return new self(
            message: 'The machine definition is not defined for the requested machine. '.
            "Ensure that the machine's definition is properly configured inside the `definition()` method of the machine class."
        );
    }

    public static function failedToLoad(string $machineClass, \Throwable $previous): self
    {
        return new self(
            message: "MachineScheduler::register() failed to load definition for {$machineClass}: {$previous->getMessage()}",
            code: $previous->getCode(),
            previous: $previous,
        );
    }

    public static function undefinedScheduleEvent(string $eventType, string $machineClass): self
    {
        return new self("Event '{$eventType}' is not defined in schedules for {$machineClass}.");
    }
}
