<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class LogExitAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('exited', true);
    }
}
