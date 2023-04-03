<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class DoNothingInsideClassAction implements ActionBehavior
{
    public function __invoke(ContextManager $context, array $event): void
    {
        // Do nothing.
    }
}
