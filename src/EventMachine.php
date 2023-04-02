<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use RuntimeException;

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

    /**
     * Transitions the state machine from the given state based on the provided event.
     *
     * @param  null|string|State  $state The current state of the state machine
     * @param  array  $event The event that triggers the transition
     *
     * @return State The new state after the transition
     */
    public static function transition(null|string|State $state, array $event): State
    {
        return static::definition()->transition($state, $event);
    }
}
