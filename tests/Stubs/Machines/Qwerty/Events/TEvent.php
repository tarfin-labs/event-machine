<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class TEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'T_EVENT';
    }
}
