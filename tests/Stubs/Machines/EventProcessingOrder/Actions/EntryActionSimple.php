<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\EventProcessingOrder\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class EntryActionSimple extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $order   = $context->get('executionOrder');
        $order[] = 'B_entry';
        $context->set('executionOrder', $order);
    }
}
