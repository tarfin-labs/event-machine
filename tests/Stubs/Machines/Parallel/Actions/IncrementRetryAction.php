<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class IncrementRetryAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('retryCount', $context->get('retryCount') + 1);
    }
}
