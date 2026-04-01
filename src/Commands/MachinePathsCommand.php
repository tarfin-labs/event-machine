<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Tarfinlabs\EventMachine\Analysis\PathStep;
use Tarfinlabs\EventMachine\Analysis\MachinePath;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Analysis\PathEnumerationResult;

class MachinePathsCommand extends Command
{
    protected $signature = 'machine:paths
        {machine : The Machine class path or FQCN}
        {--json : Output as JSON}
        {--max-paths=1000 : Maximum paths to enumerate (prevents explosion in large machines)}';
    protected $description = 'Enumerate all paths through a machine definition';

    public function handle(): int
    {
        $machinePath = $this->argument('machine');

        // Resolve file path to FQCN if a file path was given
        if (str_ends_with($machinePath, '.php') || str_contains($machinePath, DIRECTORY_SEPARATOR)) {
            $machinePath = $this->resolveClassFromFile($machinePath);

            if ($machinePath === null) {
                $this->error('Could not resolve a Machine class from the given file path.');

                return self::FAILURE;
            }
        }

        if (!class_exists($machinePath)) {
            $this->error("Machine class not found: {$machinePath}");

            return self::FAILURE;
        }

        $definition = $machinePath::definition();
        $maxPaths   = (int) $this->option('max-paths');
        $enumerator = new PathEnumerator($definition, $maxPaths);
        $result     = $enumerator->enumerate();

        if ($this->option('json')) {
            $this->line($this->renderJson($result, $machinePath));

            return self::SUCCESS;
        }

        $this->renderConsole($result, $machinePath);

        return self::SUCCESS;
    }

    private function renderConsole(PathEnumerationResult $result, string $machinePath): void
    {
        $name       = class_basename($machinePath);
        $stateStats = $result->stateStats();

        $this->line('');
        $this->line("{$name} — Path Analysis");
        $this->line(str_repeat('═', mb_strlen("{$name} — Path Analysis")));
        $this->line('');
        $this->line("  States: {$stateStats['total']} ({$stateStats['atomic']} atomic, {$stateStats['final']} final"
            .($stateStats['parallel'] > 0 ? ", {$stateStats['parallel']} parallel" : '')
            .($stateStats['compound'] > 0 ? ", {$stateStats['compound']} compound" : '')
            .')');
        $this->line("  Events: {$result->eventCount()}");
        $this->line("  Guards: {$result->guardCount()}");
        $this->line("  Actions: {$result->actionCount()}");
        $this->line("  Calculators: {$result->calculatorCount()}");
        $this->line("  Job actors: {$result->jobActorCount()}");

        foreach ($result->jobActors() as $job) {
            $queueInfo = $job['queue'] !== null ? "queue: {$job['queue']}" : '';
            $this->line("    {$job['stateKey']} → ".class_basename($job['class']).($queueInfo !== '' ? " ({$queueInfo})" : ''));
        }

        $this->line("  Child machines: {$result->childMachineCount()}");

        foreach ($result->childMachines() as $child) {
            $mode = $child['async'] ? 'async' : 'sync';
            $info = $child['queue'] !== null ? "{$mode}, queue: {$child['queue']}" : $mode;
            $this->line("    {$child['stateKey']} → ".class_basename($child['class'])." ({$info})");
        }

        $this->line("  Timers: {$result->timerCount()}");
        $this->line('  Terminal paths: '.count($result->paths).($result->pathLimitReached ? ' (limit reached — increase with --max-paths)' : ''));

        foreach ($result->parallelGroups as $group) {
            $regionCount = count($group->regionPaths);
            $this->line("  Parallel regions: 1 ({$regionCount} regions, {$group->combinationCount()} combination".($group->combinationCount() !== 1 ? 's' : '').')');
        }

        // Group paths by type
        $groups = [
            'HAPPY PATHS'       => $result->happyPaths(),
            'FAIL PATHS'        => $result->failPaths(),
            'TIMEOUT PATHS'     => $result->timeoutPaths(),
            'LOOP PATHS'        => $result->loopPaths(),
            'GUARD BLOCK PATHS' => $result->guardBlockPaths(),
            'DEAD END PATHS'    => $result->deadEndPaths(),
        ];

        // Render parallel regions
        foreach ($result->parallelGroups as $group) {
            $stateKey    = str_contains($group->parallelStateId, '.') ? substr($group->parallelStateId, strrpos($group->parallelStateId, '.') + 1) : $group->parallelStateId;
            $regionCount = count($group->regionPaths);

            $this->line('');
            $header = "PARALLEL: {$stateKey} ({$regionCount} regions)";
            $this->line($header);
            $this->line(str_repeat('─', mb_strlen($header)));

            foreach ($group->regionPaths as $regionKey => $regionPaths) {
                $this->line("  {$regionKey} region: ".count($regionPaths).' path'.(count($regionPaths) !== 1 ? 's' : ''));

                foreach ($regionPaths as $path) {
                    $this->renderPathSteps($path, '    ');
                }
            }
        }

        $pathNumber = 1;

        foreach ($groups as $label => $paths) {
            if ($paths === []) {
                continue;
            }

            // Collect terminal states for header
            $terminals = array_unique(array_filter(array_map(
                static fn (MachinePath $p): ?string => $p->terminalStateId !== null
                    ? (str_contains($p->terminalStateId, '.') ? substr($p->terminalStateId, strrpos($p->terminalStateId, '.') + 1) : $p->terminalStateId)
                    : null,
                $paths,
            ), static fn (?string $v): bool => $v !== null));

            $this->line('');
            $header = $label
                .($terminals !== [] ? ' (→ '.implode(', ', $terminals).')' : '')
                .': '.count($paths).' path'.(count($paths) !== 1 ? 's' : '');
            $this->line($header);
            $this->line(str_repeat('─', mb_strlen($header)));

            foreach ($paths as $path) {
                $this->line("  #{$pathNumber}");
                $this->renderPathSteps($path, '      ');

                // Show guards and actions
                $guards  = $path->guardNames();
                $actions = $path->actionNames();

                if ($guards !== []) {
                    $this->line('      Guards: '.implode(', ', $guards));
                }

                if ($actions !== []) {
                    $this->line('      Actions: '.implode(', ', $actions));
                }

                $pathNumber++;
            }
        }

        // Unhandled child outcomes warning
        $unhandled = $result->unhandledChildOutcomes();

        if ($unhandled !== []) {
            $this->line('');
            $this->warn('UNHANDLED CHILD OUTCOMES:');

            foreach ($unhandled as $item) {
                $this->line("  {$item['parentStateKey']} → ".class_basename($item['childClass']));
                $this->line('    Child final states: '.implode(', ', $item['childFinalStates']));
                $this->line('    Parent handles: '.($item['handledStates'] !== [] ? implode(', ', array_map(fn (string $s): string => "@done.{$s}", $item['handledStates'])) : '(none)'));
                $this->line('    Unhandled: '.implode(', ', $item['unhandled']));
            }
        }

        $this->line('');
    }

