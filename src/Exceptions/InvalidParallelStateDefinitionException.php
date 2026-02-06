<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

/**
 * Represents an exception that is thrown when a parallel state is incorrectly defined in a state machine.
 */
class InvalidParallelStateDefinitionException extends LogicException
{
    /**
     * Creates an instance of the class with an error message indicating that the specified parallel state must have child states (regions).
     *
     * @param  string  $stateDefinition  The definition of the parallel state.
     *
     * @return self The instance of the class with the error message.
     */
    public static function requiresChildStates(string $stateDefinition): self
    {
        return new self(message: "The parallel state `{$stateDefinition}` must have child states (regions). ".
            'Please define at least one region within the parallel state.'
        );
    }

    /**
     * Creates an instance of the class with an error message indicating that the specified parallel state cannot have an initial property.
     *
     * @param  string  $stateDefinition  The definition of the parallel state.
     *
     * @return self The instance of the class with the error message.
     */
    public static function cannotHaveInitial(string $stateDefinition): self
    {
        return new self(message: "The parallel state `{$stateDefinition}` cannot have an 'initial' property. ".
            'All regions in a parallel state are entered simultaneously.'
        );
    }
}
