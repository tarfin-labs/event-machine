<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

/**
 * Thrown when a listener definition uses the removed class-as-key format.
 */
class InvalidListenerDefinitionException extends LogicException
{
    public static function classAsKey(string $className): self
    {
        return new self(
            message: "Invalid listener format: '{$className}' used as array key. Use [{$className}::class, '@queue' => true] instead."
        );
    }
}
