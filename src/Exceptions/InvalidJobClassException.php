<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

class InvalidJobClassException extends LogicException
{
    public static function classNotFound(string $jobClass): self
    {
        return new self("Job class '{$jobClass}' does not exist.");
    }

    public static function missingHandleMethod(string $jobClass): self
    {
        return new self("Job class '{$jobClass}' must have a handle() method.");
    }
}
