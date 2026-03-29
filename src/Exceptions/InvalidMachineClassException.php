<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use InvalidArgumentException;
use Tarfinlabs\EventMachine\Actor\Machine;

class InvalidMachineClassException extends InvalidArgumentException
{
    public static function build(string $machineClass): self
    {
        return new self("Machine class '{$machineClass}' must exist and extend ".Machine::class.'.');
    }
}
