<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

/**
 * Thrown when a behavior definition has an invalid tuple format.
 */
class InvalidBehaviorDefinitionException extends LogicException
{
    /**
     * Tuple has no class reference or inline key at position [0].
     */
    public static function missingClassAtZero(string $context): self
    {
        return new self(
            message: "Invalid behavior tuple in {$context} — position [0] must be a class reference or inline key string."
        );
    }

    /**
     * Tuple is empty.
     */
    public static function emptyTuple(string $context): self
    {
        return new self(
            message: "Invalid behavior tuple in {$context} — tuple cannot be empty."
        );
    }

    /**
     * Closure used as [0] in a tuple — closures cannot receive named params.
     */
    public static function closureInTuple(string $context): self
    {
        return new self(
            message: "Invalid behavior tuple in {$context} — closures cannot receive named parameters. Use a class-based behavior instead."
        );
    }
}
