<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaiseActorPropagation;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Captures actor then raises another event without actor — tests chain propagation.
 */
class RaiseChainAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $context->set('chain_actor_1', $event->actor($context));
        $this->raise(['type' => 'CHAINED_EVENT']);
    }
}
