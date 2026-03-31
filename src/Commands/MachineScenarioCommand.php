<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use ReflectionClass;
use Illuminate\Console\Command;
use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Scenarios\ScenarioScaffolder;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;

class MachineScenarioCommand extends Command
{
    protected $signature = 'machine:scenario
        {name : Scenario class name (Scenario suffix auto-added if missing)}
        {machine : Machine class FQCN}
        {source : Source state route (full or partial)}
        {event : Triggering event (class FQCN or event type string)}
        {target : Target state route (full or partial)}
        {--dry-run : Print generated file without writing}
        {--force : Overwrite existing scenario file}
        {--path=0 : Select path by index when multiple paths exist}';
    protected $description = 'Generate a MachineScenario class by analyzing the machine definition';

    public function handle(): int
    {
        $name         = $this->argument('name');
        $machineClass = $this->argument('machine');
        $source       = $this->argument('source');
        $event        = $this->argument('event');
        $target       = $this->argument('target');

        // Auto-add Scenario suffix
        if (!str_ends_with($name, 'Scenario')) {
            $name .= 'Scenario';
        }

        // Validate machine class
        if (!class_exists($machineClass)) {
            $this->error("Machine class not found: {$machineClass}");

            return self::FAILURE;
        }

        $definition = $machineClass::definition();
        $graph      = new MachineGraph($definition);
        $resolver   = new ScenarioPathResolver($graph);
        $scaffolder = new ScenarioScaffolder();

        // Check for deep target
        $deepTarget   = $resolver->resolveDeepTarget($target);
        $parentTarget = $deepTarget['parentTarget'] ?? $target;

        // Resolve path
        try {
            $paths = $resolver->resolveAll($source, $event, $parentTarget);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($paths === []) {
            $this->error("No path from '{$source}' to '{$parentTarget}' via '{$event}'.");

            return self::FAILURE;
        }

        // Select path
        $pathIndex = (int) $this->option('path');

        if (count($paths) > 1 && $pathIndex === 0) {
            $this->info('Found '.count($paths)." paths from {$source} to {$parentTarget}:");
            $this->line('');

            foreach ($paths as $i => $p) {
                $stats = $p->stats();
                $this->line("  [{$i}] ".$p->signature());
                $this->line("      {$stats['overrides']} overrides, {$stats['outcomes']} delegation outcomes, {$stats['continues']} @continue");
            }

            $this->line('');
            $this->info('Use --path=N to select. Using path [0].');
        }

        if ($pathIndex >= count($paths)) {
            $this->error('Path index '.$pathIndex.' out of range (0-'.(count($paths) - 1).').');

            return self::FAILURE;
        }

        $selectedPath = $paths[$pathIndex];

        // Determine file location
        $reflection   = new ReflectionClass($machineClass);
        $machineFile  = $reflection->getFileName();
        $scenarioDir  = dirname($machineFile).'/Scenarios';
        $scenarioFile = $scenarioDir.'/'.$name.'.php';

        // Determine namespace
        $machineNamespace  = $reflection->getNamespaceName();
        $scenarioNamespace = $machineNamespace.'\\Scenarios';

        // Check existing file
        if (file_exists($scenarioFile) && !$this->option('force')) {
            $this->error("File already exists: {$scenarioFile}");
            $this->line('Use --force to overwrite.');

            return self::FAILURE;
        }

        // Generate content
        $content = $scaffolder->scaffold(
            scenarioName: $name,
            machineClass: $machineClass,
            source: $source,
            event: $event,
            target: $target, // Use original target (may be deep)
            path: $selectedPath,
            namespace: $scenarioNamespace,
        );

        // Dry run
        if ($this->option('dry-run')) {
            $this->line($content);

            return self::SUCCESS;
        }

        // Write file
        if (!is_dir($scenarioDir)) {
            mkdir($scenarioDir, 0755, true);
        }

        file_put_contents($scenarioFile, $content);
        $this->info("Created: {$scenarioFile}");

        // Deep target info
        if ($deepTarget !== null) {
            $this->line('');
            $this->warn("Deep target detected: {$target}");
            $this->line("  Parent target: {$deepTarget['parentTarget']}");
            $this->line("  Child machine: {$deepTarget['childMachine']}");
            $this->line("  Child target: {$deepTarget['childTarget']}");

            $childScenarios = $scaffolder->discoverChildScenarios(
                $deepTarget['childMachine'],
                $deepTarget['childTarget'],
            );

            if ($childScenarios === []) {
                $childMachineShort = class_basename($deepTarget['childMachine']);
                $this->warn('No child scenario found. Create one with:');
                $this->line('  php artisan machine:scenario At'.ucfirst((string) $deepTarget['childTarget'])." {$deepTarget['childMachine']} idle MACHINE_START {$deepTarget['childTarget']}");
            } else {
                $this->info('Found '.count($childScenarios).' matching child scenario(s):');

                foreach ($childScenarios as $cs) {
                    $this->line('  - '.$cs->slug().' ('.$cs::class.')');
                }
            }
        }

        return self::SUCCESS;
    }
}
