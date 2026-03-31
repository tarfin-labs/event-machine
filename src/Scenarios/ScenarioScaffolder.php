<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Tarfinlabs\EventMachine\Analysis\ScenarioPath;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathStep;
use Tarfinlabs\EventMachine\Analysis\StateClassification;

/**
 * Generates MachineScenario PHP class files from resolved scenario paths.
 */
class ScenarioScaffolder
{
    /**
     * Generate PHP file content for a MachineScenario class.
     */
    public function scaffold(
        string $scenarioName,
        string $machineClass,
        string $source,
        string $event,
        string $target,
        ScenarioPath $path,
        string $namespace,
    ): string {
        $imports     = $this->collectImports($machineClass, $event, $path);
        $planEntries = $this->generatePlanEntries($path);

        $shortMachine = class_basename($machineClass);
        $eventRef     = class_exists($event) ? class_basename($event).'::class' : "'{$event}'";

        $importsBlock = implode("\n", array_map(fn (string $i): string => "use {$i};", $imports));

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        {$importsBlock}

        class {$scenarioName} extends MachineScenario
        {
            protected string \$machine     = {$shortMachine}::class;
            protected string \$source      = '{$source}';
            protected string \$event       = {$eventRef};
            protected string \$target      = '{$target}';
            protected string \$description = ''; // TODO: describe this scenario

            protected function plan(): array
            {
                return [
        {$planEntries}
                ];
            }
        }

        PHP;
    }

    /**
     * Generate plan() entries from classified path steps.
     */
    private function generatePlanEntries(ScenarioPath $path): string
    {
        $entries = [];

        foreach ($path->steps as $step) {
            $entry = match ($step->classification) {
                StateClassification::TRANSIENT   => $this->scaffoldTransientEntry($step),
                StateClassification::DELEGATION  => $this->scaffoldDelegationEntry($step),
                StateClassification::PARALLEL    => $this->scaffoldParallelEntry($step),
                StateClassification::INTERACTIVE => $this->scaffoldInteractiveEntry($step),
                default                          => null,
            };

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return implode("\n\n", $entries);
    }

    private function scaffoldTransientEntry(ScenarioPathStep $step): string
    {
        $guards = [];
        foreach ($step->guards as $guard) {
            $short    = class_exists($guard) ? class_basename($guard).'::class' : "'{$guard}'";
            $guards[] = "                {$short} => false, // TODO: adjust";
        }

        $guardsBlock = implode("\n", $guards);
        $comment     = "            // ── {$step->stateKey} ── @always, guards: [".implode(', ', array_map(class_basename(...), $step->guards)).']';

        return "{$comment}\n            '{$step->stateRoute}' => [\n{$guardsBlock}\n            ],";
    }

    private function scaffoldDelegationEntry(ScenarioPathStep $step): string
    {
        $invokeShort     = $step->invokeClass ? class_basename($step->invokeClass) : 'unknown';
        $comment         = "            // ── {$step->stateKey} ── delegation: {$invokeShort}";
        $outcomes        = $step->availableDoneStates;
        $outcomesComment = $outcomes !== [] ? ' // Available: '.implode(', ', $outcomes) : '';

        return "{$comment}\n            '{$step->stateRoute}' => '@done',{$outcomesComment}";
    }

    private function scaffoldParallelEntry(ScenarioPathStep $step): string
    {
        $guards = [];
        foreach ($step->guards as $guard) {
            $short    = class_exists($guard) ? class_basename($guard).'::class' : "'{$guard}'";
            $guards[] = "                {$short} => true, // TODO: adjust";
        }

        $guardsBlock = implode("\n", $guards);
        $comment     = "            // ── {$step->stateKey} ── parallel @done guard";

        return "{$comment}\n            '{$step->stateRoute}' => [\n{$guardsBlock}\n            ],";
    }

    private function scaffoldInteractiveEntry(ScenarioPathStep $step): string
    {
        $events      = $step->availableEvents;
        $firstEvent  = $events[0] ?? 'UnknownEvent';
        $firstShort  = class_exists($firstEvent) ? class_basename($firstEvent).'::class' : "'{$firstEvent}'";
        $otherEvents = array_slice($events, 1);
        $alsoComment = $otherEvents !== [] ? ' // Also: '.implode(', ', array_map(class_basename(...), $otherEvents)) : '';

        $comment = "            // ── {$step->stateKey} ── interactive, @continue to reach target";

        return "{$comment}\n            '{$step->stateRoute}' => [\n                '@continue' => {$firstShort},{$alsoComment}\n            ],";
    }

    /**
     * Collect all use imports needed by the generated file.
     */
    private function collectImports(string $machineClass, string $event, ScenarioPath $path): array
    {
        $imports = [
            $machineClass,
            MachineScenario::class,
        ];

        if (class_exists($event)) {
            $imports[] = $event;
        }

        foreach ($path->steps as $step) {
            foreach ($step->guards as $guard) {
                if (class_exists($guard)) {
                    $imports[] = $guard;
                }
            }
            foreach ($step->availableEvents as $evt) {
                if (class_exists($evt) && $step->classification === StateClassification::INTERACTIVE) {
                    $imports[] = $evt;
                }
            }
        }

        $imports = array_unique($imports);
        sort($imports);

        return $imports;
    }
}
