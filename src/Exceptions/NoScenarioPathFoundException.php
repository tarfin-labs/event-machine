<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

/**
 * Thrown when no path connects source to target in the machine definition.
 */
class NoScenarioPathFoundException extends RuntimeException
{
    public static function noPath(string $source, string $target, string $machineClass): self
    {
        return new self(
            message: "No path from '{$source}' to '{$target}' in {$machineClass}. Check that the states are connected by transitions."
        );
    }
}
