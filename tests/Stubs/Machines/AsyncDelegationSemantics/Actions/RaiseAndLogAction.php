<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AsyncDelegationSemantics\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Entry action that raises an event AND logs to trace.
 * Used to verify that the raised event is deferred until all entry actions complete.
 */
class RaiseAndLogAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $trace   = $context->get('trace');
        $trace[] = 'entry1_raise';
        $context->set('trace', $trace);

        $this->raise(['type' => 'RAISED_EVENT']);
    }
}
