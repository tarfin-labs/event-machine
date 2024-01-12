<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;

class MultiplyByTwoAction extends ActionBehavior
{
    public bool $shouldLog = true;

    public function __invoke(TrafficLightsContext $context): void
    {
        $context->count *= 2;
    }
}
