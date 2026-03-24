<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysBeforeRaise\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class LogNotifyFromBAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $order   = $context->get('execution_order');
        $order[] = 'NOTIFY_handled_in_B';
        $context->set('execution_order', $order);
    }
}
