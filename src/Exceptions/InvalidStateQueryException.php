<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a Machine::query() builder receives an invalid state reference.
 */
class InvalidStateQueryException extends InvalidArgumentException
{
    public static function stateNotFound(string $stateName, string $machineId): self
    {
        return new self(
            message: "State '{$stateName}' not found in machine definition '{$machineId}'."
        );
    }
}
