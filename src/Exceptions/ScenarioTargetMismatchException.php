<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

/**
 * Thrown after scenario execution when machine didn't reach target.
 */
class ScenarioTargetMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $expectedTarget,
        public readonly string $actualState,
        string $message,
    ) {
        parent::__construct(message: $message);
    }

    public static function mismatch(string $expected, string $actual): self
    {
        return new self(
            expectedTarget: $expected,
            actualState: $actual,
            message: "Scenario expected to reach '{$expected}' but machine stopped at '{$actual}'. Check plan() overrides and @continue directives.",
        );
    }
}
