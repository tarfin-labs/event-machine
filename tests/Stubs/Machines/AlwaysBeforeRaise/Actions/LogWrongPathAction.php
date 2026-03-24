<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysBeforeRaise\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class LogWrongPathAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $trace   = $context->get('trace');
        $trace[] = 'WRONG_PATH';
        $context->set('trace', $trace);
    }
}
