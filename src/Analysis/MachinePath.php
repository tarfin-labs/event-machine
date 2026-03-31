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
     * Human-readable signature with events (for display and JSON output).
     *
     * For coverage matching, use stateSignature() instead — runtime event types
     * differ from static analysis (internal events, triggeringEvent preservation).
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
     * State-only signature for coverage matching (ignores events).
     *
     * Format: "idle→processing→completed"
     * Used for matching against tracker observations where event types
     * may differ from static analysis (internal events, triggeringEvent).
     */
    public function stateSignature(): string
    {
        $keys = array_map(
            static fn (PathStep $step): string => $step->stateKey,
            $this->steps,
        );

        if ($this->type === PathType::GUARD_BLOCK) {
            $keys[] = 'stays';
        }

        return implode('→', $keys);
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
        $guards = [];

        foreach ($this->steps as $step) {
            $guards = array_merge($guards, $step->guards);
        }

        return array_values(array_unique($guards));
    }

    /**
     * @return list<string> Unique action names across all steps.
     */
    public function actionNames(): array
    {
        $actions = [];

        foreach ($this->steps as $step) {
            $actions = array_merge($actions, $step->actions);
        }

        return array_values(array_unique($actions));
    }
}
