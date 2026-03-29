<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use InvalidArgumentException;

class InvalidJobClassException extends InvalidArgumentException
{
    public static function doesNotExist(string $jobClass): self
    {
        return new self("Job class '{$jobClass}' does not exist.");
    }

    public static function missingHandleMethod(string $jobClass): self
    {
        return new self("Job class '{$jobClass}' must have a handle() method.");
    }
}
