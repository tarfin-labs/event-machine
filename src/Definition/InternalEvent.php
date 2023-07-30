<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Illuminate\Support\Str;

enum InternalEvent: string
{
    case MACHINE_START = '{machine}.start';

    case STATE_ENTER       = '{machine}.state.{placeholder}.enter';
    case STATE_EXIT_START  = '{machine}.state.{placeholder}.exit.start';
    case STATE_EXIT_FINISH = '{machine}.state.{placeholder}.exit.finish';
    case STATE_EXIT        = '{machine}.state.{placeholder}.exit';

    case ACTION_START  = '{machine}.action.{placeholder}.start';
    case ACTION_FINISH = '{machine}.action.{placeholder}.finish';

    case GUARD_START = '{machine}.guard.{placeholder}.start';
    case GUARD_PASS  = '{machine}.guard.{placeholder}.pass';
    case GUARD_FAIL  = '{machine}.guard.{placeholder}.fail';

    case EVENT_RAISED = '{machine}.event.{placeholder}.raised';

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
            '{machine}'     => $machineId,
            '{placeholder}' => Str::of($placeholder)->classBasename()->camel(),
        ], $this->value);
    }
}
