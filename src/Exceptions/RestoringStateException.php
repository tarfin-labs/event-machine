<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

class RestoringStateException extends RuntimeException
{
    public static function build(string $errorMessage): self
    {
        return new self($errorMessage);
    }
}
