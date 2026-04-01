<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

/**
 * Thrown when MachineInput::fromContext() cannot resolve a required constructor parameter.
 */
class MachineInputValidationException extends RuntimeException
{
    /**
     * @param  array<int, string>  $availableKeys
     */
    public static function missingField(
        string $inputClass,
        string $paramName,
        array $availableKeys,
    ): self {
        $keyList = $availableKeys !== [] ? implode(', ', $availableKeys) : '(empty)';

        return new self(
            "{$inputClass} input validation failed: missing required field '{$paramName}' — parent context has: [{$keyList}]"
        );
    }
}
