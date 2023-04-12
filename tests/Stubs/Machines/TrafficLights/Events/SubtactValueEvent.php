<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class SubtactValueEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'SUB_VALUE';
    }
}
