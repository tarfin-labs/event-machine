<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

class MachineAlreadyRunningException extends RuntimeException
{
    /**
     * Builds and returns a new instance of the class.
     *
     * @param  string  $machineId  The ID of the machine.
     */
    public static function build(string $machineId): self
    {
        return new self(message: "Event processing failed: Machine `{$machineId}`is already running.");
    }
}
