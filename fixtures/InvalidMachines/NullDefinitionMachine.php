<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Fixtures\InvalidMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * A Machine subclass whose definition() returns null.
 *
 * The return type is `?MachineDefinition`, so null is a legal answer that no
 * exception announces — the command has to check the value it got back.
 *
 * In fixtures/ rather than tests/Stubs/ so machine:validate --all does not sweep it.
 */
class NullDefinitionMachine extends Machine
{
    public static function definition(): ?MachineDefinition
    {
        return null;
    }
}
