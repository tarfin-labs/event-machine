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
    public static function build(string $from, string $to): self
    {
        return new self(message: "No state definition found from '{$from}' to '{$to}'. ".
            'Make sure that a transition is defined for this event type in the current state definition.'
        );
    }
}
