<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Definition\TransitionDefinition;
use Tarfinlabs\EventMachine\Definition\MachineInvokeDefinition;

/**
 * Enumerates all paths through a state machine definition using DFS with backtracking.
 *
 * Produces a PathEnumerationResult containing all terminal paths (HAPPY, FAIL, TIMEOUT,
 * LOOP, GUARD_BLOCK, DEAD_END) and per-region parallel path groups.
 */
class PathEnumerator
{
    /** @var list<MachinePath> Accumulated terminal paths. */
    private array $paths = [];

    /** @var list<ParallelPathGroup> Accumulated parallel region groups. */
    private array $parallelGroups = [];

    /** Maximum number of paths before stopping enumeration. Prevents explosion in large machines. */
    private int $maxPaths = 1000;

    /** Whether the path limit was reached during enumeration. */
    private bool $pathLimitReached = false;

    /** @var array<string, list<array{suffixSteps: list<PathStep>, type: PathType, terminalStateId: ?string}>> */
    private array $suffixCache = [];

    private readonly MachineGraph $graph;

    public function __construct(
        private readonly MachineDefinition $definition,
        int $maxPaths = 1000,
    ) {
        $this->maxPaths = $maxPaths;
        $this->graph    = new MachineGraph($definition);
    }

    /**
     * Enumerate all paths from the initial state.
     */
    public function enumerate(): PathEnumerationResult
    {
        $this->paths            = [];
        $this->parallelGroups   = [];
        $this->pathLimitReached = false;
        $this->suffixCache      = [];

        $initialState = $this->definition->initialStateDefinition;

        if ($initialState instanceof StateDefinition) {
            $this->dfs(
                state: $initialState,
                steps: [],
                visitedIds: [],
            );
        }

        return new PathEnumerationResult(
            paths: $this->paths,
            parallelGroups: $this->parallelGroups,
            definition: $this->definition,
            pathLimitReached: $this->pathLimitReached,
        );
    }

    /**
     * DFS with backtracking. PHP arrays are pass-by-value, so $visitedIds
     * is automatically copied on each recursive call — no explicit unset needed.
     *
     * @param  list<PathStep>  $steps  Steps accumulated so far.
     * @param  array<string, true>  $visitedIds  State IDs visited in the current fork.
     * @param  ?string  $event  Event that caused transition to this state (null for initial).
     * @param  ?int  $branchIndex  Branch index for guarded transitions.
     * @param  array<string>  $guards  Guard names on this transition.
     * @param  array<string>  $actions  Action names on this transition.
     * @param  ?string  $timerType  Timer type if timer-triggered.
     * @param  ?string  $invokeType  Invoke type if machine invoke transition.
     */
    private function dfs(
        StateDefinition $state,
        array $steps,
        array $visitedIds,
        ?string $event = null,
        ?int $branchIndex = null,
        array $guards = [],
        array $actions = [],
        ?string $timerType = null,
        ?string $invokeType = null,
    ): void {
        // 0. Path limit — stop DFS when limit reached
        if ($this->pathLimitReached) {
            return;
        }

        // 1. Cycle detection — very first check
        if (isset($visitedIds[$state->id])) {
            // Add the cycle target as the last step so the signature includes
            // which state the loop returns to (and via which event).
            // Without this, different loops ending at the same state before the
            // cycle target would produce identical signatures.
            $steps[] = new PathStep(
                stateId: $state->id,
                stateKey: $state->key ?? '',
                event: $event,
                branchIndex: $branchIndex,
                guards: $guards,
                actions: $actions,
                timerType: $timerType,
                invokeType: $invokeType,
            );

            $this->recordPath($steps, PathType::LOOP);

            return;
        }

        // 2. Detect invokeClass if this state delegates to a child machine or job
        $invokeClass = null;

        if ($state->hasMachineInvoke()) {
            $def         = $state->getMachineInvokeDefinition();
            $invokeClass = $def->isJob()
                ? $def->jobClass
                : ($def->machineClass !== '' ? $def->machineClass : null);
        }

        // 3. Add current state as a step (with transition + invoke metadata)
        $currentStep = new PathStep(
            stateId: $state->id,
            stateKey: $state->key ?? '',
            event: $event,
            branchIndex: $branchIndex,
            guards: $guards,
            actions: $actions,
            timerType: $timerType,
            invokeType: $invokeType,
            invokeClass: $invokeClass,
        );
        $steps[] = $currentStep;

        $visitedIds[$state->id] = true;

        // 4. Suffix memoization — if we've explored this state before,
        //    replay cached suffixes instead of re-exploring.
        //    Key is stateId only (not visitedIds). This means cycle detection
        //    results from the first exploration are reused, which may miss some
        //    LOOP variations when the same state is reached via different prefixes.
        //    The tradeoff is acceptable: it prevents exponential explosion in
        //    large machines (117+ states) while producing correct results for
        //    non-cyclic portions of the graph.
        $cacheKey = $state->id;

        if (isset($this->suffixCache[$cacheKey])) {
            $prefixLength = count($steps);

            foreach ($this->suffixCache[$cacheKey] as $cached) {
                $fullSteps = [...$steps, ...$cached['suffixSteps']];
                $this->recordPath($fullSteps, $cached['type']);
            }

            return;
        }

        // Record path count before exploration so we can extract suffixes afterward.
        $pathCountBefore = count($this->paths);

        // 5. Dispatch by state type
        match ($state->type) {
            StateDefinitionType::FINAL    => $this->handleFinal($state, $steps, $visitedIds),
            StateDefinitionType::PARALLEL => $this->handleParallel($state, $steps, $visitedIds),
            StateDefinitionType::COMPOUND => $this->handleCompound($state, $steps, $visitedIds),
            StateDefinitionType::ATOMIC   => $this->handleAtomic($state, $steps, $visitedIds),
        };

        // 6. Cache the suffixes discovered from this (state, visitedIds) combination.
        if (!$this->pathLimitReached) {
            $prefixLength = count($steps);
            $suffixes     = [];
            $counter      = count($this->paths);

            for ($i = $pathCountBefore; $i < $counter; $i++) {
                $path        = $this->paths[$i];
                $suffixSteps = array_slice($path->steps, $prefixLength);
                $suffixes[]  = [
                    'suffixSteps'     => $suffixSteps,
                    'type'            => $path->type,
                    'terminalStateId' => $path->terminalStateId,
                ];
            }

            $this->suffixCache[$cacheKey] = $suffixes;
        }
    }

