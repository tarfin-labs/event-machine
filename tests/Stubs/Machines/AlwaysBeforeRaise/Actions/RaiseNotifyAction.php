<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysBeforeRaise\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class RaiseNotifyAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $order   = $context->get('execution_order');
        $order[] = 'A_entry_raise_NOTIFY';
        $context->set('execution_order', $order);
        $this->raise(['type' => 'NOTIFY']);
    }
}
