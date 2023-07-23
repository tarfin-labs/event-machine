<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

/**
 * Class NoTransitionDefinitionFoundException.
 *
 * This exception is thrown when a transition definition cannot be found
 * for a given event type in the current state definition.
 */
class NoTransitionDefinitionFoundException extends RuntimeException
{
    /**
     * Builds an instance of self.
     *
     * @param  string  $eventType The event type.
     * @param  string  $stateDefinitionId The state definition id.
     *
     * @return self The newly created instance of self.
     */
    public static function build(string $eventType, string $stateDefinitionId): self
    {
        return new self(message: "No transition definition found for the event type '{$eventType}' in the current state definition '{$stateDefinitionId}'. ".
            'Make sure that a transition is defined for this event type in the current state definition.'
        );
    }
}
