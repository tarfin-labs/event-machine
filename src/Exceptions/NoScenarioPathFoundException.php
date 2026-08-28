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

    /**
     * The search stopped at its iteration cap rather than exhausting the graph, so
     * whether a path exists is unknown. Distinct from noPath(), which asserts there
     * is none — reporting the two the same way is what made a truncated search look
     * like a confident answer.
     */
    public static function truncated(string $source, string $target, string $machineClass): self
    {
        return new self(
            message: "Path analysis from '{$source}' to '{$target}' in {$machineClass} was truncated at the search limit. A path may still exist — this is not a finding that none does."
        );
    }
}
