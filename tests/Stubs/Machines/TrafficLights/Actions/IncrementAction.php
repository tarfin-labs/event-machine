<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;

class IncrementAction extends ActionBehavior
{
    public function __invoke(TrafficLightsContext $context): void
    {
        $context->count++;
    }
}
