<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\RaisedEventTiebreaker\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class RaiseTwoEventsAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $trace   = $context->get('trace');
        $trace[] = 'A_entry_raise_EVENT_1';
        $context->set('trace', $trace);
        $this->raise(['type' => 'EVENT_1']);

        $trace   = $context->get('trace');
        $trace[] = 'A_entry_raise_EVENT_2';
        $context->set('trace', $trace);
        $this->raise(['type' => 'EVENT_2']);
    }
}
