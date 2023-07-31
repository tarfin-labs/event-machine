<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class YEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'Y_EVENT';
    }
}
