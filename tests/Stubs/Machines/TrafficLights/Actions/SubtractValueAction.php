<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class SubtractValueAction implements ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $eventBehavior): void
    {
        /* @var \Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext $context */
        $context->count -= $eventBehavior->payload['value'];
    }
}
