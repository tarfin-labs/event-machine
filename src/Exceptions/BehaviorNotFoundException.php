<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

/**
 * Class BehaviorNotFoundException.
 *
 * Exception thrown when a behavior of a specific type is not found.
 */
class BehaviorNotFoundException extends RuntimeException
{
    public static function build(string $behaviorType): self
    {
        return new self("Behavior of type `{$behaviorType}` not found.");
    }
}
