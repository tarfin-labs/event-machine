<?php

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Tests\Stubs\Models\ModelA;

class SleepAction  extends ActionBehavior
{
    /**
     * @throws \Exception
     */
    public function __invoke(ContextManager $context, EventBehavior $eventBehavior, array $arguments = null): void
    {
        sleep(1);
    }

}
