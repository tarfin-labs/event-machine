<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use RuntimeException;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Casts\MachineCast;
use Tarfinlabs\EventMachine\Actor\MachineActor;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Tarfinlabs\EventMachine\Exceptions\RestoringStateException;
use Tarfinlabs\EventMachine\Exceptions\BehaviorNotFoundException;

/**
 * EventMachine is an abstract base class for creating state machines.
 * Subclasses should override the definition method to provide a specific
 * MachineDefinition.
 */
abstract class EventMachine implements Castable
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
    public static function start(State|string $state = null): MachineActor
    {
        return new MachineActor(
            definition: static::build(),
            state: $state
        );
    }

    /**
     * Get the name of the caster class to use when casting from / to this cast target.
     *
     * @param  array<mixed>  $arguments
     */
    public static function castUsing(array $arguments): string
    {
        return MachineCast::class;
    }
}
