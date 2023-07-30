<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Illuminate\Support\Str;

enum InternalEvent: string
{
    case MACHINE_START = '{machine}.start';

    case STATE_ENTER = '{machine}.state.{?}.enter';
    case STATE_EXIT  = '{machine}.state.{?}.exit';

    case ACTION_START  = '{machine}.action.{?}.start';
    case ACTION_FINISH = '{machine}.action.{?}.finish';

    case GUARD_START = '{machine}.guard.{?}.start';
    case GUARD_PASS  = '{machine}.guard.{?}.pass';
    case GUARD_FAIL  = '{machine}.guard.{?}.fail';

    case EVENT_RAISED = '{machine}.event.{?}.raised';

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
            '{machine}' => $machineId,
            '{?}'       => Str::of($placeholder)->classBasename()->camel(),
        ], $this->value);
    }
}
