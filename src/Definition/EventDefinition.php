<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class EventDefinition extends EventBehavior
{
    public static function getType(): string
    {
        return '(event)';
    }
}
