<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;

class IsEvenGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = 'Count is not even';
    public bool $shouldLog       = true;

    public function __invoke(
        ContextManager|TrafficLightsContext $context,
        EventBehavior $eventBehavior,
        array $arguments = null
    ): bool {
        return $context->count % 2 === 0;
    }
}
