<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

/**
 * Per-region path enumeration for a parallel state.
 *
 * Stores paths within each region separately (not Cartesian product)
 * and provides a combination count for summary display.
 */
readonly class ParallelPathGroup
{
    /**
     * @param  string  $parallelStateId  The parallel state's definition ID.
     * @param  array<string, list<MachinePath>>  $regionPaths  Paths keyed by region key.
     */
    public function __construct(
        public string $parallelStateId,
        public array $regionPaths,
    ) {}

    /**
     * Product of per-region path counts.
     *
     * Represents the theoretical number of combined path permutations
     * across all regions without enumerating them individually.
     */
    public function combinationCount(): int
    {
        if ($this->regionPaths === []) {
            return 0;
        }

        $product = 1;

        foreach ($this->regionPaths as $paths) {
            $product *= max(1, count($paths));
        }

        return $product;
    }
}
