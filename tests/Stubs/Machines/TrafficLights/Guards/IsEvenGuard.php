<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;

class IsEvenGuard extends GuardBehavior
{
    public ?string $errorMessage = 'Count is not even';

    public function __invoke(TrafficLightsContext|ContextManager $context, EventBehavior $eventBehavior): bool
    {
        return $context->count % 2 === 0;
    }
}
