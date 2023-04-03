<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class AddValueEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'ADD';
    }
}
