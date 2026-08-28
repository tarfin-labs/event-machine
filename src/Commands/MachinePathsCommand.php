<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Analysis\PathStep;
use Tarfinlabs\EventMachine\Analysis\MachinePath;
use Tarfinlabs\EventMachine\Analysis\PathEnumerator;
use Tarfinlabs\EventMachine\Analysis\ParallelPathGroup;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Analysis\PathEnumerationResult;

class MachinePathsCommand extends Command
{
    use ResolvesMachineDefinition;
    use ValidatesNumericOptions;

    protected $signature = 'machine:paths
        {machine : The Machine class path or FQCN}
        {--json : Output as JSON}
        {--max-paths=1000 : Maximum paths to enumerate (prevents explosion in large machines)}
        {--max-depth=200 : Maximum total analysis depth before a path is cut short}';
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

        $definition = $this->machineDefinitionFor($machinePath);

        if (!$definition instanceof MachineDefinition) {
            return self::FAILURE;
        }
        $maxPaths = $this->integerOption('max-paths', min: 1);
        $maxDepth = $this->integerOption('max-depth', min: 1);

        if ($maxPaths === null || $maxDepth === null) {
            return self::FAILURE;
        }

        $enumerator = new PathEnumerator($definition, $maxPaths, null, $maxDepth);
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
        $notes = [];

        if ($result->pathLimitReached) {
            $notes[] = 'path limit reached — increase with --max-paths';
        }

        if ($result->depthLimitReached) {
            $notes[] = 'depth limit reached — increase with --max-depth';
        }

        $this->line('  Terminal paths: '.count($result->paths).($notes !== [] ? ' ('.implode('; ', $notes).')' : ''));

        // Say it once, plainly, rather than leaving the reader to infer it from a
        // parenthetical: a partial analysis must not read as a complete one.
        if ($result->analysisTruncated()) {
            $this->warn('  Analysis incomplete — enumeration stopped early. The paths below are what was reached, not the full set.');
        }

        // One summary line for the whole machine. This printed once per group with a
        // hardcoded "1", so a machine with two parallel states claimed "Parallel
        // regions: 1" twice — invisible while every test machine had a single parallel
        // state, wrong as soon as one had two.
        if ($result->parallelGroups !== []) {
            $parallelCount = count($result->parallelGroups);
            $regionCount   = 0;

            foreach ($result->parallelGroups as $group) {
                $regionCount += count($group->regionPaths);
            }

            $this->line(sprintf(
                '  Parallel states: %d (%d region%s)',
                $parallelCount,
                $regionCount,
                $regionCount === 1 ? '' : 's',
            ));

            foreach ($result->parallelGroups as $group) {
                $key = str_contains($group->parallelStateId, '.')
                    ? substr($group->parallelStateId, strrpos($group->parallelStateId, '.') + 1)
                    : $group->parallelStateId;

                $this->line(sprintf(
                    '    %s: %d region%s, %d combination%s',
                    $key,
                    count($group->regionPaths),
                    count($group->regionPaths) === 1 ? '' : 's',
                    $group->combinationCount(),
                    $group->combinationCount() === 1 ? '' : 's',
                ));
            }
        }

        // Group paths by type
        // REGION_EXIT and REGION_DEFERRED are deliberately absent: region paths live in
        // ParallelPathGroup::$regionPaths and never reach $paths, so a group over $paths
        // could only ever be empty. They are surfaced inside the PARALLEL block instead.
        $groups = [
            'HAPPY PATHS'       => $result->happyPaths(),
            'FAIL PATHS'        => $result->failPaths(),
            'TIMEOUT PATHS'     => $result->timeoutPaths(),
            'LOOP PATHS'        => $result->loopPaths(),
            'GUARD BLOCK PATHS' => $result->guardBlockPaths(),
            'DEAD END PATHS'    => $result->deadEndPaths(),
            'TRUNCATED PATHS'   => $result->truncatedPaths(),
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
                    // Region paths never reach $paths, so the per-type groups below cannot
                    // show them. Without a tag here a path that ends by leaving its region
                    // is indistinguishable from one that reached a region final state.
                    $this->line('    ['.$path->type->value.']');
                    $this->renderPathSteps($path, '      ');
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

        // A child whose definition cannot be built is skipped by the check above. Saying so is
        // the difference between "all outcomes handled" and "we could not look".
        $unanalysable = $result->unanalysableChildren();

        if ($unanalysable !== []) {
            $this->line('');
            $this->warn('CHILD MACHINES THAT COULD NOT BE ANALYSED:');
            $this->line('  Their outcomes were not checked, so an unhandled one would not appear above.');

            foreach ($unanalysable as $item) {
                $this->line("  {$item['parentStateKey']} → ".class_basename($item['childClass']).": {$item['reason']}");
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
                'states'         => $stateStats['total'],
                'atomic_states'  => $stateStats['atomic'],
                'final_states'   => $stateStats['final'],
                'events'         => $result->eventCount(),
                'guards'         => $result->guardCount(),
                'actions'        => $result->actionCount(),
                'calculators'    => $result->calculatorCount(),
                'job_actors'     => array_map(static fn (array $j): array => ['state' => $j['stateKey'], 'class' => class_basename($j['class']), 'queue' => $j['queue']], $result->jobActors()),
                'child_machines' => array_map(static fn (array $c): array => ['state' => $c['stateKey'], 'class' => class_basename($c['class']), 'async' => $c['async'], 'queue' => $c['queue']], $result->childMachines()),
                'timers'         => $result->timerCount(),
                // terminal_paths keeps its existing meaning — the size of the paths array.
                // The truncated count gets its own key rather than redefining this one.
                'terminal_paths'           => count($result->paths),
                'truncated_paths'          => count($result->truncatedPaths()),
                'path_limit_reached'       => $result->pathLimitReached,
                'depth_limit_reached'      => $result->depthLimitReached,
                'analysis_truncated'       => $result->analysisTruncated(),
                'unhandled_child_outcomes' => array_map(static fn (array $u): array => ['parent_state' => $u['parentStateKey'], 'child_class' => class_basename($u['childClass']), 'unhandled' => $u['unhandled']], $result->unhandledChildOutcomes()),
                'unanalysable_children'    => array_map(static fn (array $u): array => ['parent_state' => $u['parentStateKey'], 'child_class' => class_basename($u['childClass']), 'reason' => $u['reason']], $result->unanalysableChildren()),
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

            // Region paths live in ParallelPathGroup and never enter $paths, so before
            // this they reached no JSON consumer at all — a region path that ends by
            // leaving its region was invisible. Additive: no existing key changes.
            'parallel_groups' => array_map(static fn (ParallelPathGroup $group): array => [
                'parallel_state' => str_contains($group->parallelStateId, '.')
                    ? substr($group->parallelStateId, strrpos($group->parallelStateId, '.') + 1)
                    : $group->parallelStateId,
                'combinations' => $group->combinationCount(),
                'regions'      => array_map(static fn (array $paths): array => array_map(static fn (MachinePath $path): array => [
                    'type'      => $path->type->value,
                    'signature' => $path->signature(),
                    'steps'     => array_map(static fn (PathStep $step): array => array_filter([
                        'state'       => $step->stateKey,
                        'event'       => $step->event,
                        'invoke_type' => $step->invokeType,
                        'timer_type'  => $step->timerType,
                    ], static fn (?string $v): bool => $v !== null), $path->steps),
                ], $paths), $group->regionPaths),
            ], $result->parallelGroups),
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
