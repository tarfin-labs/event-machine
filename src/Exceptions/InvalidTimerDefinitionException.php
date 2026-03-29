<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

class InvalidTimerDefinitionException extends LogicException
{
    public static function nonPositiveDuration(int $seconds): self
    {
        return new self("Timer duration must be positive, got {$seconds} seconds.");
    }
}
