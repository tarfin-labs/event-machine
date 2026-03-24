<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysBeforeRaise\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class RaiseEventAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $trace   = $context->get('trace');
        $trace[] = 'A_entry';
        $context->set('trace', $trace);
        $this->raise(['type' => 'RAISED']);
    }
}
