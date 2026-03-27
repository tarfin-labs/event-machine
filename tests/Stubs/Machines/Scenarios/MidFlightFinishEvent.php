<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Scenarios;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class MidFlightFinishEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'FINISH';
    }
}
