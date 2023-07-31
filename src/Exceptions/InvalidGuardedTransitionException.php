<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

class InvalidGuardedTransitionException extends LogicException
{
    public static function build(string $event, string $stateDefinition): self
    {
        return new self(message: 'Validation Guard Behavior is not allowed inside guarded transitions. '.
            "Error occurred during event '{$event}' in state definition '{$stateDefinition}'.");
    }
}