    /**
     * FINAL state: check for compound @done continuation, otherwise record as terminal.
     *
     * @param  list<PathStep>  $steps
     * @param  array<string, true>  $visitedIds
     */
    private function handleFinal(StateDefinition $state, array $steps, array $visitedIds): void
    {
        // Check compound @done continuation
        $parent = $state->parent;

        if (
            $parent instanceof StateDefinition
            && $parent->type === StateDefinitionType::COMPOUND
            && $parent->onDoneTransition instanceof TransitionDefinition
        ) {
            // Follow compound @done branches
            foreach ($parent->onDoneTransition->branches ?? [] as $index => $branch) {
                if ($branch->target instanceof StateDefinition) {
                    $this->dfs(
                        state: $branch->target,
                        steps: $steps,
                        visitedIds: $visitedIds,
                        event: '@done',
                        branchIndex: count($parent->onDoneTransition->branches) > 1 ? $index : null,
                        guards: $branch->guards ?? [],
                        actions: $branch->actions ?? [],
                        invokeType: '@done',
                    );
                }
            }

            return;
        }

        // No compound @done — record as terminal
        $this->recordPath($steps, $this->classifyPath($steps));
    }

    /**
     * PARALLEL state: enumerate per-region paths and follow @done/@fail.
     *
     * @param  list<PathStep>  $steps
     * @param  array<string, true>  $visitedIds
     */
    private function handleParallel(StateDefinition $state, array $steps, array $visitedIds): void
    {
        // Enumerate per-region paths
        $regionPaths = [];

        if ($state->stateDefinitions !== null) {
            foreach ($state->stateDefinitions as $regionKey => $region) {
                $regionEnumerator = new self($this->definition);
                $regionInitial    = $region->findInitialStateDefinition();

                if ($regionInitial instanceof StateDefinition) {
                    $regionEnumerator->dfs($regionInitial, [], []);
                }

                $regionPaths[$regionKey] = $regionEnumerator->paths;
            }
        }

        // Only add to parallelGroups if this parallel state hasn't been recorded yet
        // (multiple DFS forks can discover the same parallel state independently)
        $alreadyRecorded = false;

        foreach ($this->parallelGroups as $existing) {
            if ($existing->parallelStateId === $state->id) {
                $alreadyRecorded = true;

                break;
            }
        }

        if (!$alreadyRecorded) {
            $this->parallelGroups[] = new ParallelPathGroup(
                parallelStateId: $state->id,
                regionPaths: $regionPaths,
            );
        }

        // Follow @done transition
        if ($state->onDoneTransition instanceof TransitionDefinition) {
            foreach ($state->onDoneTransition->branches ?? [] as $index => $branch) {
                if ($branch->target instanceof StateDefinition) {
                    $this->dfs(
                        state: $branch->target,
                        steps: $steps,
                        visitedIds: $visitedIds,
                        event: '@done',
                        branchIndex: count($state->onDoneTransition->branches) > 1 ? $index : null,
                        guards: $branch->guards ?? [],
                        actions: $branch->actions ?? [],
                        invokeType: '@done',
                    );
                }
            }
        }

        // Follow @fail transition
        if ($state->onFailTransition instanceof TransitionDefinition) {
            foreach ($state->onFailTransition->branches ?? [] as $index => $branch) {
                if ($branch->target instanceof StateDefinition) {
                    $this->dfs(
                        state: $branch->target,
                        steps: $steps,
                        visitedIds: $visitedIds,
                        event: '@fail',
                        branchIndex: count($state->onFailTransition->branches) > 1 ? $index : null,
                        guards: $branch->guards ?? [],
                        actions: $branch->actions ?? [],
                        invokeType: '@fail',
                    );
                }
            }
        }

        // No @done and no @fail — dead-end parallel
        if (!$state->onDoneTransition instanceof TransitionDefinition && !$state->onFailTransition instanceof TransitionDefinition) {
            $this->recordPath($steps, PathType::DEAD_END);
        }
    }

