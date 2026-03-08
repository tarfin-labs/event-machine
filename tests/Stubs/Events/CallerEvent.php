<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

/**
 * Event class used by the caller — same type string as MachineRegisteredEvent
 * but different class (no validation rules). This simulates the bug case
 * where caller bypasses machine's own event validation.
 */
class CallerEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'TEST_EVENT';
    }
}
