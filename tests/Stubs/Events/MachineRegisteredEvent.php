<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

/**
 * Event class registered by the machine in its behavior registry.
 * Has stricter validation than CallerEvent.
 */
class MachineRegisteredEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'TEST_EVENT';
    }

    public static function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
        ];
    }
}