    /**
     * COMPOUND state: drill down to initial child.
     *
     * @param  list<PathStep>  $steps
     * @param  array<string, true>  $visitedIds
     */
    private function handleCompound(StateDefinition $state, array $steps, array $visitedIds): void
    {
        $initial = $state->findInitialStateDefinition();

        if ($initial instanceof StateDefinition) {
            $this->dfs($initial, $steps, $visitedIds);
        }
    }

    /**
     * ATOMIC state: collect transitions and enumerate paths.
     *
     * @param  list<PathStep>  $steps
     * @param  array<string, true>  $visitedIds
     */
    private function handleAtomic(StateDefinition $state, array $steps, array $visitedIds): void
    {
        // Step 1: Collect all transitions (own + inherited via parent chain)
        $transitions      = $this->graph->transitionsFrom($state);
        $hasMachineInvoke = $state->hasMachineInvoke();

        // Step 2: Dead-end detection
        if ($transitions === [] && !$hasMachineInvoke) {
            $this->recordPath($steps, PathType::DEAD_END);

            return;
        }

        // Step 3: @always priority
        $alwaysTransition = null;

        foreach ($transitions as $event => $transition) {
            if ($transition->isAlways) {
                $alwaysTransition = $transition;
                unset($transitions[$event]);

                break;
            }
        }

        if ($alwaysTransition !== null) {
            $this->handleAlwaysPriority($state, $steps, $visitedIds, $alwaysTransition, $transitions);

            return;
        }

        // Step 4: Enumerate remaining transitions
        $this->enumerateTransitions($state, $steps, $visitedIds, $transitions);
    }

