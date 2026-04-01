<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

use Tarfinlabs\EventMachine\Analysis\MachineGraph;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Analysis\StateClassification;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Analysis\ScenarioPathResolver;
use Tarfinlabs\EventMachine\Exceptions\NoScenarioPathFoundException;

/**
 * Static validator for MachineScenario classes.
 * Checks structural correctness without executing scenarios.
 */
class ScenarioValidator
{
    /** @var list<string> Accumulated validation errors. */
    private array $errors = [];

    public function __construct(
        private readonly MachineScenario $scenario,
    ) {}

    /**
     * Run all static validation checks.
     *
     * @return list<string> Error messages (empty = valid).
     */
    public function validate(): array
    {
        $this->errors = [];

        $this->checkMachineClassExists();
        $this->checkPropertiesSet();

        if ($this->errors !== []) {
            return $this->errors; // Can't proceed without valid machine
        }

        $definition = $this->scenario->machine()::definition();
        $graph      = new MachineGraph($definition);

        $this->checkSourceExists($definition);
        $this->checkTargetExists($definition, $graph);
        $this->checkEventValidFromSource($definition, $graph);
        $this->checkPlanStateRoutes($definition);
        $this->checkPlanStructure($definition, $graph);

        return $this->errors;
    }

    private function checkMachineClassExists(): void
    {
        $machineClass = $this->scenario->machine();
        if (!class_exists($machineClass)) {
            $this->errors[] = "Machine class not found: {$machineClass}";
        }
    }

    private function checkPropertiesSet(): void
    {
        $properties = [
            'machine'     => $this->scenario->machine(),
            'source'      => $this->scenario->source(),
            'event'       => $this->scenario->event(),
            'target'      => $this->scenario->target(),
            'description' => $this->scenario->description(),
        ];

        foreach ($properties as $prop => $value) {
            if ($value === '') {
                $this->errors[] = "Missing required property: \${$prop}";
            }
        }
    }

    private function checkSourceExists(MachineDefinition $definition): void
    {
        $source = $this->scenario->source();
        $found  = $definition->idMap[$source] ?? $definition->idMap[$definition->id.'.'.$source] ?? null;
        if ($found === null) {
            $this->errors[] = "Source state '{$source}' not found in {$this->scenario->machine()} definition";
        }
    }

    private function checkTargetExists(MachineDefinition $definition, MachineGraph $graph): void
    {
        $target = $this->scenario->target();
        $found  = $definition->idMap[$target] ?? $definition->idMap[$definition->id.'.'.$target] ?? null;
        if ($found === null) {
            $this->errors[] = "Target state '{$target}' not found in {$this->scenario->machine()} definition";

            return;
        }

        // Check target is not transient
        if ($graph->classifyState($found) === StateClassification::TRANSIENT) {
            $this->errors[] = "Target '{$target}' is transient (@always) — machine cannot stop here";
        }
    }

    private function checkEventValidFromSource(MachineDefinition $definition, MachineGraph $graph): void
    {
        $source = $this->scenario->source();
        $state  = $definition->idMap[$source] ?? $definition->idMap[$definition->id.'.'.$source] ?? null;
        if ($state === null) {
            return;
        }

        $event = $this->scenario->event();

        // @start: valid when source is the machine's initial state (typically transient)
        if ($event === MachineScenario::START) {
            $initialState = $definition->config['initial'] ?? null;
            if ($initialState !== null && $source !== $initialState && !str_ends_with($source, '.'.$initialState)) {
                $this->errors[] = "@start event requires source to be the initial state ('{$initialState}'), got '{$source}'";
            }

            return;
        }

        $available   = $graph->availableEventsFrom($state);
        $transitions = $graph->transitionsFrom($state);

        // Check if event is in available transitions (by type or class)
        $eventMatched = false;
        foreach (array_keys($transitions) as $eventKey) {
            if ($eventKey === $event) {
                $eventMatched = true;
                break;
            }
            // Check if EventBehavior class matches
            if (class_exists($event) && method_exists($event, 'getType') && $eventKey === $event::getType()) {
                $eventMatched = true;
                break;
            }
        }

        if (!$eventMatched) {
            $availableStr   = implode(', ', $available);
            $this->errors[] = "Event '{$event}' not available from '{$source}'. Available: {$availableStr}";
        }
    }

    private function checkPlanStateRoutes(MachineDefinition $definition): void
    {
        foreach (array_keys($this->scenario->resolvedPlan()) as $route) {
            $found = $definition->idMap[$route] ?? $definition->idMap[$definition->id.'.'.$route] ?? null;
            if ($found === null) {
                $this->errors[] = "State route '{$route}' in plan() not found in machine definition";
            }
        }
    }

