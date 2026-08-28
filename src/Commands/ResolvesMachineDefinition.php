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

    /**
     * Resolve a FQCN from a PHP file path by extracting its namespace and class name.
     *
     * One known sharp edge remains, and it is behavioural rather than a crash: the regex takes
     * the FIRST class in the file that extends anything, so a file declaring a helper class
     * above the machine resolves to the wrong FQCN. That fails cleanly — the caller asks the
     * wrong class for a definition and machineDefinitionFor() refuses it — but it is still the
     * wrong answer, and a proper fix means parsing rather than matching.
     */
    protected function resolveClassFromFile(string $filePath): ?string
    {
        // is_file, not file_exists: a directory satisfies file_exists, and reading one raises
        // rather than returning false. `machine:paths src/Analysis` was enough to hit it.
        if (!is_file($filePath)) {
            $filePath = base_path($filePath);

            if (!is_file($filePath)) {
                return null;
            }
        }

        if (!is_readable($filePath)) {
            $this->error("File is not readable: {$filePath}");

            return null;
        }

        $contents = file_get_contents($filePath);

        if ($contents === false) {
            $this->error("Could not read file: {$filePath}");

            return null;
        }

        $namespace = null;
        $class     = null;

        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            $namespace = trim($matches[1]);
        }

        if (preg_match('/class\s+(\w+)\s+extends/', $contents, $matches)) {
            $class = $matches[1];
        }

        if ($class === null) {
            return null;
        }

        $fqcn = $namespace !== null ? $namespace.'\\'.$class : $class;

        try {
            // Both branches execute the caller's file — require_once directly, class_exists()
            // through the autoloader — so both can raise anything the file raises, including a
            // ParseError the caller cannot otherwise see the source of.
            if (!class_exists($fqcn)) {
                require_once $filePath;
            }

            return class_exists($fqcn) ? $fqcn : null;
        } catch (Throwable $e) {
            $this->error("Loading {$filePath} failed: {$e->getMessage()}");

            return null;
        }
    }
}
