<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class TargetlessRaiseAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $log   = $context->get('log');
        $log[] = 'raise_action';
        $context->set('log', $log);

        $this->raise(['type' => 'RAISED_PING']);
    }
}
