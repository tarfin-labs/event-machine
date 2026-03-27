<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\GetEndpoint;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class GetForwardStartEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'START';
    }
}
