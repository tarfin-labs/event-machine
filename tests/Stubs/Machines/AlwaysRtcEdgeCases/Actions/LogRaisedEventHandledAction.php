<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysRtcEdgeCases\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class LogRaisedEventHandledAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $log   = $context->get('executionOrder');
        $log[] = 'raised_event_handled';
        $context->set('executionOrder', $log);
    }
}
