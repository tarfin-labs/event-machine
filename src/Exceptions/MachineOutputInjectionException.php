<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use RuntimeException;

/**
 * Thrown when a forward endpoint OutputBehavior type-hints a MachineOutput subclass
 * but the child machine's current state does not define a MachineOutput.
 */
class MachineOutputInjectionException extends RuntimeException
{
    public static function missingChildOutput(
        string $outputBehaviorClass,
        string $expectedOutputClass,
        string $childMachineClass,
        string $childStateName,
    ): self {
        return new self(
            "{$outputBehaviorClass} expects {$expectedOutputClass} but {$childMachineClass} in state '{$childStateName}' has no MachineOutput defined. "
            ."Define 'output' => {$expectedOutputClass}::class on the '{$childStateName}' state."
        );
    }
}
