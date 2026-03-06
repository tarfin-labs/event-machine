<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\EventProcessingOrder\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class TransitionActionWithRaise extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $order   = $context->get('execution_order');
        $order[] = 'transition_action';
        $context->set('execution_order', $order);
        $this->raise(['type' => 'NEXT']);
    }
}
