<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Throwable;
use ReflectionClass;
use InvalidArgumentException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\StateConfigValidator;

class MachineConfigValidatorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'machine:validate {machine?*} {--all : Validate all machines in the project}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate machine configuration for potential issues';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        if ($this->option(key: 'all')) {
            $this->validateAllMachines();

            return;
        }

        $machines = $this->argument(key: 'machine');
        if (empty($machines)) {
            $this->error(string: 'Please provide a machine class name or use --all option.');

            return;
        }

        foreach ($machines as $machine) {
            $this->validateMachine($machine);
        }
    }

    /**
     * Validate a single machine configuration.
     */
    protected function validateMachine(string $machineClass): void
    {
        try {
            // Find the full class name if short name is provided
            $fullClassName = $this->findMachineClass($machineClass);

            if (!$fullClassName) {
                $this->error(string: "Machine class '{$machineClass}' not found.");

                return;
            }

            // Check if class exists and is a Machine
            if (!is_subclass_of(object_or_class: $fullClassName, class: Machine::class)) {
                $this->error(string: "Class '{$fullClassName}' is not a Machine.");

                return;
            }

            // Get machine definition and validate
            $definition = $fullClassName::definition();
            if ($definition === null) {
                $this->error(string: "Machine '{$fullClassName}' has no definition.");

                return;
            }

            StateConfigValidator::validate($definition->config);
            $this->info(string: "✓ Machine '{$fullClassName}' configuration is valid.");

        } catch (InvalidArgumentException $e) {
            $this->error(string: "Configuration error in '{$fullClassName}':");
            $this->error(string: $e->getMessage());
        } catch (Throwable $e) {
            $this->error(string: "Error validating '{$machineClass}':");
            $this->error(string: $e->getMessage());
        }
    }

    /**
     * Find machine class by name or FQN.
     */
    protected function findMachineClass(string $class): ?string
    {
        // If it's already a FQN and exists, return it
        if (class_exists($class)) {
            return $class;
        }

        // Get all potential machine classes
        $machineClasses = $this->findMachineClasses();

        // First try exact match
        foreach ($machineClasses as $fqn) {
            if (str_ends_with($fqn, "\\{$class}")) {
                return $fqn;
            }
        }

        // Then try case-insensitive match
        $lowercaseClass = strtolower($class);
        foreach ($machineClasses as $fqn) {
            if (str_ends_with(strtolower($fqn), "\\{$lowercaseClass}")) {
                return $fqn;
            }
        }

        return null;
    }

    /**
     * Find all potential machine classes in the project.
     *
     * @return array<string>
     */
    protected function findMachineClasses(): array
    {
        $machineClasses = [];

        // Get package path
        $packagePath = (new ReflectionClass(objectOrClass: Machine::class))->getFileName();
        $packagePath = dirname($packagePath, levels: 3); // Go up to package root

        // Check tests directory
        $testsPath = $packagePath.'/tests';
        if (File::exists($testsPath)) {
            $this->findMachineClassesInDirectory(directory: $testsPath, machineClasses: $machineClasses);
        }

        // Check src directory
        $srcPath = $packagePath.'/src';
        if (File::exists($srcPath)) {
            $this->findMachineClassesInDirectory(directory: $srcPath, machineClasses: $machineClasses);
        }

        return array_values(array_unique($machineClasses));
    }

    /**
     * Find machine classes in a specific directory.
     *
     * @param  array<string>  $machineClasses
     */
    protected function findMachineClassesInDirectory(string $directory, array &$machineClasses): void
    {
        // Find all PHP files recursively
        $files = File::allFiles($directory);

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            // Get file contents
            $contents = File::get($file->getRealPath());

            // Extract namespace
            preg_match(pattern: '/namespace\s+([^;]+)/i', subject: $contents, matches: $namespaceMatches);
            if (empty($namespaceMatches[1])) {
                continue;
            }
            $namespace = trim($namespaceMatches[1]);

            // Extract class name
            preg_match(pattern: '/class\s+(\w+)/i', subject: $contents, matches: $classMatches);
            if (empty($classMatches[1])) {
                continue;
            }
            $className = trim($classMatches[1]);

            // Create FQN
            $fqn = $namespace.'\\'.$className;

            // Check if class exists and is a Machine
            if (class_exists($fqn) && is_subclass_of($fqn, Machine::class)) {
                $machineClasses[] = $fqn;
            }
        }
    }

    /**
     * Validate all machines in the project.
     */
    protected function validateAllMachines(): void
    {
        $validated = 0;
        $failed    = 0;

        $machineClasses = $this->findMachineClasses();

        foreach ($machineClasses as $class) {
            try {
                $definition = $class::definition();
                if ($definition === null) {
                    $this->warn(string: "Machine '{$class}' has no definition.");
                    $failed++;

                    continue;
                }

                StateConfigValidator::validate($definition->config);
                $this->info(string: "✓ Machine '{$class}' configuration is valid.");
                $validated++;
            } catch (Throwable $e) {
                $this->error(string: "✗ Error in '{$class}': ".$e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info(string: "Validation complete: {$validated} valid, {$failed} failed");
    }
}
