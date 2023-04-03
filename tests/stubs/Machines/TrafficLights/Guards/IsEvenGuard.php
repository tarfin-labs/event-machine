<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Guards;

use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\Definition\ContextDefinition;

class IsEvenGuard implements GuardBehavior
{
    public function __invoke(ContextDefinition $context, array $event): bool
    {
        return $context->get('count') % 2 === 0;
    }
}
