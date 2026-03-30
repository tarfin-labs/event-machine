<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

/**
 * Thrown when a required named parameter is not provided in the behavior tuple config.
 */
class MissingBehaviorParameterException extends LogicException
{
    public static function build(string $behaviorClass, string $paramName, string $paramType): self
    {
        return new self(
            message: "{$behaviorClass} requires parameter '{$paramName}' ({$paramType}) but it was not provided in the definition."
        );
    }
}
