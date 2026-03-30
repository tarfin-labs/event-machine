<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

/**
 * Thrown when MachineFailure::fromException() cannot resolve a required constructor parameter
 * that is not in the THROWABLE_GETTERS mapping.
 */
class MachineFailureResolutionException extends RuntimeException
{
    public static function unresolvedParam(
        string $failureClass,
        string $paramName,
    ): self {
        return new self(
            "Cannot resolve required parameter '{$paramName}' for {$failureClass} from Throwable. "
            .'Override fromException() to provide custom mapping.'
        );
    }
}
