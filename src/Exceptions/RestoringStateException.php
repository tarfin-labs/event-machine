<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

/**
 * Class RestoringStateException.
 *
 * This class represents an exception that is thrown when an error occurs during state restoration.
 * It extends the RuntimeException class.
 */
class RestoringStateException extends RuntimeException
{
    /**
     * Build a new instance of the class with the given error message.
     *
     * @param  string  $errorMessage  The error message.
     *
     * @return self The new instance of the class with the given error message.
     */
    public static function build(string $errorMessage): self
    {
        return new self($errorMessage);
    }
}
