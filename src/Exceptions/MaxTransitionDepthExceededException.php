<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Exceptions;

use LogicException;

/**
 * Thrown when the machine exceeds the maximum allowed recursive transition depth
 * within a single macrostep (e.g., @always transitions or raised events creating
 * an infinite loop).
 */
class MaxTransitionDepthExceededException extends LogicException
{
    /**
     * Creates an instance indicating the recursive transition depth limit was exceeded.
     *
     * @param  int  $limit  The maximum allowed depth.
     * @param  string  $route  The state route where the limit was hit.
     */
    public static function exceeded(int $limit, string $route): self
    {
        return new self(
            message: "Maximum transition depth of {$limit} exceeded at '{$route}'. ".
                'This indicates an infinite loop in your state machine configuration, '.
                'caused by @always transitions or raised events cycling without termination.'
        );
    }
}
