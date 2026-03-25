<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Listeners;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Queued transition listener action that sets a context flag.
 */
class QueuedTransitionAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('transition_listener_ran', true);
    }
}
