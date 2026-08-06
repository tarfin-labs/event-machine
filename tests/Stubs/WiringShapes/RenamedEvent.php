<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\WiringShapes;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class RenamedEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'SOMETHING_ELSE';
    }
}
