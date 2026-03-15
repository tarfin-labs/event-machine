<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Enums;

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

    case GUARD_PASS = '{machine}.guard.{placeholder}.pass';
    case GUARD_FAIL = '{machine}.guard.{placeholder}.fail';

    case CALCULATOR_PASS = '{machine}.calculator.{placeholder}.pass';
    case CALCULATOR_FAIL = '{machine}.calculator.{placeholder}.fail';

    case EVENT_RAISED = '{machine}.event.{placeholder}.raised';

    case PARALLEL_REGION_ENTER       = '{machine}.parallel.{placeholder}.region.enter';
    case PARALLEL_REGION_EXIT        = '{machine}.parallel.{placeholder}.region.exit';
    case PARALLEL_REGION_GUARD_ABORT = '{machine}.parallel.{placeholder}.region.guard_abort';
    case PARALLEL_CONTEXT_CONFLICT   = '{machine}.parallel.{placeholder}.context.conflict';
    case PARALLEL_REGION_STALLED     = '{machine}.parallel.{placeholder}.region.stalled';
    case PARALLEL_REGION_TIMEOUT     = '{machine}.parallel.{placeholder}.region.timeout';
    case PARALLEL_DONE               = '{machine}.parallel.{placeholder}.done';
    case PARALLEL_FAIL               = '{machine}.parallel.{placeholder}.fail';

    case CHILD_MACHINE_START     = '{machine}.child.{placeholder}.start';
    case CHILD_MACHINE_DONE      = '{machine}.child.{placeholder}.done';
    case CHILD_MACHINE_FAIL      = '{machine}.child.{placeholder}.fail';
    case CHILD_MACHINE_TIMEOUT   = '{machine}.child.{placeholder}.timeout';
    case CHILD_MACHINE_CANCELLED = '{machine}.child.{placeholder}.cancelled';

    /**
     * Generate an internal event name based on the machine ID and an optional placeholder.
     *
     * @param  string  $machineId  The ID of the machine.
     * @param  string|null  $placeholder  An optional placeholder.
     *
     * @return string The generated internal event name.
     */
    public function generateInternalEventName(string $machineId, ?string $placeholder = null): string
    {
        if ($placeholder !== null && class_exists($placeholder)) {
            $placeholder = Str::of($placeholder)->classBasename()->toString();
        }

        return Str::swap([
            '{machine}'     => $machineId,
            '{placeholder}' => $placeholder,
        ], $this->value);
    }
}
