<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysBeforeRaise\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class LogNotifyFromAAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $order   = $context->get('executionOrder');
        $order[] = 'NOTIFY_handled_in_A';
        $context->set('executionOrder', $order);
    }
}
