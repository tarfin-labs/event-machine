<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class IsEvenGuard implements GuardBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $eventBehavior): bool
    {
        /* @var \Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext $context */
        return $context->count % 2 === 0;
    }
}
