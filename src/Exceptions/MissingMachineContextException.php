<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

/**
 * Exception class to be thrown when a machine context is missing.
 *
 * This exception extends the RuntimeException class and is used to indicate that a specific key is missing in the machine context.
 * It provides a static build method that creates and returns a new instance of the exception with a custom error message.
 */
class MissingMachineContextException extends RuntimeException
{
    /**
     * Builds and returns a new instance of the class.
     *
     * @param  string  $key The missing key in context.
     *
     * @return self A new instance of the class with the missing key in context.
     */
    public static function build(string $key): self
    {
        return new self("`{$key}` is missing in context.");
    }
}
