<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

/**
 * Thrown when multiple paths connect source to target.
 */
class AmbiguousScenarioPathException extends RuntimeException
{
    /** @var list<string> */
    public array $pathSignatures = [];

    public static function multiplePaths(string $source, string $target, array $signatures): self
    {
        $instance = new self(
            message: "Multiple paths from '{$source}' to '{$target}'. Use --path=N to select: ".implode(', ', $signatures)
        );
        $instance->pathSignatures = $signatures;

        return $instance;
    }
}
