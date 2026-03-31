<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

/**
 * Thrown when scenarios feature is not enabled.
 */
class ScenariosDisabledException extends RuntimeException
{
    public static function disabled(): self
    {
        return new self(
            message: 'Machine scenarios are disabled. Set MACHINE_SCENARIOS_ENABLED=true to enable.'
        );
    }
}
