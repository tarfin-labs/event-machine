<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\Events;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class REvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'R_EVENT';
    }

    public function actor(ContextManager $context): string
    {
        return 'R Actor';
    }
}
