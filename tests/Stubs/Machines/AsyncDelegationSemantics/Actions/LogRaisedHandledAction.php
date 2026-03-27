<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AsyncDelegationSemantics\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Transition action that logs when the raised event is handled.
 * Should be the LAST entry in trace (after both entry actions).
 */
class LogRaisedHandledAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $trace   = $context->get('trace');
        $trace[] = 'raised_handled';
        $context->set('trace', $trace);
    }
}
