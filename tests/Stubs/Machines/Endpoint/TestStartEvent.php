<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class TestStartEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'START';
    }
}
