<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

class MissingMachineContextException extends RuntimeException
{
    public static function build(string $key): self
    {
        return new self("`{$key}` is missing in context.");
    }
}
