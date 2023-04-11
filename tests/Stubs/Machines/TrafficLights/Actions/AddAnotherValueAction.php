<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class AddAnotherValueAction implements ActionBehavior
{
    /* @var \Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext $context */
    /* @var \Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\AddAnotherValueEvent $eventBehavior */

    public function __invoke(ContextManager $context, EventBehavior $eventBehavior): void
    {
        $context->count += $eventBehavior->value;
    }
}
