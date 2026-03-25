<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AsyncDelegationSemantics\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Second entry action that just logs to trace.
 * Should run BEFORE the raised event from the first entry action is processed.
 */
class LogSecondEntryAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $trace   = $context->get('trace');
        $trace[] = 'entry2_log';
        $context->set('trace', $trace);
    }
}
