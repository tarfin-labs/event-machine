<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

use Tarfinlabs\EventMachine\Enums\TransitionProperty;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Definition\TransitionDefinition;
use Tarfinlabs\EventMachine\Exceptions\NoScenarioPathFoundException;

/**
 * Finds paths from source to target through a machine definition.
 * Used by machine:scenario scaffold command.
 */
class ScenarioPathResolver
{
    public function __construct(
        private readonly MachineGraph $graph,
    ) {}

    /**
     * Find the shortest path from source to target via the trigger event.
     */
    public function resolve(string $source, string $event, string $target): ScenarioPath
    {
        $paths = $this->resolveAll($source, $event, $target);

        if ($paths === []) {
            throw NoScenarioPathFoundException::noPath($source, $target, $this->graph->definition()->id);
        }

        return $paths[0];
    }

    /**
     * Find ALL paths from source to target via the trigger event.
     *
     * @return list<ScenarioPath>
     */
    public function resolveAll(string $source, string $event, string $target): array
    {
        $sourceState = $this->graph->resolveState($source);
        $targetState = $this->graph->resolveState($target);

        // Find initial transitions from source via trigger event
        $transitions = $this->graph->transitionsFrom($sourceState);

        // @start: use the @always transition from the initial state
        if ($event === MachineScenario::START) {
            $eventTransition = $transitions[TransitionProperty::Always->value] ?? null;
        } else {
            $eventTransition = $transitions[$event] ?? null;

            // Try EventBehavior::getType() match
            if ($eventTransition === null) {
                foreach ($transitions as $eventKey => $transition) {
                    if (class_exists($event) && method_exists($event, 'getType') && $eventKey === $event::getType()) {
                        $eventTransition = $transition;
                        break;
                    }
                }
            }
        }

        if ($eventTransition === null) {
            return [];
        }

        $paths    = [];
        $targetId = $targetState->id;

        // BFS from each branch of the trigger event's transition
        foreach ($eventTransition->branches ?? [] as $branch) {
            if (!$branch->target instanceof StateDefinition) {
                continue;
            }

            $this->bfs(
                startState: $branch->target,
                startEvent: $event,
                startGuards: $branch->guards ?? [],
                startActions: $branch->actions ?? [],
                targetId: $targetId,
                results: $paths,
            );
        }

        return $paths;
    }

    /**
     * BFS from a start state to the target, building classified ScenarioPath steps.
     *
     * @param  list<ScenarioPath>  $results  Accumulated results (passed by reference).
     * @param  array<int, string>  $startGuards
     * @param  array<int, string>  $startActions
     */
    private function bfs(
        StateDefinition $startState,
        string $startEvent,
        array $startGuards,
        array $startActions,
        string $targetId,
        array &$results,
    ): void {
        $firstStep = new ScenarioPathStep(
            stateRoute: $this->routeKey($startState),
            stateKey: $startState->key ?? '',
            classification: $this->graph->classifyState($startState),
            event: $startEvent,
            guards: $startGuards,
            actions: $startActions,
            invokeClass: $this->getInvokeClass($startState),
            availableEvents: $this->graph->availableEventsFrom($startState),
            availableDoneStates: $this->graph->delegationOutcomes($startState),
            entryActions: $this->getEntryActions($startState),
        );

        // Check if start IS the target
        if ($startState->id === $targetId) {
            $results[] = new ScenarioPath([$firstStep]);

            return;
        }

        // BFS queue: [state, path-so-far, visited]
        $queue   = [[$startState, [$firstStep], [$startState->id => true]]];
        $maxIter = 1000;
        $iter    = 0;

        while ($queue !== [] && $iter < $maxIter) {
            $iter++;
            [$currentState, $currentPath, $visited] = array_shift($queue);

            $nextStates = $this->getNextStates($currentState);

            foreach ($nextStates as [$nextState, $nextEvent, $nextGuards, $nextActions]) {
                if (isset($visited[$nextState->id])) {
                    continue; // Cycle
                }

                $step = new ScenarioPathStep(
                    stateRoute: $this->routeKey($nextState),
                    stateKey: $nextState->key ?? '',
                    classification: $this->graph->classifyState($nextState),
                    event: $nextEvent,
                    guards: $nextGuards,
                    actions: $nextActions,
                    invokeClass: $this->getInvokeClass($nextState),
                    availableEvents: $this->graph->availableEventsFrom($nextState),
                    availableDoneStates: $this->graph->delegationOutcomes($nextState),
                    entryActions: $this->getEntryActions($nextState),
                );

                $newPath                    = [...$currentPath, $step];
                $newVisited                 = $visited;
                $newVisited[$nextState->id] = true;

                if ($nextState->id === $targetId) {
                    $results[] = new ScenarioPath($newPath);

                    continue; // Found one path, keep searching for alternatives
                }

                $queue[] = [$nextState, $newPath, $newVisited];
            }
        }
    }

