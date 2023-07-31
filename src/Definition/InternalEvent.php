<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Definition;

use Illuminate\Support\Str;

/**
 * Class InternalEvent.
 *
 * An enum class representing internal event names.
 */
enum InternalEvent: string
{
    case MACHINE_START  = '{machine}.start';
    case MACHINE_FINISH = '{machine}.finish';

    case STATE_ENTER        = '{machine}.state.{placeholder}.enter';
    case STATE_ENTRY_START  = '{machine}.state.{placeholder}.entry.start';
    case STATE_ENTRY_FINISH = '{machine}.state.{placeholder}.entry.finish';
    case STATE_EXIT_START   = '{machine}.state.{placeholder}.exit.start';
    case STATE_EXIT_FINISH  = '{machine}.state.{placeholder}.exit.finish';
    case STATE_EXIT         = '{machine}.state.{placeholder}.exit';

    case TRANSITION_START  = '{machine}.transition.{placeholder}.start';
    case TRANSITION_FINISH = '{machine}.transition.{placeholder}.finish';
    case TRANSITION_FAIL   = '{machine}.transition.{placeholder}.fail';

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
        if ($placeholder !== null && class_exists($placeholder)) {
            $placeholder = Str::of($placeholder)->classBasename();
        }

        return Str::swap([
            '{machine}'     => $machineId,
            '{placeholder}' => $placeholder,
        ], $this->value);
    }
}