    /**
     * Handle @always transition priority.
     *
     * - Unguarded @always: follow exclusively — skip all other transitions (unreachable).
     * - Guarded @always: fork into guard-pass paths + guard-fail continuation.
     *   Guard-fail enumerates remaining non-@always transitions. If none exist → GUARD_BLOCK.
     *
     * @param  list<PathStep>  $steps
     * @param  array<string, true>  $visitedIds
     * @param  array<string, TransitionDefinition>  $remainingTransitions  Non-@always transitions.
     */
    private function handleAlwaysPriority(
        StateDefinition $state,
        array $steps,
        array $visitedIds,
        TransitionDefinition $alwaysTransition,
        array $remainingTransitions,
    ): void {
        // @always is guaranteed to fire if ANY branch has no guard (unguarded fallback).
        // In runtime, getFirstValidTransitionBranch() tries branches in order — if all
        // guarded branches fail, the unguarded fallback is always taken. So guard-fail
        // continuation (enumerating remaining events) is only needed when ALL branches
        // have guards (every branch could fail).
        $hasUnguardedFallback = !$this->isAllBranchesGuarded($alwaysTransition);

        // Enumerate @always guard-pass forks
        foreach ($alwaysTransition->branches ?? [] as $index => $branch) {
            if (!$branch->target instanceof StateDefinition) {
                continue;
            }

            $this->dfs(
                state: $branch->target,
                steps: $steps,
                visitedIds: $visitedIds,
                event: '@always',
                branchIndex: count($alwaysTransition->branches ?? []) > 1 ? $index : null,
                guards: $branch->guards ?? [],
                actions: $branch->actions ?? [],
            );
        }

        // If @always has an unguarded fallback: it always fires — remaining transitions unreachable
        if ($hasUnguardedFallback) {
            return;
        }

        // Guarded @always: guard-fail continuation — enumerate remaining transitions
        if ($remainingTransitions !== [] || $state->hasMachineInvoke()) {
            $this->enumerateTransitions($state, $steps, $visitedIds, $remainingTransitions);
        } else {
            // No remaining transitions and guard failed → GUARD_BLOCK
            $this->recordPath($steps, PathType::GUARD_BLOCK);
        }
    }

    /**
     * Enumerate all collected transitions from an atomic state.
     *
     * @param  list<PathStep>  $steps
     * @param  array<string, true>  $visitedIds
     * @param  array<string, TransitionDefinition>  $transitions
     */
    private function enumerateTransitions(
        StateDefinition $state,
        array $steps,
        array $visitedIds,
        array $transitions,
    ): void {
        $enumerated = false;

        foreach ($transitions as $event => $transition) {
            // @always is handled before enumerateTransitions is called
            if ($transition->isAlways) {
                continue;
            }

            $timerType = $transition->timerDefinition?->type;

            foreach ($transition->branches ?? [] as $index => $branch) {
                // Skip self-transitions (target === null)
                if (!$branch->target instanceof StateDefinition) {
                    continue;
                }

                $this->dfs(
                    state: $branch->target,
                    steps: $steps,
                    visitedIds: $visitedIds,
                    event: $event,
                    branchIndex: count($transition->branches ?? []) > 1 ? $index : null,
                    guards: $branch->guards ?? [],
                    actions: $branch->actions ?? [],
                    timerType: $timerType,
                );
                $enumerated = true;
            }

            // GUARD_BLOCK: all branches are guarded, no unguarded fallback
            if ($this->isAllBranchesGuarded($transition)) {
                $this->recordPath($steps, PathType::GUARD_BLOCK);
                $enumerated = true;
            }
        }

        // Machine invoke transitions (@done, @fail, @timeout, @done.{state}, fire-and-forget)
        if ($state->hasMachineInvoke()) {
            $this->enumerateMachineInvoke($state, $steps, $visitedIds);
            $enumerated = true;
        }

        // If nothing was enumerated (e.g., only @always transitions exist but we skip them)
        if (!$enumerated) {
            $this->recordPath($steps, PathType::DEAD_END);
        }
    }

