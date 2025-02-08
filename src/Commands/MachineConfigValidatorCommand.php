<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Throwable;
use ReflectionClass;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveIteratorIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use Composer\Autoload\ClassLoader;
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
            if (!is_subclass_of($fullClassName, class: Machine::class)) {
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
            $this->error($e->getMessage());
        } catch (Throwable $e) {
            $this->error(string: "Error validating '{$machineClass}':");
            $this->error($e->getMessage());
        }
    }

    /**
     * Find machine class by name or FQN.
     */
    protected function findMachineClass(string $class): ?string
    {
        // If it's already a FQN and exists, return it
        if (class_exists(class: $class, autoload: false)) {
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

    protected function findMachineClasses(): array
    {
        $machineClasses = [];

        try {
            // Read composer.json
            $composerJson = $this->getComposerConfig();
            if (!$composerJson) {
                $this->error(string: 'Could not find or parse composer.json');

                return [];
            }

            // Get project namespaces from composer.json
            $projectNamespaces = $this->getAutoloadNamespaces($composerJson);

            // Get composer's autoloader
            $autoloaders = ClassLoader::getRegisteredLoaders();

            foreach ($autoloaders as $autoloader) {
                $prefixes = $autoloader->getPrefixesPsr4();

                // Only check project namespaces
                foreach ($projectNamespaces as $namespace => $paths) {
                    // Skip if namespace is not registered in autoloader
                    if (!isset($prefixes[$namespace])) {
                        continue;
                    }

                    foreach ($paths as $path) {
                        $this->findMachineClassesInPath(namespace: $namespace, path: $path, machineClasses: $machineClasses);
                    }
                }
            }
        } catch (Throwable $e) {
            $this->error(string: 'Error scanning for machine classes: '.$e->getMessage());
        }

        return array_values(array_unique($machineClasses));
    }

    /**
     * Get project root path considering test environment.
     */
    private function getProjectRootPath(): string
    {
        // Check if we're in testbench environment
        if (str_contains(base_path(), '/vendor/orchestra/testbench-core/laravel')) {
            // Get the package root path
            $reflection = new ReflectionClass(objectOrClass: Machine::class);

            return dirname($reflection->getFileName(), levels: 3);
        }

        return base_path();
    }

    /**
     * Get composer.json configuration.
     *
     * @throws \JsonException
     */
    private function getComposerConfig(): ?array
    {
        $rootPath     = $this->getProjectRootPath();
        $composerPath = $rootPath.'/composer.json';

        if (!file_exists($composerPath)) {
            return null;
        }

        $composerJson = json_decode(file_get_contents($composerPath), associative: true, depth: JSON_THROW_ON_ERROR, flags: JSON_THROW_ON_ERROR);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $composerJson;
    }

    /**
     * Get PSR-4 autoload namespaces from composer.json.
     *
     * @return array<string, array<string>>
     */
    private function getAutoloadNamespaces(array $composerJson): array
    {
        $namespaces = [];
        $rootPath   = $this->getProjectRootPath();

        // Check autoload section
        if (isset($composerJson['autoload']['psr-4'])) {
            foreach ($composerJson['autoload']['psr-4'] as $namespace => $path) {
                $paths                  = is_array($path) ? $path : [$path];
                $namespaces[$namespace] = array_map(function ($p) use ($rootPath) {
                    return $rootPath.'/'.ltrim($p, characters: '/');
                }, $paths);
            }
        }

        // Also check autoload-dev for development
        if (isset($composerJson['autoload-dev']['psr-4'])) {
            foreach ($composerJson['autoload-dev']['psr-4'] as $namespace => $path) {
                $paths                  = is_array($path) ? $path : [$path];
                $namespaces[$namespace] = array_map(function ($p) use ($rootPath) {
                    return $rootPath.'/'.ltrim($p, characters: '/');
                }, $paths);
            }
        }

        return $namespaces;
    }

    private function isFileMachineClass(string $filePath, string $expectedClass): bool
    {
        try {
            // Skip test files with inline class definitions
            if (str_contains($filePath, 'Test.php')) {
                return false;
            }

            // Skip if class already exists but is not a Machine
            if (class_exists($expectedClass) && !is_subclass_of($expectedClass, class: Machine::class)) {
                return false;
            }

            // Now we can safely check class existence and Machine inheritance
            if (class_exists($expectedClass)) {
                return is_subclass_of($expectedClass, class: Machine::class);
            }
        } catch (Throwable) {
            // Ignore any errors
        }

        return false;
    }

    private function findMachineClassesInPath(string $namespace, string $path, array &$machineClasses): void
    {
        try {
            if (!is_dir($path)) {
                return;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, flags: FilesystemIterator::SKIP_DOTS)
            );

            $namespace = rtrim($namespace, characters: '\\');

            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                // Clean up the path
                $relativePath = str_replace(
                    search: $path,
                    replace: '',
                    subject: $file->getRealPath()
                );

                // Convert path to namespace format
                $relativePath = trim($relativePath, characters: '/');
                $classPath    = str_replace(search: '/', replace: '\\', subject: $relativePath);
                $classPath    = preg_replace(pattern: '/\.php$/', replacement: '', subject: $classPath);

                // Build full class name
                $class = $namespace.'\\'.$classPath;

                try {
                    // Skip if it's not a valid file
                    if (!is_file($file->getRealPath())) {
                        continue;
                    }

                    // Check if it defines a class and is a Machine
                    if ($this->isFileMachineClass($file->getRealPath(), $class)) {
                        $machineClasses[] = $class;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        } catch (Throwable) {
            return;
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
