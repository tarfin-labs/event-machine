<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class SleepAction extends ActionBehavior
{
    /**
     * @throws \Exception
     */
    public function __invoke(ContextManager $context, EventBehavior $eventBehavior, ?array $arguments = null): void
    {
        sleep(1);
    }
}
