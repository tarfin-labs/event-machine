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
}
