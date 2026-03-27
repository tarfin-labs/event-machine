<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysRtcEdgeCases\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class LogAndRaiseOnInitialEntryAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $log   = $context->get('executionOrder');
        $log[] = 'initial_entry';
        $context->set('executionOrder', $log);

        $this->raise(['type' => 'RAISED_FROM_ENTRY']);
    }
}
