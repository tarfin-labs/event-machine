<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use ReflectionClass;
use Illuminate\Console\Command;
use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Scenarios\ScenarioScaffolder;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;

class MachineScenarioCommand extends Command
{
    use ResolvesMachineDefinition;
    use ValidatesNumericOptions;

    protected $signature = 'machine:scenario
        {name : Scenario class name (Scenario suffix auto-added if missing)}
        {machine : Machine class FQCN}
        {source : Source state route (full or partial)}
        {event : Triggering event (class FQCN or event type string)}
        {target : Target state route (full or partial)}
        {--dry-run : Print generated file without writing}
        {--force : Overwrite existing scenario file}
        {--path=0 : Select path by index when multiple paths exist}
        {--max-iterations=1000 : Maximum BFS iterations before the search is reported as truncated}';
    protected $description = 'Generate a MachineScenario class by analyzing the machine definition';

    public function handle(): int
    {
        $name         = $this->argument('name');
        $machineClass = $this->argument('machine');
        $source       = $this->argument('source');
        $event        = $this->argument('event');
        $target       = $this->argument('target');

        // The name becomes both a path segment and a class name in generated PHP, and it was
        // taken verbatim into both. `../Foo` wrote the file outside Scenarios/ and still printed
        // "Created:", and `Evil{} echo 1; class Zz` was interpolated into the template, putting
        // top-level statements in a class file. A PHP class name is the only thing this can be.
        if (preg_match('/^[A-Za-z_]\w*$/', $name) !== 1) {
            $this->error("Invalid scenario name: {$name}");
            $this->line('A scenario name must be a PHP class name: a letter or underscore, then letters, digits or underscores.');

            return self::FAILURE;
        }

        // Auto-add Scenario suffix
        if (!str_ends_with($name, 'Scenario')) {
            $name .= 'Scenario';
        }

        // Validate machine class
        $definition = $this->machineDefinitionFor($machineClass);

        if (!$definition instanceof MachineDefinition) {
            return self::FAILURE;
        }

        $graph         = new MachineGraph($definition);
        $maxIterations = $this->integerOption('max-iterations', min: 1);

        if ($maxIterations === null) {
            return self::FAILURE;
        }

        $resolver   = new ScenarioPathResolver($graph, $maxIterations);
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
            // The command calls resolveAll() and never catches the exception, so it reads
            // the distinction off the resolver. Both outcomes still fail; what changes is
            // whether we claim there is no path or admit we stopped looking.
            if ($resolver->wasTruncated()) {
                $this->error("Path analysis from '{$source}' to '{$parentTarget}' via '{$event}' was truncated at the search limit ({$maxIterations} iterations).");
                $this->line('A path may still exist — this is not a finding that none does. Raise the ceiling with --max-iterations.');
            } else {
                $this->error("No path from '{$source}' to '{$parentTarget}' via '{$event}'.");
            }

            return self::FAILURE;
        }

        // Truncation matters even when paths WERE found: the search stopped with work
        // pending, so the list below may be missing routes, and --path=N indexes into a
        // partial set. Reporting it only on the empty branch let a truncated search that
        // happened to find one route look like an exhaustive answer.
        if ($resolver->wasTruncated()) {
            // Naming the ceiling is the difference between a warning and an actionable one:
            // a large machine trips the default on a query whose answer is already complete,
            // and without the number the reader cannot tell which value to raise past.
            $this->warn("Path analysis was truncated at the search limit ({$maxIterations} iterations); other paths from '{$source}' to '{$parentTarget}' may exist.");
            $this->line('Raise the ceiling with --max-iterations to search further — a large machine can need 50000 or more.');
        }

        // Select path
        // A bad --path is the worst of these: zero is a valid index, so the command would
        // scaffold path 0 and report "Created:" for a file the caller never asked for.
        $pathIndex = $this->integerOption('path', min: 0);

        if ($pathIndex === null) {
            return self::FAILURE;
        }

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

        // Deep target info — shown for both --dry-run and normal mode
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
                $this->warn('No child scenario found. Create one with:');
                $this->line('  php artisan machine:scenario At'.ucfirst($deepTarget['childTarget'])." {$deepTarget['childMachine']} idle MACHINE_START {$deepTarget['childTarget']}");
            } else {
                $this->info('Found '.count($childScenarios).' matching child scenario(s):');

                foreach ($childScenarios as $cs) {
                    $this->line('  - '.$cs->slug().' ('.$cs::class.')');
                }
            }

            $this->line('');
        }

        // Dry run
        if ($this->option('dry-run')) {
            $this->line($content);

            return self::SUCCESS;
        }

        // Write file. Reporting "Created:" for a write that failed sends the caller
        // looking for a file that is not there.
        // The warnings are suppressed because the return values ARE the handling: under
        // Laravel's error handler a raw warning is promoted to an ErrorException, which
        // made both branches below unreachable — the caller got a stack trace instead of
        // the message, and neither branch could be exercised at all.
        if (!is_dir($scenarioDir) && !@mkdir($scenarioDir, 0755, true) && !is_dir($scenarioDir)) {
            $this->error("Could not create scenario directory: {$scenarioDir}");

            return self::FAILURE;
        }

        // Compared against the byte count rather than false, though the two are equivalent
        // here: PHP folds a partial write into `false` (it returns -1 internally on a short
        // count), so a positive-but-short return cannot occur — demonstrated with a
        // userland stream wrapper, which returns the full length even when its writes make
        // progress in small chunks. An earlier comment here claimed the opposite. The
        // stricter form is kept because it states the intent, not because it catches a
        // case `=== false` misses.
        if (@file_put_contents($scenarioFile, $content) !== strlen($content)) {
            $this->error("Could not write scenario file: {$scenarioFile}");

            return self::FAILURE;
        }

        $this->info("Created: {$scenarioFile}");

        return self::SUCCESS;
    }
}
