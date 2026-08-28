<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\MachineDefinitionNotFoundException;

/**
 * Turn a caller-supplied class name into a definition, or a clean failure.
 *
 * Two things have to be true before `$class::definition()` is worth calling, and
 * `class_exists()` alone establishes neither. The class must actually be a Machine —
 * otherwise the command invokes a static method on a stranger, and gets a TypeError
 * where an exit code belonged. And it must actually define a machine: `Machine` itself
 * declares `definition()` as a thrower, so an abstract or half-written subclass passes
 * every structural check and then raises `MachineDefinitionNotFoundException` from
 * inside the call. Both used to reach the user as a stack trace.
 */
trait ResolvesMachineDefinition
{
    /**
     * Returns null after printing the reason, so the caller can `return self::FAILURE`.
     */
    protected function machineDefinitionFor(string $class): ?MachineDefinition
    {
        if (!class_exists($class) || !is_subclass_of($class, Machine::class)) {
            $this->error("Machine class not found: {$class}");

            return null;
        }

        try {
            $definition = $class::definition();
        } catch (MachineDefinitionNotFoundException $e) {
            $this->error("{$class}: {$e->getMessage()}");

            return null;
        }

        if (!$definition instanceof MachineDefinition) {
            $this->error("{$class}::definition() returned no machine definition.");

            return null;
        }

        return $definition;
    }
}
