<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

use Throwable;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
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
    /**
     * Whether the most recent resolveAll() hit the iteration cap with work left.
     *
     * A capped search and a search that genuinely found nothing both return an
     * empty list, which is what made machine:scenario report a confident "No path"
     * for targets that exist.
     */
    private bool $truncated = false;

    /**
     * @param  int  $maxIterations  BFS iteration cap. The default is the value this
     *                              resolver has always used; it is a constructor setting
     *                              only so the truncation behaviour can be exercised
     *                              without changing what ships.
     */
    public function __construct(
        private readonly MachineGraph $graph,
        private readonly int $maxIterations = 1000,
    ) {}

    /**
     * Did the most recent resolution stop early rather than exhaust the search?
     *
     * Reset at the start of every resolveAll(), including the one resolve()
     * performs internally, so it always describes the latest resolution.
     */
    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Find the shortest path from source to target via the trigger event.
     */
    public function resolve(string $source, string $event, string $target): ScenarioPath
    {
        $paths = $this->resolveAll($source, $event, $target);

        if ($paths === []) {
            throw $this->truncated
                ? NoScenarioPathFoundException::truncated($source, $target, $this->graph->definition()->id)
                : NoScenarioPathFoundException::noPath($source, $target, $this->graph->definition()->id);
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
        $this->truncated = false;

        $sourceState = $this->graph->resolveState($source);
        $targetState = $this->graph->resolveState($target);

        // Find initial transitions from source via trigger event
        $transitions = $this->graph->transitionsFrom($sourceState);

        // @start: use the @always transition from the initial state
        if ($event === MachineScenario::START) {
            $eventTransition = $transitions[TransitionProperty::Always->value] ?? null;
        } else {
            $eventTransition = $transitions[$event] ?? null;

            // Try EventBehavior::getType() match.
            //
            // is_subclass_of, not class_exists + method_exists: $event is a raw CLI argument, so
            // class_exists() autoloads whatever it names and runs that file's top-level code
            // before anything has established it is an event at all, and method_exists() then
            // admits a non-static getType() — which raised `Non-static method cannot be called
            // statically` from inside the resolver. Requiring an EventBehavior subclass settles
            // both: the signature is guaranteed, and a stranger is never loaded.
            if ($eventTransition === null && is_subclass_of($event, EventBehavior::class)) {
                foreach ($transitions as $eventKey => $transition) {
                    if ($eventKey === $event::getType()) {
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
        $maxIter = $this->maxIterations;
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

        // The loop can end two ways, and they mean opposite things: an empty queue is
        // an exhausted search, while a non-empty one means the cap stopped us with work
        // still pending. Without recording that, a caller cannot tell "there is no path"
        // from "I stopped looking".
        if ($queue !== []) {
            $this->truncated = true;
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

                // Resolved through transitionsFrom(), so own and inherited transitions are
                // both included — the same set PathEnumerator uses for a parallel state.
                // Reading $state->transitionDefinitions here instead would leave the two
                // analysers disagreeing about the same definition, which is the defect this
                // change exists to remove rather than to invert. @always is skipped for the
                // same reason the INTERACTIVE case skips it: it is not an event a scenario
                // can send.
                foreach ($this->graph->transitionsFrom($state) as $event => $transition) {
                    if ($event === TransitionProperty::Always->value) {
                        continue;
                    }

                    $parallelTransitions[$event] = $transition;
                }

                foreach ($parallelTransitions as $event => $transition) {
                    foreach ($transition->branches ?? [] as $branch) {
                        if ($branch->target instanceof StateDefinition) {
                            $next[] = [$branch->target, $event, $branch->guards ?? [], $branch->actions ?? []];
                        }
                    }
                }

                // Descend into the regions. Entering a parallel state activates every
                // region at once, so each region's initial state really is reachable —
                // without this, every state inside a region looks unreachable and the
                // resolver answers "no path" for targets the machine reaches in practice.
                //
                // The label is @region rather than @entry because the two mean different
                // things: @entry is exclusive compound descent, while a route through one
                // region is a projection of a concurrent configuration whose sibling
                // regions are simultaneously active in unspecified states.
                foreach ($state->stateDefinitions ?? [] as $region) {
                    $regionInitial = $region->findInitialStateDefinition();

                    if ($regionInitial instanceof StateDefinition && $regionInitial->id !== $state->id) {
                        $next[] = [$regionInitial, '@region', [], []];
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
                // Not a dead end. Two continuations survive a final state, and treating it
                // as terminal made every target beyond one unreachable to the resolver
                // while the runtime reaches them without difficulty.
                //
                // First, the enclosing compound's @done fires on its own once the child is
                // final, which is how a nested flow hands control back up.
                $parent = $state->parent;

                // The COMPOUND guard matches the enumerator's: a PARALLEL parent's @done
                // fires only once EVERY region is final, so following it from one final
                // child would claim a continuation the runtime does not offer. Omitting
                // it here would reintroduce exactly the disagreement between the two
                // analysers that this arm exists to remove.
                if (
                    $parent instanceof StateDefinition
                    && $parent->type === StateDefinitionType::COMPOUND
                    && $parent->onDoneTransition instanceof TransitionDefinition
                ) {
                    foreach ($parent->onDoneTransition->branches ?? [] as $branch) {
                        if ($branch->target instanceof StateDefinition) {
                            $next[] = [$branch->target, '@done', $branch->guards ?? [], $branch->actions ?? []];
                        }
                    }
                }

                // Second, a handler declared on an ancestor still fires from a final child:
                // findTransitionDefinition walks the parent chain with no special case for
                // FINAL, so the machine can be driven out of a final state by an event.
                foreach ($this->graph->transitionsFrom($state) as $event => $transition) {
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
     * Output: [
     *   'parentTarget'    => 'verification',
     *   'delegationState' => 'verification.findeks.running',
     *   'childMachine'    => FindeksMachine::class,
     *   'childTarget'     => 'awaiting_birth_date_correction',
     * ]
     * Returns null if the target is not a deep target.
     *
     * @return array{parentTarget: string, delegationState: string, childMachine: class-string, childTarget: string}|null
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