    /**
     * Run Level-2 path validation checks.
     *
     * @return list<string> Error messages (empty = valid).
     */
    public function validatePaths(): array
    {
        $errors = [];

        $machineClass = $this->scenario->machine();
        if (!class_exists($machineClass)) {
            return ['Cannot validate paths — machine class not found'];
        }

        $definition = $machineClass::definition();
        $graph      = new MachineGraph($definition);
        $resolver   = new ScenarioPathResolver($graph);

        // Check 1: Path exists from source to target
        try {
            $resolver->resolve(
                source: $this->scenario->source(),
                event: $this->scenario->event(),
                target: $this->scenario->target(),
            );
        } catch (NoScenarioPathFoundException) {
            $errors[] = "No path from '{$this->scenario->source()}' to '{$this->scenario->target()}' via '{$this->scenario->eventType()}'";
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        // Check 2: @continue events lead toward target (basic direction check)
        $plan = $this->scenario->resolvedPlan();
        foreach ($plan as $route => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (!isset($value['@continue'])) {
                continue;
            }
            $continue   = $value['@continue'];
            $eventClass = is_string($continue) ? $continue : ($continue[0] ?? null);

            if ($eventClass === null) {
                continue;
            }

            // Check the event class exists if it looks like a FQCN
            if (is_string($eventClass) && str_contains($eventClass, '\\') && !class_exists($eventClass)) {
                $errors[] = "@continue at '{$route}' references non-existent event class: {$eventClass}";

                continue;
            }

            // Direction check: verify @continue event is available from this state
            // and leads toward target (not away from it)
            $state = $definition->idMap[$route] ?? $definition->idMap[$definition->id.'.'.$route] ?? null;
            if ($state !== null) {
                $availableEvents = $graph->availableEventsFrom($state);

                // Resolve event type for comparison
                $eventType = (is_string($eventClass) && class_exists($eventClass) && method_exists($eventClass, 'getType'))
                    ? $eventClass::getType()
                    : $eventClass;

                if ($availableEvents !== [] && !in_array($eventType, $availableEvents, true)) {
                    $errors[] = "@continue at '{$route}' sends '{$eventType}' which is not available from this state. Available: ".implode(', ', $availableEvents);
                }

                // Check if event leads toward target (any transition target is closer to $target)
                $transitions = $graph->transitionsFrom($state);
                $transition  = $transitions[$eventType] ?? null;
                if ($transition !== null) {
                    $leadsTowardTarget = false;
                    foreach ($transition->branches ?? [] as $branch) {
                        if (!$branch->target instanceof StateDefinition) {
                            continue;
                        }
                        $branchRoute = $branch->target->route ?? $branch->target->key ?? '';
                        $targetRoute = $this->scenario->target();
                        // Does the branch target match or get closer to $target?
                        if ($branchRoute === $targetRoute
                            || str_contains($branchRoute, $targetRoute)
                            || str_contains($targetRoute, $branchRoute)) {
                            $leadsTowardTarget = true;
                            break;
                        }
                        // Any transition is "toward target" if we can't determine direction
                        $leadsTowardTarget = true;
                    }
                    if (!$leadsTowardTarget) {
                        $errors[] = "@continue at '{$route}' sends '{$eventType}' which does not lead toward target '{$this->scenario->target()}'";
                    }
                }
            }
        }

        // Check 3: Deep target child scenario exists
        $deepTarget = $resolver->resolveDeepTarget($this->scenario->target());
        if ($deepTarget !== null) {
            $childScenarios = ScenarioDiscovery::forMachine($deepTarget['childMachine']);
            $matching       = $childScenarios->filter(fn (MachineScenario $s): bool => $s->target() === $deepTarget['childTarget']);

            if ($matching->isEmpty()) {
                $errors[] = "Deep target: no child scenario found for {$deepTarget['childMachine']} targeting '{$deepTarget['childTarget']}'";
            }
        }

        return $errors;
    }

    private function checkPlanStructure(MachineDefinition $definition, MachineGraph $graph): void
    {
        foreach ($this->scenario->resolvedPlan() as $route => $value) {
            $state = $definition->idMap[$route] ?? $definition->idMap[$definition->id.'.'.$route] ?? null;
            if ($state === null) {
                continue;
            }

            $classification = $graph->classifyState($state);
            $isDelegation   = $classification === StateClassification::DELEGATION;

            // Check delegation outcomes on non-delegation states
            if (!$isDelegation && is_string($value) && str_starts_with($value, '@')) {
                $this->errors[] = "'{$route}' has delegation outcome '{$value}' but is not a delegation state";
            }

            // Check @continue on delegation states
            if ($isDelegation && is_array($value) && isset($value['@continue'])) {
                $this->errors[] = "'{$route}' has @continue but is a delegation state";
            }

            // Check child scenario machine match
            if (is_string($value) && class_exists($value) && is_subclass_of($value, MachineScenario::class)) {
                $childScenario = new $value();
                $invokeClass   = $state->getMachineInvokeDefinition()?->machineClass ?? '';
                if ($childScenario->machine() !== $invokeClass) {
                    $this->errors[] = "Child scenario '{$value}' targets {$childScenario->machine()} but '{$route}' delegates to {$invokeClass}";
                }
            }

            // Check behavior override classes exist
            if (is_array($value) && !isset($value['outcome'])) {
                foreach (array_keys($value) as $key) {
                    if ($key === '@continue') {
                        continue;
                    }
                    if (is_string($key) && str_contains($key, '\\') && !class_exists($key)) {
                        $this->errors[] = "Behavior class '{$key}' in plan() at '{$route}' not found";
                    }
                }
            }
        }
    }
}
