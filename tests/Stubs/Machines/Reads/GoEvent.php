<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Reads;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class GoEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'GO';
    }
}