    /**
     * Get reachable next states from current state (based on classification).
     *
     * @return list<array{0: StateDefinition, 1: string, 2: array<int, string>, 3: array<int, string>}> [state, event, guards, actions]
     */
    private function getNextStates(StateDefinition $state): array
    {
        $classification = $this->graph->classifyState($state);
        $next           = [];

        switch ($classification) {
            case StateClassification::TRANSIENT:
                // Follow @always branches
                $transitions = $this->graph->transitionsFrom($state);
                $always      = $transitions[TransitionProperty::Always->value] ?? null;
                if ($always !== null) {
                    foreach ($always->branches ?? [] as $branch) {
                        if ($branch->target instanceof StateDefinition) {
                            $next[] = [$branch->target, '@always', $branch->guards ?? [], $branch->actions ?? []];
                        }
                    }
                }
                break;

            case StateClassification::DELEGATION:
                // Follow @done/@fail/@timeout transitions.
                // These are stored on separate properties (onDoneTransition, etc.)
                // AND/OR in transitionDefinitions (when defined via 'on' key).
                $doneTransitions = [];

                // Collect from dedicated properties
                if ($state->onDoneTransition instanceof TransitionDefinition) {
                    $doneTransitions['@done'] = $state->onDoneTransition;
                }
                foreach ($state->onDoneStateTransitions as $doneState => $transition) {
                    $doneTransitions["@done.{$doneState}"] = $transition;
                }
                if ($state->onFailTransition instanceof TransitionDefinition) {
                    $doneTransitions['@fail'] = $state->onFailTransition;
                }
                if ($state->onTimeoutTransition instanceof TransitionDefinition) {
                    $doneTransitions['@timeout'] = $state->onTimeoutTransition;
                }

                // Also check transitionDefinitions (for states defined via 'on' key)
                foreach ($state->transitionDefinitions ?? [] as $event => $transition) {
                    if (str_starts_with((string) $event, '@done') || $event === '@fail' || $event === '@timeout') {
                        $doneTransitions[$event] = $transition;
                    }
                }

                foreach ($doneTransitions as $event => $transition) {
                    foreach ($transition->branches ?? [] as $branch) {
                        if ($branch->target instanceof StateDefinition) {
                            $next[] = [$branch->target, $event, $branch->guards ?? [], $branch->actions ?? []];
                        }
                    }
                }
                break;

            case StateClassification::PARALLEL:
                // Follow @done/@fail transitions AND regular 'on' transitions.
                // Parallel states can have both @done/@fail AND normal event transitions
                // (e.g., ApplicationSubmittedEvent on data_collection parallel state).
                $parallelTransitions = [];

                if ($state->onDoneTransition instanceof TransitionDefinition) {
                    $parallelTransitions['@done'] = $state->onDoneTransition;
                }
                if ($state->onFailTransition instanceof TransitionDefinition) {
                    $parallelTransitions['@fail'] = $state->onFailTransition;
                }
                // Include ALL transitionDefinitions (both @done-style and regular events)
                foreach ($state->transitionDefinitions ?? [] as $event => $transition) {
                    $parallelTransitions[$event] = $transition;
                }

                foreach ($parallelTransitions as $event => $transition) {
                    foreach ($transition->branches ?? [] as $branch) {
                        if ($branch->target instanceof StateDefinition) {
                            $next[] = [$branch->target, $event, $branch->guards ?? [], $branch->actions ?? []];
                        }
                    }
                }
                break;

            case StateClassification::COMPOUND:
                // Enter the initial child state (recursive descent through nested compounds)
                $initialChild = $state->findInitialStateDefinition();
                if ($initialChild instanceof StateDefinition && $initialChild->id !== $state->id) {
                    $next[] = [$initialChild, '@entry', [], []];
                }
                break;

            case StateClassification::INTERACTIVE:
                // Follow all event transitions
                $transitions = $this->graph->transitionsFrom($state);
                foreach ($transitions as $event => $transition) {
                    if ($event === TransitionProperty::Always->value) {
                        continue;
                    }
                    foreach ($transition->branches ?? [] as $branch) {
                        if ($branch->target instanceof StateDefinition) {
                            $next[] = [$branch->target, $event, $branch->guards ?? [], $branch->actions ?? []];
                        }
                    }
                }
                break;

            case StateClassification::FINAL:
                // Dead end — no next states
                break;
        }

        return $next;
    }

