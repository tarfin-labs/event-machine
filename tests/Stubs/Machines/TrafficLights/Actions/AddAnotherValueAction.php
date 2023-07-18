<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;

class AddAnotherValueAction extends ActionBehavior
{
    public function __invoke(ContextManager|TrafficLightsContext $context, EventBehavior $eventBehavior, array $arguments = null): void
    {
        $context->count += $eventBehavior->value;
    }
}
