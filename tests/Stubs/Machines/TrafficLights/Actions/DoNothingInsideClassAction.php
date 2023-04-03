<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

class DoNothingInsideClassAction implements ActionBehavior
{
    public function __invoke(ContextManager $context, EventDefinition $eventDefinition): void
    {
        // Do nothing.
    }
}
