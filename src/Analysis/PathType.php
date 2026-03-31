<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Analysis;

/**
 * Classification of an enumerated machine path.
 *
 * Determined by scanning the full path (priority order):
 * LOOP > GUARD_BLOCK > FAIL > TIMEOUT > DEAD_END > HAPPY
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
}
