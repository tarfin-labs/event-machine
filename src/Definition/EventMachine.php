<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use RuntimeException;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\MachineActor;
use Tarfinlabs\EventMachine\Exceptions\RestoringStateException;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;

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
    abstract public static function build(): MachineDefinition;

    /**
     * Starts the machine as an actor.
     *
     * @param  State|string|null  $state The starting state of the machine (optional).
     *
     * @return MachineActor The instance of the machine actor.
     *
     * @throws BehaviorNotFoundException|RestoringStateException
     */
    public static function start(State|string|null $state = null): MachineActor
    {
        return new MachineActor(
            definition: static::build(),
            state: $state
        );
    }
}
