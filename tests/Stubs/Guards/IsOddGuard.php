<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Guards;

use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;

class IsOddGuard extends GuardBehavior
{
    public array $requiredContext = [
        'counts.oddCount' => 'integer',
    ];

    public function __invoke(TrafficLightsContext $context): bool
    {
        return $context->count % 2 === 1;
    }
}
