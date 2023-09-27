<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Qwerty\Events;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class QEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'Q_EVENT';
    }

    public function actor(ContextManager $context): string
    {
        $count = $context->get('count');

        return match ($count % 2) {
            0 => 'Q Event Odd Actor',
            1 => 'Q Event Even Actor'
        };
    }
}
