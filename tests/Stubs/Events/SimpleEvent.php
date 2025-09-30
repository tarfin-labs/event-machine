<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class SimpleEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'SIMPLE_EVENT';
    }
}
