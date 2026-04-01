<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class ApproveEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'APPROVE';
    }
}
