<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class DoNothingInsideClassAction implements ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $eventBehavior): void
    {
        // Do nothing.
    }
}
