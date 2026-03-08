<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Writes to shared context keys (same keys as Region A) to test merge conflict.
 * Scalar: shared_scalar = 'value_from_b'.
 * Array: shared_array = ['from_b' => true, 'score' => 92].
 */
class WriteSharedKeyBAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('shared_scalar', 'value_from_b');
        $context->set('shared_array', ['from_b' => true, 'score' => 92]);
        $context->set('region_b_wrote', true);
        $this->raise(['type' => 'REGION_B_PROCESSED']);
    }
}
