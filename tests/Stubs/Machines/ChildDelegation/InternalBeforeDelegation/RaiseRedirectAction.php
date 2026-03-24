<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\InternalBeforeDelegation;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Entry action that logs to execution_order and raises a REDIRECT internal event.
 *
 * Used to verify that internal events raised during entry are processed
 * before child machine invocation (SCXML invoker-05 macrostep semantics).
 */
class RaiseRedirectAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $order   = $context->get('execution_order');
        $order[] = 'entry:raise_redirect';
        $context->set('execution_order', $order);

        $this->raise(['type' => 'REDIRECT']);
    }
}
