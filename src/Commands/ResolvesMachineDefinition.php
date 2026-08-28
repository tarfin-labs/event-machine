<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Throwable;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\MachineDefinitionNotFoundException;

/**
 * Turn a caller-supplied class name — or file path — into a definition, or a clean failure.
 *
 * Every command here takes something a human typed and ends up calling a static method on
 * whatever it names. Each step of that is a way to hand the user a stack trace where an exit
 * code belonged, and each one was reachable:
 *
 * - `class_exists()` alone admits any resolvable class, so `$class::definition()` called a
 *   stranger's static method and got a TypeError.
 * - `Machine` declares `definition()` as a thrower, so an abstract or half-written subclass
 *   passes every structural check and raises from inside the call.
 * - `definition()` is ordinary user code and can throw anything at all, not just the one
 *   exception this trait first learned to catch.
 * - The file-path form is worse still: it `require_once`s the file, so it runs the caller's
 *   code before any check, and the path may not be a readable, parseable PHP file — pointing
 *   the command at a DIRECTORY was enough to raise `file_get_contents(): Is a directory`.
 *
 * Everything below fails closed with a printed reason and a null return.
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
        } catch (Throwable $e) {
            // definition() is ordinary user code: a missing behavior class, a bad config, a
            // failed container resolve. Naming the class and the message is more use than the
            // trace, and the command still owes the caller an exit code.
            $this->error("{$class}::definition() failed: {$e->getMessage()}");

            return null;
        }

        if (!$definition instanceof MachineDefinition) {
            $this->error("{$class}::definition() returned no machine definition.");

            return null;
        }

        return $definition;
    }
}
