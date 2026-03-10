<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class TestCancelEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'CANCEL';
    }
}
