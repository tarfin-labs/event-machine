<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class PingEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'PING';
    }
}
