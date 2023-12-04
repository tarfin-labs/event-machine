<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Guards;

use Closure;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\TrafficLightsContext;

class IsValidatedOddGuard extends ValidationGuardBehavior
{
    public array $requiredContext = [
        'counts.oddCount' => 'integer',
    ];
    public ?string $errorMessage = 'Count is not odd';

    public function definition(): Closure
    {
        return function (TrafficLightsContext $context) {
            return $context->count % 2 === 1;
        };
    }
}
