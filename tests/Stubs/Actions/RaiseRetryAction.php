<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Test stub action that calls raise() to enqueue a RETRY event.
 */
class RaiseRetryAction extends ActionBehavior
{
    public function __invoke(ContextManager $ctx): void
    {
        $this->raise(['type' => 'RETRY']);
    }
}
