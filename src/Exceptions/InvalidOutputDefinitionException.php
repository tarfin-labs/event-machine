<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

/**
 * Thrown when an output definition is placed on a state that cannot have one.
 */
class InvalidOutputDefinitionException extends LogicException
{
    /**
     * Output defined on a transient state (@always) which is never observed by consumers.
     */
    public static function transientState(string $stateRoute): self
    {
        return new self(
            message: "Cannot define output on transient state '{$stateRoute}' — @always states are never observed by consumers."
        );
    }

    /**
     * Output defined on a parallel region state or its children.
     * Only the parallel state itself can define output.
     */
    public static function parallelRegionState(string $stateRoute): self
    {
        return new self(
            message: "Cannot define output on parallel region state '{$stateRoute}' — only the parallel state itself can define output."
        );
    }
}
