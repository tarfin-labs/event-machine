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
     * @param  list<ScenarioPathStep>  $steps  Ordered steps from source to target.
     */
    public function __construct(
        public array $steps,
    ) {}

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
