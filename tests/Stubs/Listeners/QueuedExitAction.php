<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Listeners;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Queued exit listener action that sets a context flag.
 */
class QueuedExitAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('exitListenerRan', true);
    }
}