    /**
     * Get the short route key for a state (strip machine ID prefix).
     */
    private function routeKey(StateDefinition $state): string
    {
        $machineId = $this->graph->definition()->id;
        $id        = $state->id;

        if (str_starts_with($id, $machineId.'.')) {
            return substr($id, strlen($machineId) + 1);
        }

        return $id;
    }

    /**
     * Resolve a deep target (cross-delegation) into parent target + child target.
     *
     * Input: 'findeks.awaiting_birth_date_correction'
     * Output: ['parentTarget' => 'verification', 'childMachine' => FindeksMachine::class, 'childTarget' => 'awaiting_birth_date_correction']
     * Returns null if target is not a deep target.
     */
    /**
     * @return array{parentTarget: string, childMachine: class-string, childTarget: string}|null
     */
    public function resolveDeepTarget(string $target): ?array
    {
        // First check if the target exists directly in the machine
        try {
            $this->graph->resolveState($target);

            return null; // Direct target, not deep
        } catch (\InvalidArgumentException) {
            // Not found — might be a deep target
        }

        // Try to parse as region_key.child_state
        $parts = explode('.', $target, 2);
        if (count($parts) < 2) {
            return null; // Can't be a deep target without at least 2 parts
        }

        // Walk the idMap looking for delegation states whose path contains the first part
        $definition = $this->graph->definition();

        foreach ($definition->idMap as $id => $state) {
            if (!$state->hasMachineInvoke()) {
                continue;
            }

            // Check if the state's path contains the prefix (e.g., 'verification.findeks.running' contains 'findeks')
            if (!str_contains((string) $id, '.'.$parts[0].'.') && !str_ends_with((string) $id, '.'.$parts[0])) {
                continue;
            }

            $childMachineClass = $state->getMachineInvokeDefinition()?->machineClass;
            if ($childMachineClass === null) {
                continue;
            }
            if ($childMachineClass === '') {
                continue;
            }
            if (!class_exists($childMachineClass)) {
                continue;
            }

            // Check if the child state part exists in the child machine
            $childDefinition = $childMachineClass::definition();
            $childTarget     = $parts[1];
            $found           = $childDefinition->idMap[$childTarget]
                ?? $childDefinition->idMap[$childDefinition->id.'.'.$childTarget]
                ?? null;

            if ($found !== null) {
                // Find the parallel parent state (e.g., 'verification')
                $parentState = $state->parent;
                while ($parentState !== null && $parentState->parent !== null) {
                    if ($parentState->type === StateDefinitionType::PARALLEL) {
                        break;
                    }
                    $parentState = $parentState->parent;
                }

                $parentTarget = $parentState !== null
                    ? substr($parentState->id, strlen($definition->id) + 1)
                    : substr($state->id, strlen($definition->id) + 1);

                return [
                    'parentTarget'    => $parentTarget,
                    'delegationState' => substr($state->id, strlen($definition->id) + 1),
                    'childMachine'    => $childMachineClass,
                    'childTarget'     => $childTarget,
                ];
            }
        }

        return null;
    }

    /**
     * Get entry action names from a state definition.
     *
     * @return list<string>
     */
    private function getEntryActions(StateDefinition $state): array
    {
        if ($state->entry === null || $state->entry === []) {
            return [];
        }

        $actions = [];
        foreach ($state->entry as $entryDef) {
            if (is_string($entryDef)) {
                // Plain string format: 'entry' => SomeAction::class
                $actions[] = $entryDef;
            } elseif (is_array($entryDef)) {
                // Array format: 'entry' => [['action' => SomeAction::class, ...]]
                $action = $entryDef['action'] ?? null;
                if ($action !== null) {
                    $actions[] = $action;
                }
            }
        }

        return $actions;
    }

    /**
     * Get invoke class for a delegation state.
     */
    private function getInvokeClass(StateDefinition $state): ?string
    {
        if (!$state->hasMachineInvoke()) {
            return null;
        }

        $def = $state->getMachineInvokeDefinition();

        return $def?->isJob()
            ? $def->jobClass
            : ($def?->machineClass !== '' ? $def?->machineClass : null);
    }
}
