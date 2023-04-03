<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

class IncrementAction implements ActionBehavior
{
    public function __invoke(ContextManager $context, EventDefinition $eventDefinition): void
    {
        $context->set('count', $context->get('count') + 1);
    }
}
