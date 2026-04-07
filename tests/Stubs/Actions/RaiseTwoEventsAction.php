<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class RaiseTwoEventsAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $trace   = $context->get('trace');
        $trace[] = 'entry_raise_both';
        $context->set('trace', $trace);

        // Raise EVENT_A first, then EVENT_B
        $this->raise(['type' => 'EVENT_A']);
        $this->raise(['type' => 'EVENT_B']);
    }
}
