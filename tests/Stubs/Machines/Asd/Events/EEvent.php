<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class EEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'E_EVENT';
    }
}
