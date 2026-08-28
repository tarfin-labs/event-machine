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
    private readonly int $maxPaths;

    /** Whether the path limit was reached during enumeration. */
    private bool $pathLimitReached = false;

    /**
     * Maximum total analysis depth before a path is cut short.
     *
     * Measured as the depth inherited from the caller plus the steps accumulated
     * in this enumerator, so it bounds total recursion rather than each
     * enumerator separately. This is the structural backstop: whatever shape a
     * definition takes, enumeration terminates (E1).
     */
    private readonly int $maxDepth;

    /** Depth already consumed by the enumerator that created this one. */
    private readonly int $depthOffset;

    /** Whether the depth ceiling was reached during enumeration. */
    private bool $depthLimitReached = false;

    /** @var array<string, list<array{suffixSteps: list<PathStep>, type: PathType, terminalStateId: ?string}>> */
    private array $suffixCache = [];

    private readonly MachineGraph $graph;

    /**
     * When set, enumeration is confined to this state's subtree — the region
     * of a parallel state. Null for machine-level enumeration, which is
     * unaffected by every boundary rule below.
     */
    private readonly ?StateDefinition $boundary;

    /**
     * How many branches the boundary handed to machine-level enumeration (E3).
     * Callers compare this before and after enumerating a state's continuations
     * to tell "every continuation was deferred" from "there were none".
     */
    private int $deferrals = 0;

    public function __construct(
        private readonly MachineDefinition $definition,
        int $maxPaths = 1000,
        ?StateDefinition $boundary = null,
        int $maxDepth = 200,
        int $depthOffset = 0,
    ) {
        $this->maxPaths    = $maxPaths;
        $this->boundary    = $boundary;
        $this->maxDepth    = $maxDepth;
        $this->depthOffset = $depthOffset;
        $this->graph       = new MachineGraph($definition);
    }

    /**
     * Is this state inside the current boundary?
     *
     * Structural, by parent-chain identity — never a string prefix on the id.
     * StateDefinition::buildId() returns `$this->config['id'] ?? implode(...)`,
     * so an explicit `id` in the config bypasses the path-derived prefix
     * entirely, and the delimiter is a per-machine setting rather than a
     * literal dot. Both make a lexical test unsound.
     */
    private function isInsideBoundary(StateDefinition $state): bool
    {
        if (!$this->boundary instanceof StateDefinition) {
            return true;
        }

        $current = $state;

        while ($current instanceof StateDefinition) {
            if ($current === $this->boundary) {
                return true;
            }

            $current = $current->parent;
        }

        return false;
    }

    /**
     * Would following this branch leave the boundary?
     */
    private function leavesBoundary(?StateDefinition $target): bool
    {
        return $this->boundary instanceof StateDefinition
            && $target instanceof StateDefinition
            && !$this->isInsideBoundary($target);
    }

    /**
     * Is this transition declared inside the boundary, rather than inherited
     * from the parallel state or one of its ancestors?
     *
     * The distinction matters because the runtime treats the two differently:
     * a handler at or above the parallel state exits the whole parallel state
     * (MachineDefinition::isParallelEscapeSource), while one declared inside a
     * region only re-points that region's slot and leaves the parallel state
     * active. Only the second has no machine-level representation, so only the
     * second is recorded here as a region exit.
     */
    private function isDeclaredInsideBoundary(?StateDefinition $source): bool
    {
        return $source instanceof StateDefinition && $this->isInsideBoundary($source);
    }

    /**
     * Record the outcome for a branch the boundary stopped us following.
     *
     * Returns true when this call recorded a path, so the caller can tell an
     * outcome from silence. A branch inherited from at or above the parallel
     * state records nothing here: machine-level enumeration owns it (E3), and
     * whether the state as a whole is REGION_DEFERRED is the caller's decision,
     * since only the caller knows whether every continuation was deferred.
     *
     * @param  list<PathStep>  $steps
     * @param  array<string>  $guards
     * @param  array<string>  $actions
     */
    private function recordBoundarySkip(
        array $steps,
        StateDefinition $target,
        ?StateDefinition $source,
        ?string $event,
        array $guards = [],
        array $actions = [],
        ?string $timerType = null,
        ?string $invokeType = null,
    ): bool {
        if (!$this->isDeclaredInsideBoundary($source)) {
            $this->deferrals++;

            return false;
        }

        // Declared inside the region and leaving it. Nothing else represents
        // this edge, so the region path ends here with a step naming the event
        // and target that leave the region (E2).
        $steps[] = new PathStep(
            stateId: $target->id,
            stateKey: $target->key ?? '',
            event: $event,
            guards: $guards,
            actions: $actions,
            timerType: $timerType,
            invokeType: $invokeType,
        );

        $this->recordPath($steps, PathType::REGION_EXIT);

        return true;
    }

    /**
     * Enumerate all paths from the initial state.
     */
    public function enumerate(): PathEnumerationResult
    {
        $this->paths             = [];
        $this->parallelGroups    = [];
        $this->pathLimitReached  = false;
        $this->depthLimitReached = false;
        $this->deferrals         = 0;
        $this->suffixCache       = [];

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
            depthLimitReached: $this->depthLimitReached,
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

        // 0b. Depth ceiling — the structural backstop that makes E1 hold whatever
        // shape the graph takes. The branch is recorded as cut short rather than
        // dropped, so a truncated analysis can never be read as a complete one.
        if ($this->depthOffset + count($steps) >= $this->maxDepth) {
            $this->depthLimitReached = true;
            $this->recordPath($steps, PathType::TRUNCATED);

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
            $enumerated = false;
            $deferred   = false;

            // Follow compound @done branches
            foreach ($parent->onDoneTransition->branches ?? [] as $index => $branch) {
                if (!$branch->target instanceof StateDefinition) {
                    continue;
                }

                // The compound @done continuation is a way out of a region just like
                // any other transition, so the boundary applies to it too. Omitting it
                // would leave region enumeration able to escape.
                if ($this->leavesBoundary($branch->target)) {
                    $recorded = $this->recordBoundarySkip(
                        steps: $steps,
                        target: $branch->target,
                        source: $parent,
                        event: '@done',
                        guards: $branch->guards ?? [],
                        actions: $branch->actions ?? [],
                        invokeType: '@done',
                    );

                    $enumerated = $enumerated || $recorded;
                    $deferred   = $deferred || !$recorded;

                    continue;
                }

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
                $enumerated = true;
            }

            if (!$enumerated && $deferred) {
                $this->recordPath($steps, PathType::REGION_DEFERRED);
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
        // Enumerate per-region paths.
        //
        // Each region gets its own enumerator bounded to that region. Before this,
        // the sub-enumerator was unbounded and started with an empty visited set, so
        // region DFS followed an inherited ancestor transition straight out of the
        // region, walked the whole machine, and spawned another empty-visited
        // enumerator at the next parallel state it met — recursion that neither the
        // cycle check nor the path budget could stop, because neither crossed the
        // boundary.
        $regionPaths = [];

        if ($state->stateDefinitions !== null) {
            foreach ($state->stateDefinitions as $regionKey => $region) {
                $regionEnumerator = new self($this->definition, $this->maxPaths, $region);
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

        $enumerated = false;
        $deferred   = false;

        // Follow @done transition
        if ($state->onDoneTransition instanceof TransitionDefinition) {
            foreach ($state->onDoneTransition->branches ?? [] as $index => $branch) {
                if (!$branch->target instanceof StateDefinition) {
                    continue;
                }

                if ($this->leavesBoundary($branch->target)) {
                    $recorded = $this->recordBoundarySkip(
                        steps: $steps,
                        target: $branch->target,
                        source: $state,
                        event: '@done',
                        guards: $branch->guards ?? [],
                        actions: $branch->actions ?? [],
                        invokeType: '@done',
                    );

                    $enumerated = $enumerated || $recorded;
                    $deferred   = $deferred || !$recorded;

                    continue;
                }

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
                $enumerated = true;
            }
        }

        // Follow @fail transition
        if ($state->onFailTransition instanceof TransitionDefinition) {
            foreach ($state->onFailTransition->branches ?? [] as $index => $branch) {
                if (!$branch->target instanceof StateDefinition) {
                    continue;
                }

                if ($this->leavesBoundary($branch->target)) {
                    $recorded = $this->recordBoundarySkip(
                        steps: $steps,
                        target: $branch->target,
                        source: $state,
                        event: '@fail',
                        guards: $branch->guards ?? [],
                        actions: $branch->actions ?? [],
                        invokeType: '@fail',
                    );

                    $enumerated = $enumerated || $recorded;
                    $deferred   = $deferred || !$recorded;

                    continue;
                }

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
                $enumerated = true;
            }
        }

        // Follow the parallel state's own remaining transitions.
        //
        // Only the keys already followed by property above are excluded: anything
        // beginning with @done (covering both @done and @done.{state}) and @fail.
        // @always needs no exclusion because enumerateTransitions skips any transition
        // whose isAlways flag is set, and @timeout is followed as an ordinary transition
        // since handleParallel does not follow onTimeoutTransition — that belongs to
        // enumerateMachineInvoke, and a parallel state is not an invoke state.
        //
        // Before this, a transition declared on a parallel state was never followed at
        // machine level at all, so those paths were absent from the output while
        // ScenarioPathResolver did follow them: the two analysers disagreed about the
        // same definition.
        $ownTransitions = [];

        foreach ($this->graph->transitionsFrom($state) as $event => $transition) {
            if (str_starts_with($event, '@done')) {
                continue;
            }

            if ($event === '@fail') {
                continue;
            }

            $ownTransitions[$event] = $transition;
        }

        if ($ownTransitions !== []) {
            $enumerated = $this->enumerateTransitions(
                state: $state,
                steps: $steps,
                visitedIds: $visitedIds,
                transitions: $ownTransitions,
                ownsOutcome: false,
            ) || $enumerated;
        }

        // Dead-end parallel: no @done, no @fail and no transitions of its own. The test
        // stays structural, as it was before, so a parallel whose only outcome is a
        // targetless @done still records nothing rather than newly claiming a dead end.
        // The deferred branch can only be reached under a boundary.
        $hasOutcome = $state->onDoneTransition instanceof TransitionDefinition
            || $state->onFailTransition instanceof TransitionDefinition
            || $ownTransitions !== [];

        if (!$hasOutcome) {
            $this->recordPath($steps, PathType::DEAD_END);
        } elseif (!$enumerated && $deferred) {
            $this->recordPath($steps, PathType::REGION_DEFERRED);
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

        $enumerated = false;
        $deferred   = false;

        // Enumerate @always guard-pass forks
        foreach ($alwaysTransition->branches ?? [] as $index => $branch) {
            if (!$branch->target instanceof StateDefinition) {
                continue;
            }

            if ($this->leavesBoundary($branch->target)) {
                $recorded = $this->recordBoundarySkip(
                    steps: $steps,
                    target: $branch->target,
                    source: $alwaysTransition->source,
                    event: '@always',
                    guards: $branch->guards ?? [],
                    actions: $branch->actions ?? [],
                );

                $enumerated = $enumerated || $recorded;
                $deferred   = $deferred || !$recorded;

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
            $enumerated = true;
        }

        // If @always has an unguarded fallback: it always fires — remaining transitions unreachable
        if ($hasUnguardedFallback) {
            // This path returns without recording, which is right when a branch was
            // followed. Under a boundary it can now leave the state with no outcome
            // at all, and a region @always is runtime-reachable (transitionParallelState
            // runs its own ungated @always sweep), so silence here would breach E4.
            if (!$enumerated && $deferred) {
                $this->recordPath($steps, PathType::REGION_DEFERRED);
            }

            return;
        }

        // Guarded @always: guard-fail continuation — enumerate remaining transitions
        if ($remainingTransitions !== [] || $state->hasMachineInvoke()) {
            $this->enumerateTransitions($state, $steps, $visitedIds, $remainingTransitions);

            return;
        }

        // No remaining transitions and guard failed → GUARD_BLOCK
        $this->recordPath($steps, PathType::GUARD_BLOCK);

        // Every @always branch belonged to machine level: the guard block records the
        // guard-fail outcome, and this records that the branches themselves are owned
        // above the boundary (E6). They are different claims about the same state.
        if (!$enumerated && $deferred) {
            $this->recordPath($steps, PathType::REGION_DEFERRED);
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
        bool $ownsOutcome = true,
    ): bool {
        $enumerated = false;
        $deferred   = false;

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

                if ($this->leavesBoundary($branch->target)) {
                    $recorded = $this->recordBoundarySkip(
                        steps: $steps,
                        target: $branch->target,
                        source: $transition->source,
                        event: $event,
                        guards: $branch->guards ?? [],
                        actions: $branch->actions ?? [],
                        timerType: $timerType,
                    );

                    $enumerated = $enumerated || $recorded;
                    $deferred   = $deferred || !$recorded;

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

        // If nothing was enumerated (e.g., only @always transitions exist but we skip them).
        // Under a boundary, "nothing enumerated but something was deferred" is E6: every
        // continuation belongs to machine level, which is not a dead end — the runtime can
        // leave this state. $deferred is only ever true when a boundary is set, so the
        // no-boundary path records exactly what it recorded before.
        //
        // $ownsOutcome is false when handleParallel calls this: a parallel state may have
        // already enumerated @done/@fail branches this function cannot see, so it owns the
        // single outcome decision and only wants to know whether anything was enumerated.
        if (!$enumerated && $ownsOutcome) {
            $this->recordPath($steps, $deferred ? PathType::REGION_DEFERRED : PathType::DEAD_END);
        }

        return $enumerated;
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
                if ($this->leavesBoundary($targetState)) {
                    if (!$this->recordBoundarySkip(
                        steps: $steps,
                        target: $targetState,
                        source: $state,
                        event: 'fire-and-forget',
                        invokeType: 'fire-and-forget',
                    )) {
                        $this->recordPath($steps, PathType::REGION_DEFERRED);
                    }

                    return;
                }

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

        $enumerated      = false;
        $deferralsBefore = $this->deferrals;

        // @done.{state} transitions — per-final-state routing
        foreach ($state->onDoneStateTransitions as $finalStateName => $transition) {
            $enumerated = $this->followInvokeTransition($transition, $steps, $visitedIds, "@done.{$finalStateName}", $state) || $enumerated;
        }

        // @done catch-all transition
        if ($state->onDoneTransition instanceof TransitionDefinition) {
            $enumerated = $this->followInvokeTransition($state->onDoneTransition, $steps, $visitedIds, '@done', $state) || $enumerated;
        }

        // @fail transition
        if ($state->onFailTransition instanceof TransitionDefinition) {
            $enumerated = $this->followInvokeTransition($state->onFailTransition, $steps, $visitedIds, '@fail', $state) || $enumerated;
        }

        // @timeout transition
        if ($state->onTimeoutTransition instanceof TransitionDefinition) {
            $enumerated = $this->followInvokeTransition($state->onTimeoutTransition, $steps, $visitedIds, '@timeout', $state) || $enumerated;
        }

        // Every invoke outcome belonged to machine level, so the region records that
        // rather than nothing (E6). The deferral counter is what distinguishes this
        // from a state that simply has no invoke outcomes, which must keep recording
        // nothing exactly as it did before.
        if (!$enumerated && $this->deferrals > $deferralsBefore) {
            $this->recordPath($steps, PathType::REGION_DEFERRED);
        }
    }

    /**
     * Follow an invoke transition (@done/@fail/@timeout) with all its branches.
     *
     * @param  list<PathStep>  $steps
     * @param  array<string, true>  $visitedIds
     * @param  StateDefinition  $source  The state that owns the invoke, used to
     *                                   classify a boundary skip.
     *
     * @return bool True when this call produced an outcome — a followed branch or
     *              a recorded region exit. False means every branch was either
     *              targetless or handed to machine-level enumeration.
     */
    private function followInvokeTransition(
        TransitionDefinition $transition,
        array $steps,
        array $visitedIds,
        string $invokeEvent,
        StateDefinition $source,
    ): bool {
        $enumerated = false;

        foreach ($transition->branches ?? [] as $index => $branch) {
            if (!$branch->target instanceof StateDefinition) {
                continue;
            }

            if ($this->leavesBoundary($branch->target)) {
                $recorded = $this->recordBoundarySkip(
                    steps: $steps,
                    target: $branch->target,
                    source: $source,
                    event: $invokeEvent,
                    guards: $branch->guards ?? [],
                    actions: $branch->actions ?? [],
                    invokeType: $invokeEvent,
                );

                $enumerated = $enumerated || $recorded;

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
            $enumerated = true;
        }

        return $enumerated;
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

        // For non-terminal types, clear the terminal state. None of these reached
        // a terminal point: LOOP returns to a visited state, GUARD_BLOCK stays put,
        // TRUNCATED was cut at the ceiling, and the two region outcomes end at a
        // boundary rather than at a final state.
        if (in_array($type, [
            PathType::LOOP,
            PathType::GUARD_BLOCK,
            PathType::TRUNCATED,
            PathType::REGION_EXIT,
            PathType::REGION_DEFERRED,
        ], true)) {
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
        // LOOP, GUARD_BLOCK, TRUNCATED, REGION_EXIT and REGION_DEFERRED are set
        // directly during DFS and are never returned from here: each records a
        // decision the classifier cannot re-derive from the steps alone.
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
