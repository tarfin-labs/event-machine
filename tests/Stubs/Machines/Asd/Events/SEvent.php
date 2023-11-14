<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class SEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'S_EVENT';
    }
}
