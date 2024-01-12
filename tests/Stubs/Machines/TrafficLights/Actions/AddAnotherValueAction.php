<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\AddAnotherValueEvent;

class AddAnotherValueAction extends ActionBehavior
{
    public function __invoke(
        TrafficLightsContext $context,
        AddAnotherValueEvent $eventBehavior
    ): void {
        $context->count += $eventBehavior->value;
    }
}
