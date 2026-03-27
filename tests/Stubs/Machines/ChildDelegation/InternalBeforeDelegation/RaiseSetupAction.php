<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\InternalBeforeDelegation;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Entry action that logs to execution_order and raises a SETUP internal event.
 */
class RaiseSetupAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $order   = $context->get('executionOrder');
        $order[] = 'entry:raise_setup';
        $context->set('executionOrder', $order);

        $this->raise(['type' => 'SETUP']);
    }
}
