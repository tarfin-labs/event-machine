<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Guards;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;

class IsOddGuard extends GuardBehavior
{
    public array $requiredContext = [
        'value' => 'integer',
    ];
    public ?string $errorMessage = 'Count is not odd';

    public function __invoke(
        TrafficLightsContext|ContextManager $context,
        EventBehavior $eventBehavior,
        array $arguments = null
    ): bool {
        return $context->value % 2 === 1;
    }
}
