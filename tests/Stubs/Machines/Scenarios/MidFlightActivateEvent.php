<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Scenarios;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class MidFlightActivateEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'ACTIVATE';
    }
}
