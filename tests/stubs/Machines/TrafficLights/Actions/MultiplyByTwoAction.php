<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\ContextDefinition;

class MultiplyByTwoAction implements ActionBehavior
{
    public function __invoke(ContextDefinition $context, array $event): void
    {
        $context->set('count', $context->get('count') * 2);
    }
}
