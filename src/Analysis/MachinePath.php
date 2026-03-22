<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

/**
 * An enumerated path through a state machine.
 *
 * Represents an ordered sequence of PathSteps from the initial state
 * to a terminal point (FINAL, cycle, guard block, or dead end).
 * Used for both static analysis display and coverage matching.
 */
readonly class MachinePath
{
    /**
     * @param  list<PathStep>  $steps  Ordered steps from initial to terminal state.
     * @param  PathType  $type  Classification of this path.
     * @param  ?string  $terminalStateId  Final state ID, or null for LOOP/GUARD_BLOCK.
     */
    public function __construct(
        public array $steps,
        public PathType $type,
        public ?string $terminalStateId = null,
    ) {}

    /**
     * Deterministic signature for coverage matching.
     *
     * Format: "stateKey→[event]→stateKey→[event]→stateKey"
     * Examples:
     *   "idle→[@always]→done"
     *   "idle→[START]→processing→[@done]→completed"
     *   "idle→[GO]→stays" (GUARD_BLOCK)
     */
    public function signature(): string
    {
        $parts = [];

        foreach ($this->steps as $step) {
            if ($step->event !== null) {
                $parts[] = "[{$step->event}]";
            }

            $parts[] = $step->stateKey;
        }

        if ($this->type === PathType::GUARD_BLOCK) {
            $parts[] = 'stays';
        }

        return implode('→', $parts);
    }

    /**
     * @return list<string> State IDs in path order.
     */
    public function stateIds(): array
    {
        return array_map(
            static fn (PathStep $step): string => $step->stateId,
            $this->steps,
        );
    }

    /**
     * @return list<string> Unique guard names across all steps.
     */
    public function guardNames(): array
    {
        return array_values(array_unique(
            array_merge(...array_map(
                static fn (PathStep $step): array => $step->guards,
                $this->steps,
            )),
        ));
    }

    /**
     * @return list<string> Unique action names across all steps.
     */
    public function actionNames(): array
    {
        return array_values(array_unique(
            array_merge(...array_map(
                static fn (PathStep $step): array => $step->actions,
                $this->steps,
            )),
        ));
    }
}
