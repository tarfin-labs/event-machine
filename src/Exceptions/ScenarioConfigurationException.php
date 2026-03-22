<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

class ScenarioConfigurationException extends RuntimeException
{
    public static function machineMismatch(string $parentScenario, string $parentMachine, string $childScenario, string $childMachine): self
    {
        return new self(
            "Machine mismatch in scenario chain: {$childScenario} targets {$childMachine} but its parent {$parentScenario} targets {$parentMachine}. All scenarios in a composition chain must target the same machine.",
        );
    }
}
