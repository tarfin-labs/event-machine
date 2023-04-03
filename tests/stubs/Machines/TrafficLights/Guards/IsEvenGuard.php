<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class IsEvenGuard implements GuardBehavior
{
    public function __invoke(ContextManager $context, array $event): bool
    {
        return $context->get('count') % 2 === 0;
    }
}
