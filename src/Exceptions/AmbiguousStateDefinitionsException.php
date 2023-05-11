<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use Exception;

class AmbiguousStateDefinitionsException extends Exception
{
    public static function build(string $state, array $states): self
    {
        return new self(
            message: sprintf(
                "Multiple '%s' state definitions create ambiguity and could result in unexpected behavior.".
                'The conflicting state definitions are located in the following paths: %s.',
                $state,
                implode(', ', array_keys($states))
            )
        );
    }
}
