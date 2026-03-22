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
            StateDefinitionType::ATOMIC   => $this->handleAtomic($state, $steps),
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
     * ATOMIC state: collect transitions, enumerate paths.
     * Placeholder — will be implemented in subsequent tasks.
     *
     * @param  list<PathStep>  $steps
     */
    private function handleAtomic(StateDefinition $state, array $steps): void
    {
        $steps[] = new PathStep(stateId: $state->id, stateKey: $state->key ?? '');

        // Dead-end: no transitions, not FINAL
        $hasTransitions   = $state->transitionDefinitions !== null && $state->transitionDefinitions !== [];
        $hasMachineInvoke = $state->hasMachineInvoke();

        if (!$hasTransitions && !$hasMachineInvoke) {
            $this->recordPath($steps, PathType::DEAD_END);

            return;
        }

        // Transition enumeration will be implemented in subsequent tasks.
        // For now, record as dead-end if we have no handler for the transitions.
        $this->recordPath($steps, PathType::DEAD_END);
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
