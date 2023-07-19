<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use Exception;

class MissingMachineContextException extends Exception
{
    public static function build(string $key): self
    {
        return new self("`{$key}` is missing in context.");
    }
}
