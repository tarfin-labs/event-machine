<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

/**
 * Classification of an enumerated machine path.
 *
 * HAPPY, FAIL, TIMEOUT and DEAD_END are derived by scanning a completed path, in the
 * priority order FAIL > TIMEOUT > DEAD_END > HAPPY. Every other case records a decision
 * the scan cannot re-derive from the steps, so it is set directly during DFS: LOOP,
 * GUARD_BLOCK, TRUNCATED, and the two region outcomes.
 *
 * REGION_EXIT and REGION_DEFERRED occur only in a region's own paths, which live in
 * ParallelPathGroup and never enter PathEnumerationResult::$paths.
 */
enum PathType: string
{
    /**
     * Reached a top-level FINAL state without @fail or timer.
     */
    case HAPPY = 'happy';

    /**
     * Path contains an @fail step.
     */
    case FAIL = 'fail';

    /**
     * Path contains a timer-triggered step (after/every) or @timeout.
     */
    case TIMEOUT = 'timeout';

    /**
     * Cycle detected — path revisits a state.
     */
    case LOOP = 'loop';

    /**
     * All guards fail with no fallback — event swallowed, stays in state.
     */
    case GUARD_BLOCK = 'guard_block';

    /**
     * ATOMIC state with no transitions and not FINAL.
     */
    case DEAD_END = 'dead_end';

    /**
     * Enumeration stopped at the depth ceiling — the path was cut short and
     * did not reach a terminal point.
     */
    case TRUNCATED = 'truncated';

    /**
     * A region path that ends by leaving its region: a transition declared
     * inside the region whose target lies outside it. The final step names
     * the escaping event and its target.
     *
     * Region-level only — never appears in PathEnumerationResult::$paths.
     */
    case REGION_EXIT = 'region_exit';

    /**
     * A region state whose only continuations are declared at or above the
     * parallel state, and so are followed by machine-level enumeration rather
     * than by the region. Distinct from DEAD_END: the runtime can leave this
     * state. Distinct from REGION_EXIT: the region does not follow it.
     *
     * Region-level only — never appears in PathEnumerationResult::$paths.
     */
    case REGION_DEFERRED = 'region_deferred';
}
