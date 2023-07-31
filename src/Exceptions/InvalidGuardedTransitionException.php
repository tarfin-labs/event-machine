<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

/**
 * Exception class representing an invalid guarded transition.
 *
 * These exceptions are thrown when attempting to use validation guard behavior inside guarded transitions.
 * It contains information about the event and state definition where the error occurred.
 */
class InvalidGuardedTransitionException extends LogicException
{
    /**
     * Builds a new instance of the class.
     *
     * @param  string  $event The event that triggered the error.
     * @param  string  $stateDefinition The state definition where the error occurred.
     *
     * @return self A new instance of the class with the specified error message.
     */
    public static function build(string $event, string $stateDefinition): self
    {
        return new self(message: 'Validation Guard Behavior is not allowed inside guarded transitions. '.
            "Error occurred during event '{$event}' in state definition '{$stateDefinition}'.");
    }
}
