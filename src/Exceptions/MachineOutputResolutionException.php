<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

/**
 * Thrown when MachineOutput::fromContext() cannot resolve a required constructor parameter.
 */
class MachineOutputResolutionException extends RuntimeException
{
    /**
     * @param  array<int, string>  $availableKeys
     */
    public static function missingField(
        string $outputClass,
        string $paramName,
        array $availableKeys,
    ): self {
        $keyList = $availableKeys !== [] ? implode(', ', $availableKeys) : '(empty)';

        return new self(
            "{$outputClass} output resolution failed: missing required field '{$paramName}' — context has: [{$keyList}]"
        );
    }

    /**
     * The output definition is not something that can be resolved into a behavior.
     *
     * A valid definition is a class-string, an inline behavior key, a callable, or a tuple
     * array. Anything else used to be handed to resolve(), which takes a string or a
     * callable — so an array or a plain object produced a container error naming neither
     * the machine nor the state it came from.
     */
    public static function unresolvableDefinition(mixed $definition): self
    {
        return new self(
            'Output definition could not be resolved: expected a class-string, an inline '
            .'behavior key, a callable or a tuple array, got '.get_debug_type($definition).'.'
        );
    }
}
