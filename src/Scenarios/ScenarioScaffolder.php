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

        // Extract trigger event payload hint (shown as class docblock)
        $triggerPayloadHint   = '';
        $triggerPayloadFields = $this->extractEventPayloadFields($event);
        if ($triggerPayloadFields !== []) {
            $fields = [];
            foreach ($triggerPayloadFields as $field => $type) {
                $fields[] = "     *   - {$field}: {$type}";
            }
            $triggerPayloadHint = "/**\n     * Trigger event payload:\n".implode("\n", $fields)."\n     */\n        ";
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        {$importsBlock}

        {$triggerPayloadHint}class {$scenarioName} extends MachineScenario
        {
            protected string \$machine     = {$shortMachine}::class;
            protected string \$source      = '{$source}';
            protected string \$event       = {$eventRef};
            protected string \$target      = '{$target}';
            protected string \$description = 'TODO: describe this scenario';

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
     * Find child scenarios matching a deep target.
     * Returns matching scenario class name or null.
     */
    public function discoverChildScenario(string $childMachineClass, string $childTarget): ?string
    {
        $childScenarios = ScenarioDiscovery::forMachine($childMachineClass);

        $matching = $childScenarios->filter(
            fn (MachineScenario $s): bool => $s->target() === $childTarget
        );

        if ($matching->count() === 1) {
            return $matching->first()::class;
        }

        return null; // None found or multiple — caller handles
    }

    /**
     * Find ALL child scenarios matching a deep target (for selection).
     *
     * @return list<MachineScenario>
     */
    public function discoverChildScenarios(string $childMachineClass, string $childTarget): array
    {
        return ScenarioDiscovery::forMachine($childMachineClass)
            ->filter(fn (MachineScenario $s): bool => $s->target() === $childTarget)
            ->values()
            ->all();
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
        $lines = [];
        foreach ($step->guards as $guard) {
            $short   = class_exists($guard) ? class_basename($guard).'::class' : "'{$guard}'";
            $lines[] = "                {$short} => false, // TODO: adjust";
        }
        foreach ($step->entryActions as $action) {
            $short   = class_exists($action) ? class_basename($action).'::class' : "'{$action}'";
            $lines[] = "                // {$short} => [], // TODO: entry action override (if it accesses context models)";
        }

        $linesBlock = implode("\n", $lines);
        $comment    = "            // ── {$step->stateKey} ── @always, guards: [".implode(', ', array_map(class_basename(...), $step->guards)).']';

        return "{$comment}\n            '{$step->stateRoute}' => [\n{$linesBlock}\n            ],";
    }

    private function scaffoldDelegationEntry(ScenarioPathStep $step): string
    {
        $invokeShort     = $step->invokeClass !== null ? class_basename($step->invokeClass) : 'unknown';
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

        $entryHints = '';
        foreach ($step->entryActions as $action) {
            $short = class_exists($action) ? class_basename($action).'::class' : "'{$action}'";
            $entryHints .= "\n                // {$short} => [], // TODO: entry action override (if it accesses context models)";
        }

        $comment = "            // ── {$step->stateKey} ── interactive, @continue to reach target";

        // Check if event has rules() for payload extraction
        $payloadFields = $this->extractEventPayloadFields($firstEvent);

        if ($payloadFields !== []) {
            $payloadEntries = [];
            foreach ($payloadFields as $field => $type) {
                $payloadEntries[] = "                    '{$field}' => '', // TODO: {$type}";
            }
            $payloadBlock = implode("\n", $payloadEntries);

            return "{$comment}\n            '{$step->stateRoute}' => [\n                '@continue' => [{$firstShort}, 'payload' => [\n{$payloadBlock}\n                ]],{$alsoComment}{$entryHints}\n            ],";
        }

        return "{$comment}\n            '{$step->stateRoute}' => [\n                '@continue' => {$firstShort},{$alsoComment}{$entryHints}\n            ],";
    }

    /**
     * Extract payload field names and types from EventBehavior::rules().
     *
     * @return array<string, string> field => type hint
     */
    private function extractEventPayloadFields(string $eventClass): array
    {
        if (!class_exists($eventClass) || !method_exists($eventClass, 'rules')) {
            return [];
        }

        try {
            $rules = $eventClass::rules();
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($rules)) {
            return [];
        }

        $fields = [];
        foreach ($rules as $field => $rule) {
            $ruleStr = is_array($rule) ? implode('|', array_map(strval(...), $rule)) : (string) $rule;
            $type    = match (true) {
                str_contains($ruleStr, 'integer') || str_contains($ruleStr, 'numeric') => 'number',
                str_contains($ruleStr, 'boolean')                                      => 'boolean',
                str_contains($ruleStr, 'array')                                        => 'array',
                default                                                                => 'string',
            };
            $required       = str_contains($ruleStr, 'required') ? 'required' : 'optional';
            $fields[$field] = "{$required} ({$type})";
        }

        return $fields;
    }

    /**
     * Collect all use imports needed by the generated file.
     */
    /**
     * @return array<int, string>
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
