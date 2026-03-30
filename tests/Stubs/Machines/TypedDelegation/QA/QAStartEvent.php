<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class QAStartEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'START';
    }
}
