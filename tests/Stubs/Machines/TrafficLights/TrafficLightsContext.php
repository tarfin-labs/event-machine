<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights;

use Tarfinlabs\EventMachine\Behavior\ContextBehavior;

class TrafficLightsContext extends ContextBehavior
{
    public static function define(): array
    {
        return [
            'count' => 1,
        ];
    }

    public function isCountEven(): bool
    {
        return $this->get('count') % 2 === 0;
    }
}
