<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

use Throwable;
use SplPriorityQueue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Enums\TransitionProperty;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
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
     * @param  int  $maxIterations  Expansion cap for the whole resolution. The default is the value this
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
     * Find the cheapest path from source to target via the trigger event.
     *
     * Cheapest by total weight, then by step count. This is resolveAll()[0] and deliberately
     * not the first target the search reaches: those two disagree whenever equally priced
     * routes accumulate their weight at different points.
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

        // Seed one frontier from every branch of the trigger event's transition
        // One frontier for every branch of the trigger, seeded in the order the branches appear
        // in the transition definition. A per-branch search cannot deliver a cost-ordered
        // frontier: the first branch would exhaust its expensive paths before the second was
        // given a single expansion.
        $frontier = [];

        foreach ($eventTransition->branches ?? [] as $branch) {
            if (!$branch->target instanceof StateDefinition) {
                continue;
            }

            $firstStep = $this->step(
                state: $branch->target,
                event: $event,
                guards: $branch->guards ?? [],
                actions: $branch->actions ?? [],
            );

            // A branch whose target IS the resolution target is a one-step path. It is recorded
            // here and never placed on the frontier, so no target state is ever expanded.
            if ($branch->target->id === $targetId) {
                $paths[] = new ScenarioPath([$firstStep]);

                continue;
            }

            // Seeded at the cost of its own first step, not at zero. `visited` holds that branch
            // target and nothing else: the trigger's source is deliberately absent, so a cyclic
            // machine may re-enter it as an ordinary step and be priced for it.
            $frontier[] = new FrontierEntry(
                state: $branch->target,
                path: [$firstStep],
                visited: [$branch->target->id => true],
                cost: $firstStep->classification->weight(),
            );
        }

        $this->bestFirst($frontier, $targetId, $paths);

        // Sort globally, not per branch: the loop above accumulates from every branch of the
        // trigger transition, so anything narrower would leave a cheap path found by a later
        // branch behind an expensive one found by an earlier one.
        //
        // Two keys, in order: total weight, then step count — among equally priced paths the
        // shorter one is a shorter plan(). Paths tied on BOTH have no specified order; usort()
        // is stable as of PHP 8.0, so they keep the order they were found in, but that order is
        // deliberately not a promise and no test may assert it.
        usort(
            $paths,
            static fn (ScenarioPath $a, ScenarioPath $b): int => [$a->totalWeight, count($a->steps)]
                <=> [$b->totalWeight, count($b->steps)],
        );

        return $paths;
    }

    /**
     * Walk one frontier to the target, recording every simple path that reaches it.
     *
     * The seeds arrive from resolveAll(), which is what makes this a single search over every
     * branch of the trigger rather than one search per branch.
     *
     * Four properties, and only these four, are promised:
     *
     *   1. entries expand in non-decreasing order of accumulated cost;
     *   2. among entries of equal cost, expansion order is insertion order (FIFO);
     *   3. for a given definition and cap, both the returned set and its order are the same on
     *      every run and in every process;
     *   4. the truncation flag is set exactly when the loop stops with the frontier non-empty.
     *
     * The second is not automatic. SplPriorityQueue gives no defined order among equal
     * priorities, so a bare -$cost priority would leave which paths were found before the cap
     * dependent on heap internals. The composite [-$cost, -$sequence] fixes it: PHP compares
     * array priorities element-wise, so this is cost first, insertion order second, and the
     * negation is because the queue is a max-heap.
     *
     * @param  list<FrontierEntry>  $seeds
     * @param  list<ScenarioPath>  $results  Accumulated results (passed by reference).
     */
    private function bestFirst(array $seeds, string $targetId, array &$results): void
    {
        /** @var SplPriorityQueue<array{0: int, 1: int}, FrontierEntry> $frontier */
        $frontier = new SplPriorityQueue();
        $frontier->setExtractFlags(SplPriorityQueue::EXTR_DATA);

        $sequence = 0;

        foreach ($seeds as $seed) {
            $frontier->insert($seed, [-$seed->cost, -$sequence]);
            $sequence++;
        }

        // One budget for the whole resolution, not one per branch, counting expansions: one
        // increment per entry taken off the frontier. For a multi-branch trigger the effective
        // ceiling therefore drops — it used to be maxIterations times the branch count.
        $maxIter = $this->maxIterations;
        $iter    = 0;

        while (!$frontier->isEmpty() && $iter < $maxIter) {
            $iter++;
            $entry = $frontier->extract();

            foreach ($this->getNextStates($entry->state) as [$nextState, $nextEvent, $nextGuards, $nextActions]) {
                if (isset($entry->visited[$nextState->id])) {
                    continue; // Cycle
                }

                $step    = $this->step($nextState, $nextEvent, $nextGuards, $nextActions);
                $newPath = [...$entry->path, $step];

                // Recorded when its final step is PUSHED. A target is never placed on the
                // frontier and never expanded, so every path is recorded exactly once.
                if ($nextState->id === $targetId) {
                    $results[] = new ScenarioPath($newPath);

                    continue; // Found one path, keep searching for alternatives
                }

                $newVisited                 = $entry->visited;
                $newVisited[$nextState->id] = true;
                $nextCost                   = $entry->cost + $step->classification->weight();

                $frontier->insert(
                    new FrontierEntry($nextState, $newPath, $newVisited, $nextCost),
                    [-$nextCost, -$sequence],
                );
                $sequence++;
            }
        }

        // The loop can end two ways, and they mean opposite things: an empty frontier is
        // an exhausted search, while a non-empty one means the cap stopped us with work
        // still pending. Without recording that, a caller cannot tell "there is no path"
        // from "I stopped looking".
        if (!$frontier->isEmpty()) {
            $this->truncated = true;
        }
    }

    /**
     * Build the classified step for entering a state.
     *
     * @param  array<int, string>  $guards
     * @param  array<int, string>  $actions
     */
    private function step(StateDefinition $state, string $event, array $guards, array $actions): ScenarioPathStep
    {
        return new ScenarioPathStep(
            stateRoute: $this->routeKey($state),
            stateKey: $state->key ?? '',
            classification: $this->graph->classifyState($state),
            event: $event,
            guards: $guards,
            actions: $actions,
            invokeClass: $this->getInvokeClass($state),
            availableEvents: $this->graph->availableEventsFrom($state),
            availableDoneStates: $this->graph->delegationOutcomes($state),
            entryActions: $this->getEntryActions($state),
        );
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
            // is_subclass_of, not class_exists: the value comes from a machine config, so it can
            // name anything, and the next line calls a static method on it.
            if (!is_subclass_of($childMachineClass, Machine::class)) {
                continue;
            }

            // Check if the child state part exists in the child machine.
            //
            // A child whose definition() throws means "no path through this child", which is
            // what continue already says here. Letting it escape was worse than useless: this
            // runs from MachineScenarioCommand outside its try/catch, so the throw reached the
            // user as a stack trace instead of the command's own "no path" report.
            try {
                $childDefinition = $childMachineClass::definition();
            } catch (Throwable) {
                continue;
            }

            if (!$childDefinition instanceof MachineDefinition) {
                continue;
            }

            $childTarget = $parts[1];
            $found       = $childDefinition->idMap[$childTarget]
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
