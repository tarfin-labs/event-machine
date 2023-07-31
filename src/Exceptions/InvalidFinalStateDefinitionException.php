<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

/**
 * Represents an exception that is thrown when a final state is incorrectly defined in a state machine.
 */
class InvalidFinalStateDefinitionException extends LogicException
{
    /**
     * Creates an instance of the class with an error message indicating that the specified final state should not have child states.
     *
     * @param  string  $stateDefinition The definition of the final state.
     *
     * @return self The instance of the class with the error message.
     */
    public static function noChildStates(string $stateDefinition): self
    {
        return new self(message: "The final state `{$stateDefinition}` should not have child states. ".
            'Please revise your state machine definitions to ensure that final states are correctly configured without child states.'
        );
    }

    /**
     * Creates an instance of the class with an error message indicating that the specified final state should not have transitions.
     *
     * @param  string  $stateDefinition The definition of the final state.
     *
     * @return self The instance of the class with the error message.
     */
    public static function noTransitions(string $stateDefinition): self
    {
        return new self(message: "The final state `{$stateDefinition}` should not have transitions. ".
            'Check your state machine configuration to ensure events are not dispatched when in a final state.'
        );
    }
}
