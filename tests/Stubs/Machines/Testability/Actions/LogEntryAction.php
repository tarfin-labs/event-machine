<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Testability\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class LogEntryAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('entered', true);
    }
}
