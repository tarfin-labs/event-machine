<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

use Tarfinlabs\EventMachine\Enums\TransitionProperty;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
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
        $transitions     = $this->graph->transitionsFrom($sourceState);
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
     * @return list<array{0: StateDefinition, 1: string, 2: array, 3: array}> [state, event, guards, actions]
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
                // Follow @done/@fail/@timeout transitions
                $transitions = $state->transitionDefinitions ?? [];
                foreach ($transitions as $event => $transition) {
                    if (!str_starts_with((string) $event, '@done') && $event !== '@fail' && $event !== '@timeout') {
                        continue;
                    }
                    foreach ($transition->branches ?? [] as $branch) {
                        if ($branch->target instanceof StateDefinition) {
                            $next[] = [$branch->target, $event, $branch->guards ?? [], $branch->actions ?? []];
                        }
                    }
                }
                break;

            case StateClassification::PARALLEL:
                // Follow @done transition
                $transitions = $state->transitionDefinitions ?? [];
                foreach ($transitions as $event => $transition) {
                    if (!str_starts_with((string) $event, '@done')) {
                        continue;
                    }
                    foreach ($transition->branches ?? [] as $branch) {
                        if ($branch->target instanceof StateDefinition) {
                            $next[] = [$branch->target, $event, $branch->guards ?? [], $branch->actions ?? []];
                        }
                    }
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
