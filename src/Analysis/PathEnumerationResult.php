<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Result of path enumeration on a machine definition.
 *
 * Contains all enumerated paths, parallel region groups,
 * and provides filtering by path type and stat computation.
 */
readonly class PathEnumerationResult
{
    /**
     * @param  list<MachinePath>  $paths  All enumerated terminal paths.
     * @param  list<ParallelPathGroup>  $parallelGroups  Per-region path groups for parallel states.
     * @param  ?MachineDefinition  $definition  Source definition for stat computation.
     */
    public function __construct(
        public array $paths = [],
        public array $parallelGroups = [],
        public ?MachineDefinition $definition = null,
        public bool $pathLimitReached = false,
        public bool $depthLimitReached = false,
    ) {}

    /**
     * Did the analysis stop early, for either reason?
     *
     * Every surface that presents an enumeration reads this: a partial analysis
     * must never be presentable as a complete one.
     */
    public function analysisTruncated(): bool
    {
        return $this->pathLimitReached || $this->depthLimitReached;
    }

    // region Filters

    /**
     * @return list<MachinePath>
     */
    public function happyPaths(): array
    {
        return $this->filterByType(PathType::HAPPY);
    }

    /**
     * @return list<MachinePath>
     */
    public function failPaths(): array
    {
        return $this->filterByType(PathType::FAIL);
    }

    /**
     * @return list<MachinePath>
     */
    public function timeoutPaths(): array
    {
        return $this->filterByType(PathType::TIMEOUT);
    }

    /**
     * @return list<MachinePath>
     */
    public function loopPaths(): array
    {
        return $this->filterByType(PathType::LOOP);
    }

    /**
     * @return list<MachinePath>
     */
    public function guardBlockPaths(): array
    {
        return $this->filterByType(PathType::GUARD_BLOCK);
    }

    /**
     * @return list<MachinePath>
     */
    public function deadEndPaths(): array
    {
        return $this->filterByType(PathType::DEAD_END);
    }

    /**
     * @return list<MachinePath>
     */
    public function truncatedPaths(): array
    {
        return $this->filterByType(PathType::TRUNCATED);
    }

    // No regionExitPaths()/regionDeferredPaths() filters exist, deliberately. Region
    // paths are collected into ParallelPathGroup::$regionPaths and never enter $paths,
    // which is the only list these filters read — so such methods would return an empty
    // array for every caller. Group region paths by reading $type off the paths in
    // ParallelPathGroup instead.

    // endregion

    // region Stats

    /**
     * @return array{total: int, atomic: int, compound: int, parallel: int, final: int}
     */
    public function stateStats(): array
    {
        $stats = ['total' => 0, 'atomic' => 0, 'compound' => 0, 'parallel' => 0, 'final' => 0];

        foreach ($this->definition->idMap ?? [] as $state) {
            $stats['total']++;
            match ($state->type) {
                StateDefinitionType::ATOMIC   => $stats['atomic']++,
                StateDefinitionType::COMPOUND => $stats['compound']++,
                StateDefinitionType::PARALLEL => $stats['parallel']++,
                StateDefinitionType::FINAL    => $stats['final']++,
            };
        }

        // Subtract root state (it's always COMPOUND but not a real state)
        if ($stats['compound'] > 0) {
            $stats['compound']--;
            $stats['total']--;
        }

        return $stats;
    }

    public function eventCount(): int
    {
        $events = $this->definition?->root?->collectUniqueEvents() ?? [];

        return count($events);
    }

    public function guardCount(): int
    {
        return count($this->collectBehaviorKeys('guards'));
    }

    public function actionCount(): int
    {
        return count($this->collectBehaviorKeys('actions'));
    }

    public function calculatorCount(): int
    {
        return count($this->collectBehaviorKeys('calculators'));
    }

    public function jobActorCount(): int
    {
        $count = 0;

        foreach ($this->definition->idMap ?? [] as $state) {
            if ($state->hasMachineInvoke() && $state->getMachineInvokeDefinition()?->isJob()) {
                $count++;
            }
        }

        return $count;
    }

    public function childMachineCount(): int
    {
        $count = 0;

        foreach ($this->definition->idMap ?? [] as $state) {
            if ($state->hasMachineInvoke() && !$state->getMachineInvokeDefinition()?->isJob()) {
                $count++;
            }
        }

        return $count;
    }

    public function timerCount(): int
    {
        $count = 0;

        foreach ($this->definition->idMap ?? [] as $state) {
            foreach ($state->transitionDefinitions ?? [] as $transition) {
                if ($transition->timerDefinition !== null) {
                    $count++;
                }
            }
        }

        return $count;
    }

    // endregion

    // region Structured Stats

    /**
     * Structured list of child machine delegations.
     *
     * @return list<array{stateKey: string, class: string, async: bool, queue: ?string}>
     */
    public function childMachines(): array
    {
        $result = [];

        foreach ($this->definition->idMap ?? [] as $state) {
            if (!$state->hasMachineInvoke()) {
                continue;
            }

            $def = $state->getMachineInvokeDefinition();
            if ($def === null) {
                continue;
            }
            if ($def->isJob()) {
                continue;
            }
            if ($def->machineClass === '') {
                continue;
            }

            $result[] = [
                'stateKey' => $state->key ?? '',
                'class'    => $def->machineClass,
                'async'    => $def->async,
                'queue'    => $def->queue,
            ];
        }

        return $result;
    }

    /**
     * Structured list of job actor delegations.
     *
     * @return list<array{stateKey: string, class: string, queue: ?string}>
     */
    public function jobActors(): array
    {
        $result = [];

        foreach ($this->definition->idMap ?? [] as $state) {
            if (!$state->hasMachineInvoke()) {
                continue;
            }

            $def = $state->getMachineInvokeDefinition();
            if ($def === null) {
                continue;
            }
            if (!$def->isJob()) {
                continue;
            }

            $result[] = [
                'stateKey' => $state->key ?? '',
                'class'    => $def->jobClass ?? '',
                'queue'    => $def->queue,
            ];
        }

        return $result;
    }

    /**
     * Child machines the analysis could not build, and why.
     *
     * unhandledChildOutcomes() has to skip a child whose definition cannot be built — one
     * unbuildable child must not take down the report for every other state. But skipping it
     * silently makes it indistinguishable from a child whose outcomes are all handled, so a
     * missing @done route hides behind an exception nobody sees. This names them, and the
     * commands print them as a warning: disclosure, not a gate, matching how truncation and
     * unmatched observations are reported.
     *
     * @return list<array{parentStateKey: string, childClass: string, reason: string}>
     */
    public function unanalysableChildren(): array
    {
        $result = [];

        foreach ($this->definition->idMap ?? [] as $state) {
            if (!$state->hasMachineInvoke()) {
                continue;
            }

            $def = $state->getMachineInvokeDefinition();
            if ($def === null) {
                continue;
            }
            if ($def->isJob()) {
                continue;
            }
            if ($def->machineClass === '') {
                continue;
            }
            if ($def->target !== null) {
                continue;
            }

            if (!class_exists($def->machineClass)) {
                $result[] = [
                    'parentStateKey' => $state->key ?? '',
                    'childClass'     => $def->machineClass,
                    'reason'         => 'class not found',
                ];

                continue;
            }

            try {
                $def->machineClass::definition();
            } catch (\Throwable $e) {
                $result[] = [
                    'parentStateKey' => $state->key ?? '',
                    'childClass'     => $def->machineClass,
                    'reason'         => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * Detect child machines whose final states are not fully handled by the parent.
     *
     * Only checks non-fire-and-forget, non-job invoke states where the parent
     * uses @done.{state} routing without a catch-all @done.
     *
     * @return list<array{parentStateKey: string, childClass: string, childFinalStates: list<string>, handledStates: list<string>, unhandled: list<string>}>
     */
    public function unhandledChildOutcomes(): array
    {
        $result = [];

        foreach ($this->definition->idMap ?? [] as $state) {
            if (!$state->hasMachineInvoke()) {
                continue;
            }

            $def = $state->getMachineInvokeDefinition();
            // Skip job actors (no machine final states) and fire-and-forget (no @done expected)
            if ($def === null) {
                continue;
            }
            if ($def->isJob()) {
                continue;
            }
            if ($def->machineClass === '') {
                continue;
            }
            if ($def->target !== null) {
                continue;
            }

            // If parent has catch-all @done, all outcomes are handled
            if ($state->onDoneTransition !== null) {
                continue;
            }

            // Try to get child definition.
            //
            // Building a child definition runs that machine's own config, which can throw
            // for reasons that have nothing to do with the parent: a missing behavior
            // class, a definition that needs runtime config, a half-written stub. This is
            // a warning feature, so one unbuildable child must not take down the whole
            // report for every other state.
            //
            // Skipping it is right; skipping it SILENTLY was not. A child whose definition
            // cannot be built is indistinguishable from one whose outcomes are all handled —
            // caught, then reported as success. unanalysableChildren() names them instead.
            try {
                if (!class_exists($def->machineClass)) {
                    continue;
                }

                $childDef = $def->machineClass::definition();
            } catch (\Throwable) {
                continue;
            }

            // Find child's root-level FINAL state keys
            $childFinalStates = [];

            foreach ($childDef->root->stateDefinitions ?? [] as $childState) {
                if ($childState->type === StateDefinitionType::FINAL) {
                    $childFinalStates[] = $childState->key;
                }
            }

            if ($childFinalStates === []) {
                continue;
            }

            // Compare against parent's @done.{state} routes
            $handledStates = array_keys($state->onDoneStateTransitions);
            $unhandled     = array_values(array_diff($childFinalStates, $handledStates));

            if ($unhandled !== []) {
                $result[] = [
                    'parentStateKey'   => $state->key ?? '',
                    'childClass'       => $def->machineClass,
                    'childFinalStates' => $childFinalStates,
                    'handledStates'    => $handledStates,
                    'unhandled'        => $unhandled,
                ];
            }
        }

        return $result;
    }

    // endregion

    /**
     * @return list<MachinePath>
     */
    private function filterByType(PathType $type): array
    {
        return array_values(array_filter(
            $this->paths,
            static fn (MachinePath $path): bool => $path->type === $type,
        ));
    }

    /**
     * Collect unique behavior keys from the definition's behavior array.
     *
     * @return list<string>
     */
    private function collectBehaviorKeys(string $type): array
    {
        $behaviors = $this->definition?->behavior[$type] ?? [];

        return array_keys($behaviors);
    }
}
