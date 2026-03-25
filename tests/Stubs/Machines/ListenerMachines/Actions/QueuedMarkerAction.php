<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * A simple queued listener action that writes a marker to context.
 * Used by LocalQA tests to verify queued listeners run on Horizon workers.
 */
class QueuedMarkerAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('queuedListenerRan', true);
        $context->set('queuedListenerRanAt', now()->toISOString());
    }
}
