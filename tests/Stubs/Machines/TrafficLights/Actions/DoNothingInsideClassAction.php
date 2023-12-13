<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class DoNothingInsideClassAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $eventBehavior, ?array $arguments = null): void
    {
        // Do nothing.
    }
}
