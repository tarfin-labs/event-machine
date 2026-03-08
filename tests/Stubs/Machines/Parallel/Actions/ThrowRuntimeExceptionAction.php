<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use RuntimeException;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Entry action that always throws RuntimeException.
 *
 * Used to test parallel region job failure handling and onFail transitions.
 */
class ThrowRuntimeExceptionAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        throw new RuntimeException('Simulated API failure in region');
    }
}
