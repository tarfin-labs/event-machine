<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Illuminate\Support\Str;

enum InternalEvent: string
{
    case MACHINE_START = '{+}.start';

    case STATE_ENTER = '{+}.state.{?}.enter';
    case STATE_EXIT  = '{+}.state.{?}.exit';

    case ACTION_START  = '{+}.action.{?}.start';
    case ACTION_FINISH = '{+}.action.{?}.finish';

    case GUARD_START = '{+}.guard.{?}.start';
    case GUARD_PASS  = '{+}.guard.{?}.pass';
    case GUARD_FAIL  = '{+}.guard.{?}.fail';

    case EVENT_RAISED = '{+}.event.{?}.raised';

    /**
     * Generate an internal event name based on the machine ID and an optional placeholder.
     *
     * @param  string  $machineId The ID of the machine.
     * @param  string|null  $placeholder An optional placeholder.
     *
     * @return string The generated internal event name.
     */
    public function generateInternalEventName(string $machineId, string $placeholder = null): string
    {
        return Str::swap([
            '{+}' => $machineId,
            '{?}' => Str::of($placeholder)->classBasename()->camel(),
        ], $this->value);
    }
}
