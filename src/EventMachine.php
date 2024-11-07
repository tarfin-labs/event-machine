<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

use Tarfinlabs\EventMachine\Behavior\InvokableBehavior;

class EventMachine
{
    /**
     * Resets all fake invocations to their default state.
     */
    public function resetAllFakes(): void
    {
        InvokableBehavior::resetAllFakes();
    }
}
