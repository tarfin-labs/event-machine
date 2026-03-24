<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysBeforeRaise\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class LogEntryBAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $order   = $context->get('execution_order');
        $order[] = 'B_entry';
        $context->set('execution_order', $order);
    }
}
