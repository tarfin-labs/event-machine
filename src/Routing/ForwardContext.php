<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Routing;

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;

/**
 * Type-safe wrapper that carries child machine state for forward endpoint injection.
 *
 * Type-hint this in an OutputBehavior's __invoke() to access the child's
 * context and state when processing a forwarded endpoint response.
 * Only available in forward endpoint context — not injected in regular endpoints.
 */
class ForwardContext
{
    public function __construct(
        public readonly ContextManager $childContext,
        public readonly State $childState,
    ) {}
}
