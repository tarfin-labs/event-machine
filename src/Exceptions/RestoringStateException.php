<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use Exception;

class RestoringStateException extends Exception
{
    public static function build(string $errorMessage): self
    {
        return new self($errorMessage);
    }
}
