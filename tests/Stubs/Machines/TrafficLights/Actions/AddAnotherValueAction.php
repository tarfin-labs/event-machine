<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events\AddAnotherValueEvent;

class AddAnotherValueAction extends ActionBehavior
{
    public function __invoke(ContextManager|TrafficLightsContext $context, EventBehavior|AddAnotherValueEvent $eventBehavior, ?array $arguments = null): void
    {
        $context->count += $eventBehavior->value;
    }
}