    /**
     * Check if all branches of a transition have guards (no unguarded fallback).
     */
    private function isAllBranchesGuarded(TransitionDefinition $transition): bool
    {
        if ($transition->branches === null || $transition->branches === []) {
            return false;
        }

        foreach ($transition->branches as $branch) {
            if ($branch->guards === null || $branch->guards === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Enumerate machine invoke transitions (@done, @fail, @timeout, @done.{state}, fire-and-forget).
     *
     * @param  list<PathStep>  $steps
     * @param  array<string, true>  $visitedIds
     */
    private function enumerateMachineInvoke(StateDefinition $state, array $steps, array $visitedIds): void
    {
        $invokeDefinition = $state->getMachineInvokeDefinition();

        // Fire-and-forget: target property set, no @done
        if ($invokeDefinition instanceof MachineInvokeDefinition && $invokeDefinition->target !== null) {
            $targetState = $this->definition->getNearestStateDefinitionByString($invokeDefinition->target, $state);

            if ($targetState instanceof StateDefinition) {
                $this->dfs(
                    state: $targetState,
                    steps: $steps,
                    visitedIds: $visitedIds,
                    event: 'fire-and-forget',
                    invokeType: 'fire-and-forget',
                );
            }

            return;
        }

        // @done.{state} transitions — per-final-state routing
        foreach ($state->onDoneStateTransitions as $finalStateName => $transition) {
            $this->followInvokeTransition($transition, $steps, $visitedIds, "@done.{$finalStateName}");
        }

        // @done catch-all transition
        if ($state->onDoneTransition instanceof TransitionDefinition) {
            $this->followInvokeTransition($state->onDoneTransition, $steps, $visitedIds, '@done');
        }

        // @fail transition
        if ($state->onFailTransition instanceof TransitionDefinition) {
            $this->followInvokeTransition($state->onFailTransition, $steps, $visitedIds, '@fail');
        }

        // @timeout transition
        if ($state->onTimeoutTransition instanceof TransitionDefinition) {
            $this->followInvokeTransition($state->onTimeoutTransition, $steps, $visitedIds, '@timeout');
        }
    }

    /**
     * Follow an invoke transition (@done/@fail/@timeout) with all its branches.
     *
     * @param  list<PathStep>  $steps
     * @param  array<string, true>  $visitedIds
     */
    private function followInvokeTransition(
        TransitionDefinition $transition,
        array $steps,
        array $visitedIds,
        string $invokeEvent,
    ): void {
        foreach ($transition->branches ?? [] as $index => $branch) {
            if (!$branch->target instanceof StateDefinition) {
                continue;
            }

            $this->dfs(
                state: $branch->target,
                steps: $steps,
                visitedIds: $visitedIds,
                event: $invokeEvent,
                branchIndex: count($transition->branches) > 1 ? $index : null,
                guards: $branch->guards ?? [],
                actions: $branch->actions ?? [],
                invokeType: $invokeEvent,
            );
        }
    }

    /**
     * Record a completed path.
     *
     * @param  list<PathStep>  $steps
     */
    private function recordPath(array $steps, PathType $type): void
    {
        if (count($this->paths) >= $this->maxPaths) {
            $this->pathLimitReached = true;

            return;
        }

        $terminalStateId = $steps !== [] ? $steps[count($steps) - 1]->stateId : null;

        // For non-terminal types, clear the terminal state
        if (in_array($type, [PathType::LOOP, PathType::GUARD_BLOCK], true)) {
            $terminalStateId = null;
        }

        $this->paths[] = new MachinePath(
            steps: $steps,
            type: $type,
            terminalStateId: $terminalStateId,
        );
    }

    /**
     * Classify a completed path by scanning its steps (priority order).
     *
     * @param  list<PathStep>  $steps
     */
    private function classifyPath(array $steps): PathType
    {
        $hasFailStep    = false;
        $hasTimeoutStep = false;

        foreach ($steps as $step) {
            if ($step->invokeType === '@fail') {
                $hasFailStep = true;
            }

            if ($step->timerType !== null || $step->invokeType === '@timeout') {
                $hasTimeoutStep = true;
            }
        }

        // Priority: FAIL > TIMEOUT > DEAD_END > HAPPY
        // (LOOP and GUARD_BLOCK are set directly during DFS, not here)
        if ($hasFailStep) {
            return PathType::FAIL;
        }

        if ($hasTimeoutStep) {
            return PathType::TIMEOUT;
        }

        // Check if terminal state is a dead-end (ATOMIC, no transitions, not FINAL)
        $lastStep = $steps[count($steps) - 1] ?? null;

        if ($lastStep !== null) {
            $lastState = $this->definition->idMap[$lastStep->stateId] ?? null;

            if (
                $lastState instanceof StateDefinition
                && $lastState->type === StateDefinitionType::ATOMIC
                && ($lastState->transitionDefinitions === null || $lastState->transitionDefinitions === [])
            ) {
                return PathType::DEAD_END;
            }
        }

        return PathType::HAPPY;
    }
}
