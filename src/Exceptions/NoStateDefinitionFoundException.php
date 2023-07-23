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
class NoStateDefinitionFoundException extends RuntimeException
{
    /**
     * Builds an instance of self.
     *
     *
     * @return self The newly created instance of self.
     */
    public static function build(
        string $from,
        string $to,
        string $eventType,
    ): self {
        return new self(message: "No transition defined in the event machine from state '{$from}' to state '{$to}' for the event type '{$eventType}'. ".
            'Please ensure that a transition for this event type is defined in the current state definition.'
        );
    }
}
