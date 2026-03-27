<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Writes to shared context keys (same keys as Region B) to test merge conflict.
 * Scalar: shared_scalar = 'value_from_a'.
 * Array: shared_array = ['from_a' => true, 'score' => 85].
 */
class ProcessSharedKeyAAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('sharedScalar', 'value_from_a');
        $context->set('sharedArray', ['from_a' => true, 'score' => 85]);
        $context->set('regionAWrote', true);
        $this->raise(['type' => 'REGION_A_PROCESSED']);
    }
}
