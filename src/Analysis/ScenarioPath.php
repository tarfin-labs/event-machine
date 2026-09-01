<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

/**
 * A resolved path from source to target through a machine definition.
 * Contains classified steps for scaffold generation.
 */
readonly class ScenarioPath
{
    /**
     * Sum of StateClassification::weight() over every step.
     *
     * Derived here rather than accepted as an argument, so it cannot be constructed
     * inconsistently and every existing call site keeps working unchanged. The source
     * state is not a step, so it is never priced: a one-step path weighs exactly what
     * its single target weighs.
     */
    public int $totalWeight;

    /**
     * @param  list<ScenarioPathStep>  $steps  Ordered steps from source to target.
     */
    public function __construct(
        public array $steps,
    ) {
        $totalWeight = 0;

        foreach ($steps as $step) {
            $totalWeight += $step->classification->weight();
        }

        $this->totalWeight = $totalWeight;
    }

    /**
     * Human-readable signature: "source→[event]→state→[event]→target".
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

        return implode('→', $parts);
    }

    /**
     * Summary stats for display.
     *
     * @return array{overrides: int, outcomes: int, continues: int}
     */
    public function stats(): array
    {
        $overrides = 0;
        $outcomes  = 0;
        $continues = 0;

        foreach ($this->steps as $step) {
            match ($step->classification) {
                StateClassification::TRANSIENT   => $overrides++,
                StateClassification::DELEGATION  => $outcomes++,
                StateClassification::PARALLEL    => $outcomes++,
                StateClassification::INTERACTIVE => $continues++,
                default                          => null,
            };
        }

        return ['overrides' => $overrides, 'outcomes' => $outcomes, 'continues' => $continues];
    }
}