    private function renderPathSteps(MachinePath $path, string $indent): void
    {
        foreach ($path->steps as $step) {
            $line = '→ ';

            if ($step->event !== null) {
                $line = "→ [{$step->event}] ";
            }

            $line .= $step->stateKey;

            if ($step->invokeClass !== null) {
                $line .= ' ('.class_basename($step->invokeClass).')';
            }

            if ($step->timerType !== null) {
                $line .= " ({$step->timerType})";
            }

            $this->line($indent.$line);
        }
    }

    private function renderJson(PathEnumerationResult $result, string $machinePath): string
    {
        $stateStats = $result->stateStats();

        $data = [
            'machine' => class_basename($machinePath),
            'stats'   => [
                'states'                   => $stateStats['total'],
                'atomic_states'            => $stateStats['atomic'],
                'final_states'             => $stateStats['final'],
                'events'                   => $result->eventCount(),
                'guards'                   => $result->guardCount(),
                'actions'                  => $result->actionCount(),
                'calculators'              => $result->calculatorCount(),
                'job_actors'               => array_map(static fn (array $j): array => ['state' => $j['stateKey'], 'class' => class_basename($j['class']), 'queue' => $j['queue']], $result->jobActors()),
                'child_machines'           => array_map(static fn (array $c): array => ['state' => $c['stateKey'], 'class' => class_basename($c['class']), 'async' => $c['async'], 'queue' => $c['queue']], $result->childMachines()),
                'timers'                   => $result->timerCount(),
                'terminal_paths'           => count($result->paths),
                'unhandled_child_outcomes' => array_map(static fn (array $u): array => ['parent_state' => $u['parentStateKey'], 'child_class' => class_basename($u['childClass']), 'unhandled' => $u['unhandled']], $result->unhandledChildOutcomes()),
            ],
            'paths' => array_map(static fn (MachinePath $path, int $index): array => [
                'id'        => $index + 1,
                'type'      => $path->type->value,
                'signature' => $path->signature(),
                'steps'     => array_map(static fn (PathStep $step): array => array_filter([
                    'state'        => $step->stateKey,
                    'event'        => $step->event,
                    'invoke_type'  => $step->invokeType,
                    'invoke_class' => $step->invokeClass !== null ? class_basename($step->invokeClass) : null,
                    'timer_type'   => $step->timerType,
                ], static fn (?string $v): bool => $v !== null), $path->steps),
                'terminal_state' => $path->terminalStateId !== null
                    ? (str_contains($path->terminalStateId, '.') ? substr($path->terminalStateId, strrpos($path->terminalStateId, '.') + 1) : $path->terminalStateId)
                    : null,
                'guards'  => $path->guardNames(),
                'actions' => $path->actionNames(),
            ], $result->paths, array_keys($result->paths)),
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Resolve a FQCN from a PHP file path by extracting namespace and class name.
     * Copied from ExportXStateCommand.
     */
    private function resolveClassFromFile(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            $filePath = base_path($filePath);
            if (!file_exists($filePath)) {
                return null;
            }
        }

        $contents  = file_get_contents($filePath);
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

        if (!class_exists($fqcn)) {
            require_once $filePath;
        }

        return class_exists($fqcn) ? $fqcn : null;
    }
}
