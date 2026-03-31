<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Composer\Autoload\ClassLoader;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Scenarios\ScenarioDiscovery;
use Tarfinlabs\EventMachine\Scenarios\ScenarioValidator;

class MachineScenarioValidateCommand extends Command
{
    protected $signature = 'machine:scenario-validate
        {machine? : Validate scenarios for a specific machine (FQCN or class name)}
        {--scenario= : Validate a single scenario by class name or slug}';
    protected $description = 'Validate all scenarios against their machine definitions';

    public function handle(): int
    {
        $machineClass   = $this->argument('machine');
        $scenarioFilter = $this->option('scenario');

        if ($machineClass !== null && !class_exists($machineClass)) {
            $this->error("Machine class not found: {$machineClass}");

            return self::FAILURE;
        }

        if ($machineClass !== null) {
            return $this->validateMachine($machineClass, $scenarioFilter);
        }

        // Auto-discover all machines with scenarios
        return $this->validateAllMachines($scenarioFilter);
    }

    private function validateAllMachines(?string $scenarioFilter): int
    {
        $machineClasses = $this->discoverMachinesWithScenarios();

        if ($machineClasses === []) {
            $this->warn('No machines with scenarios found.');

            return self::SUCCESS;
        }

        $totalPassed = 0;
        $totalFailed = 0;

        foreach ($machineClasses as $machineClass) {
            $result = $this->validateMachineAndCount($machineClass, $scenarioFilter);
            $totalPassed += $result['passed'];
            $totalFailed += $result['failed'];
        }

        $this->line('');
        $this->line('────────────────────────────────────────');
        $summary = "<info>{$totalPassed} passed</info>";
        if ($totalFailed > 0) {
            $summary .= ", <fg=red>{$totalFailed} failed</>";
        }
        $this->line("Total: {$summary}");

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Discover all Machine subclasses that have a Scenarios/ directory.
     *
     * Uses Composer's classmap to find all classes, filters for Machine subclasses,
     * then checks if their file location has a sibling Scenarios/ directory.
     *
     * @return list<class-string<Machine>>
     */
    private function discoverMachinesWithScenarios(): array
    {
        $machines = [];

        // Use Composer's autoloader to get all known class mappings
        $autoloadFiles = [];
        foreach (spl_autoload_functions() as $autoloader) {
            if (is_array($autoloader) && $autoloader[0] instanceof ClassLoader) {
                $autoloadFiles = $autoloader[0]->getClassMap();
                break;
            }
        }

        // If classmap is empty, try PSR-4 prefixes to scan directories
        if ($autoloadFiles === []) {
            $appPath = base_path('app/Machines');
            if (is_dir($appPath)) {
                $autoloadFiles = $this->scanForMachineClasses($appPath);
            }
        }

        foreach ($autoloadFiles as $class => $file) {
            // Quick filter: only check classes ending with "Machine"
            if (!str_ends_with($class, 'Machine')) {
                continue;
            }

            try {
                if (!is_subclass_of($class, Machine::class)) {
                    continue;
                }
            } catch (\Throwable) {
                continue; // Skip classes that can't be loaded
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            $machineFile = $reflection->getFileName();
            if ($machineFile === false) {
                continue;
            }

            $scenarioDir = dirname($machineFile).'/Scenarios';
            if (is_dir($scenarioDir)) {
                $machines[] = $class;
            }
        }

        sort($machines);

        return $machines;
    }

    /**
     * Scan a directory for PHP files that might be Machine classes.
     * Falls back to file-based scanning when Composer classmap is unavailable.
     *
     * @return array<string, string>
     */
    private function scanForMachineClasses(string $directory): array
    {
        $classes  = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains((string) $file->getPathname(), '/Scenarios/')) {
                continue;
            }
            if (!str_ends_with((string) $file->getFilename(), 'Machine.php')) {
                continue;
            }

            // Derive class name from file path (assumes PSR-4 under app/)
            $relativePath = str_replace(base_path('app/'), '', $file->getRealPath());
            $className    = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $relativePath);

            if (class_exists($className)) {
                $classes[$className] = $file->getRealPath();
            }
        }

        return $classes;
    }

    private function validateMachine(string $machineClass, ?string $scenarioFilter): int
    {
        $result = $this->validateMachineAndCount($machineClass, $scenarioFilter);

        $this->line('');
        $summary = "{$result['passed']} passed";
        if ($result['failed'] > 0) {
            $summary .= ", <fg=red>{$result['failed']} failed</>";
        }
        $this->line($summary);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{passed: int, failed: int}
     */
    private function validateMachineAndCount(string $machineClass, ?string $scenarioFilter): array
    {
        $scenarios = ScenarioDiscovery::forMachine($machineClass);

        if ($scenarioFilter !== null) {
            $scenarios = $scenarios->filter(function (MachineScenario $s) use ($scenarioFilter): bool {
                if ($s->slug() === $scenarioFilter) {
                    return true;
                }
                if ($s::class === $scenarioFilter) {
                    return true;
                }

                return class_basename($s::class) === $scenarioFilter;
            });
        }

        if ($scenarios->isEmpty()) {
            return ['passed' => 0, 'failed' => 0];
        }

        $machineShort = class_basename($machineClass);
        $this->line('');
        $this->line("<info>{$machineShort}</info> ({$scenarios->count()} scenarios)");

        $passed = 0;
        $failed = 0;

        foreach ($scenarios as $scenario) {
            $validator = new ScenarioValidator($scenario);

            // Level 1: Static checks
            $errors = $validator->validate();

            // Level 2: Path checks (only if Level 1 passes)
            if ($errors === []) {
                $pathErrors = $validator->validatePaths();
                $errors     = array_merge($errors, $pathErrors);
            }

            $slug   = $scenario->slug();
            $source = $scenario->source();
            $target = $scenario->target();

            if ($errors === []) {
                $this->line("  <fg=green>✓</> {$slug}  <fg=gray>{$source} → {$target}</>");
                $passed++;
            } else {
                $this->line("  <fg=red>✗</> {$slug}  <fg=gray>{$source} → {$target}</>");
                foreach ($errors as $error) {
                    $this->line("    <fg=red>{$error}</>");
                }
                $failed++;
            }
        }

        return ['passed' => $passed, 'failed' => $failed];
    }
}
