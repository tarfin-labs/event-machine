<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use RuntimeException;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * EventMachine is an abstract base class for creating state machines.
 * Subclasses should override the definition method to provide a specific
 * MachineDefinition.
 */
abstract class EventMachine
{
    /**
     * Provides the MachineDefinition for the current state machine.
     * Subclasses should override this method to return a specific
     * MachineDefinition.
     *
     * @return MachineDefinition The state machine's definition
     *
     * @throws RuntimeException if the method is not overridden by a subclass
     */
    abstract public static function definition(): MachineDefinition;
}
