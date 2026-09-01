<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

use Tarfinlabs\EventMachine\Definition\StateDefinition;

/**
 * One entry on the resolver's shared frontier: a partial path and what reaching it cost.
 *
 * This replaces a positional tuple that was declared nowhere and respelled at every site that
 * touched it — built in one shape, retyped in two annotations, read once as a bare $seed[3],
 * and destructured somewhere else again. Four names, written down once.
 */
readonly class FrontierEntry
{
    /**
     * @param  StateDefinition  $state  The state this partial path currently rests at.
     * @param  list<ScenarioPathStep>  $path  Steps taken to reach it, in order.
     * @param  array<string, true>  $visited  State ids already on THIS path. Per path and not
     *                                        global: that is what makes the search an enumeration
     *                                        of simple paths rather than a shortest-path algorithm,
     *                                        and why the same state may appear on two entries.
     * @param  int  $cost  Accumulated weight of $path, and the queue's primary priority key.
     */
    public function __construct(
        public StateDefinition $state,
        public array $path,
        public array $visited,
        public int $cost,
    ) {}
}
