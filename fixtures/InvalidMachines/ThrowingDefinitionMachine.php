<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Fixtures\InvalidMachines;

use RuntimeException;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * A Machine subclass whose definition() throws something other than
 * MachineDefinitionNotFoundException.
 *
 * definition() is ordinary user code — a missing behavior class, a bad config, a container
 * resolve that fails. Catching only the one exception the package raises itself left every
 * other cause reaching the user as a stack trace.
 *
 * In fixtures/ rather than tests/Stubs/ so machine:validate --all does not sweep it.
 */
class ThrowingDefinitionMachine extends Machine
{
    public static function definition(): ?MachineDefinition
    {
        throw new RuntimeException('behavior class App\\Actions\\Missing does not exist');
    }
}
