<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;
use Tarfinlabs\EventMachine\Actor\Machine;

class InvalidMachineClassException extends LogicException
{
    public static function mustExtendMachine(string $machineClass): self
    {
        return new self("Machine class '{$machineClass}' must exist and extend ".Machine::class.'.');
    }
}
