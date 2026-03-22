<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\StateDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Definition\TransitionDefinition;

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

    public function __construct(
        private readonly MachineDefinition $definition,
    ) {}

    /**
     * Enumerate all paths from the initial state.
     */
    public function enumerate(): PathEnumerationResult
    {
        $this->paths          = [];
        $this->parallelGroups = [];

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
        );
    }

    /**
     * DFS with backtracking. PHP arrays are pass-by-value, so $visitedIds
     * is automatically copied on each recursive call — no explicit unset needed.
     *
     * @param  list<PathStep>  $steps  Steps accumulated so far.
     * @param  array<string, true>  $visitedIds  State IDs visited in the current fork.
     */
    private function dfs(StateDefinition $state, array $steps, array $visitedIds): void
    {
        // 1. Cycle detection — very first check
        if (isset($visitedIds[$state->id])) {
            $this->recordPath($steps, PathType::LOOP);

            return;
        }

        $visitedIds[$state->id] = true;

        // 2. Dispatch by state type
        match ($state->type) {
            StateDefinitionType::FINAL    => $this->handleFinal($state, $steps, $visitedIds),
            StateDefinitionType::PARALLEL => $this->handleParallel($state, $steps, $visitedIds),
            StateDefinitionType::COMPOUND => $this->handleCompound($state, $steps, $visitedIds),
            StateDefinitionType::ATOMIC   => $this->handleAtomic($state, $steps, $visitedIds),
        };
    }

    /**
     * FINAL state: check for compound @done continuation, otherwise record as terminal.
     *
     * @param  list<PathStep>  $steps
     * @param  array<string, true>  $visitedIds
     */
    private function handleFinal(StateDefinition $state, array $steps, array $visitedIds): void
    {
        $steps[] = new PathStep(stateId: $state->id, stateKey: $state->key ?? '');

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
                    $branchStep = new PathStep(
                        stateId: $state->id,
                        stateKey: $state->key ?? '',
                        event: '@done',
                        branchIndex: $index,
                        guards: $branch->guards ?? [],
                        actions: $branch->actions ?? [],
                        invokeType: '@done',
                    );

                    // Replace last step (the FINAL state) with the @done step
                    $branchSteps   = $steps;
                    $branchSteps[] = $branchStep;

                    $this->dfs($branch->target, $branchSteps, $visitedIds);
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

        $this->parallelGroups[] = new ParallelPathGroup(
            parallelStateId: $state->id,
            regionPaths: $regionPaths,
        );

        // Add parallel state as a step in the outer path
        $steps[] = new PathStep(stateId: $state->id, stateKey: $state->key ?? '');

        // Follow @done transition
        if ($state->onDoneTransition instanceof TransitionDefinition) {
            foreach ($state->onDoneTransition->branches ?? [] as $index => $branch) {
                if ($branch->target instanceof StateDefinition) {
                    $doneStep = new PathStep(
                        stateId: $state->id,
                        stateKey: $state->key ?? '',
                        event: '@done',
                        branchIndex: $index,
                        guards: $branch->guards ?? [],
                        actions: $branch->actions ?? [],
                        invokeType: '@done',
                    );

                    $branchSteps   = $steps;
                    $branchSteps[] = $doneStep;

                    $this->dfs($branch->target, $branchSteps, $visitedIds);
                }
            }
        }

        // Follow @fail transition
        if ($state->onFailTransition instanceof TransitionDefinition) {
            foreach ($state->onFailTransition->branches ?? [] as $index => $branch) {
                if ($branch->target instanceof StateDefinition) {
                    $failStep = new PathStep(
                        stateId: $state->id,
                        stateKey: $state->key ?? '',
                        event: '@fail',
                        branchIndex: $index,
                        guards: $branch->guards ?? [],
                        actions: $branch->actions ?? [],
                        invokeType: '@fail',
                    );

                    $branchSteps   = $steps;
                    $branchSteps[] = $failStep;

                    $this->dfs($branch->target, $branchSteps, $visitedIds);
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
        $steps[] = new PathStep(stateId: $state->id, stateKey: $state->key ?? '');

        // Step 1: Collect all transitions (own + inherited via parent chain)
        $transitions      = $this->collectAllTransitions($state);
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
     * Collect all transitions for an atomic state, including inherited ones.
     *
     * Walks up the parent chain (mirroring findTransitionDefinition() bubbling)
     * and adds transitions for events not already seen at a lower level.
     *
     * @return array<string, TransitionDefinition> Keyed by event type.
     */
    private function collectAllTransitions(StateDefinition $state): array
    {
        $transitions = [];

        // Start with the state's own transitions
        if ($state->transitionDefinitions !== null) {
            foreach ($state->transitionDefinitions as $event => $transition) {
                $transitions[$event] = $transition;
            }
        }

        // Walk up parent chain — add transitions for events NOT already seen
        $current = $state->parent;

        while ($current instanceof StateDefinition && $current->order !== 0) {
            if ($current->transitionDefinitions !== null) {
                foreach ($current->transitionDefinitions as $event => $transition) {
                    if (!isset($transitions[$event])) {
                        $transitions[$event] = $transition;
                    }
                }
            }

            $current = $current->parent;
        }

        return $transitions;
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
        $isUnguarded = !$this->isAllBranchesGuarded($alwaysTransition)
            && count($alwaysTransition->branches ?? []) === 1
            && ($alwaysTransition->branches[0]->guards === null || $alwaysTransition->branches[0]->guards === []);

        // Enumerate @always guard-pass forks
        foreach ($alwaysTransition->branches ?? [] as $index => $branch) {
            if (!$branch->target instanceof StateDefinition) {
                continue;
            }

            $step = new PathStep(
                stateId: $branch->target->id,
                stateKey: $branch->target->key ?? '',
                event: '@always',
                branchIndex: count($alwaysTransition->branches ?? []) > 1 ? $index : null,
                guards: $branch->guards ?? [],
                actions: $branch->actions ?? [],
            );

            $branchSteps   = $steps;
            $branchSteps[] = $step;

            $this->dfs($branch->target, $branchSteps, $visitedIds);
        }

        // If unguarded @always: exclusive — don't enumerate remaining transitions
        if ($isUnguarded) {
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

                $step = new PathStep(
                    stateId: $branch->target->id,
                    stateKey: $branch->target->key ?? '',
                    event: $event,
                    branchIndex: count($transition->branches ?? []) > 1 ? $index : null,
                    guards: $branch->guards ?? [],
                    actions: $branch->actions ?? [],
                    timerType: $timerType,
                );

                $branchSteps   = $steps;
                $branchSteps[] = $step;

                $this->dfs($branch->target, $branchSteps, $visitedIds);
                $enumerated = true;
            }

            // GUARD_BLOCK: all branches are guarded, no unguarded fallback
            if ($this->isAllBranchesGuarded($transition)) {
                $guardBlockStep = new PathStep(
                    stateId: $state->id,
                    stateKey: $state->key ?? '',
                    event: $event,
                    guards: $this->extractAllGuards($transition),
                );

                $blockSteps   = $steps;
                $blockSteps[] = $guardBlockStep;

                $this->recordPath($blockSteps, PathType::GUARD_BLOCK);
                $enumerated = true;
            }
        }

        // Machine invoke transitions — will be implemented in implement-machine-invoke-enumeration task
        if ($state->hasMachineInvoke()) {
            $this->enumerateMachineInvoke();
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
     * Extract all guard names from all branches of a transition.
     *
     * @return array<string>
     */
    private function extractAllGuards(TransitionDefinition $transition): array
    {
        $guards = [];

        foreach ($transition->branches ?? [] as $branch) {
            foreach ($branch->guards ?? [] as $guard) {
                $guards[] = $guard;
            }
        }

        return array_values(array_unique($guards));
    }

    /**
     * Enumerate machine invoke transitions (@done, @fail, @timeout, @done.{state}).
     * Placeholder — implemented in implement-machine-invoke-enumeration task.
     */
    private function enumerateMachineInvoke(): void
    {
        // Will be implemented in implement-machine-invoke-enumeration task
    }

    /**
     * Record a completed path.
     *
     * @param  list<PathStep>  $steps
     */
    private function recordPath(array $steps, PathType $type): void
    {
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
