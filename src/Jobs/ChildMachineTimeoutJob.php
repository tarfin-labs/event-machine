<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\TransitionDefinition;

/**
 * Delayed check job that detects timed-out child machines.
 *
 * Dispatched alongside ChildMachineJob with a configurable delay
 * (from @timeout config). When the delay expires, checks whether
 * the child has completed. If still running, marks it as timed_out
 * and fires the parent's @timeout transition.
 */
class ChildMachineTimeoutJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $parentRootEventId  The parent machine's root_event_id.
     * @param  string  $parentMachineClass  FQCN of the parent machine.
     * @param  string  $parentStateId  The parent state that invoked the child.
     * @param  string  $machineChildId  The MachineChild tracking record ID.
     * @param  string  $childMachineClass  FQCN of the child machine.
     * @param  int  $timeoutSeconds  The configured timeout duration.
     */
    public function __construct(
        public readonly string $parentRootEventId,
        public readonly string $parentMachineClass,
        public readonly string $parentStateId,
        public readonly string $machineChildId,
        public readonly string $childMachineClass,
        public readonly int $timeoutSeconds,
    ) {}

    public function handle(): void
    {
        // 1. Check if child is still active (no-op if already completed)
        $childRecord = MachineChild::find($this->machineChildId);
        if ($childRecord === null || $childRecord->isTerminal()) {
            return;
        }

        // 2. Restore parent machine
        try {
            /** @var Machine $parentMachine */
            $parentMachine = $this->parentMachineClass::create(state: $this->parentRootEventId);
        } catch (\Throwable) {
            return;
        }

        // 3. Pre-lock guard: is parent still in the invoking state?
        if ($parentMachine->state->currentStateDefinition->id !== $this->parentStateId) {
            return;
        }

        // 4. Acquire lock for state mutation
        $lockHandle = MachineLockManager::acquire(
            rootEventId: $this->parentRootEventId,
            timeout: 30,
            ttl: 60,
            context: 'child_machine_timeout',
        );

        try {
            // 5. Re-check under lock (double-guard pattern)
            $freshChild = MachineChild::find($this->machineChildId);
            if ($freshChild === null || $freshChild->isTerminal()) {
                return;
            }

            /** @var Machine $freshParent */
            $freshParent = $this->parentMachineClass::create(state: $this->parentRootEventId);

            if ($freshParent->state->currentStateDefinition->id !== $this->parentStateId) {
                return;
            }

            $stateDefinition = $freshParent->state->currentStateDefinition;

            // 6. Check for @timeout transition on the parent state
            if (!$stateDefinition->onTimeoutTransition instanceof TransitionDefinition) {
                return;
            }

            DB::transaction(function () use ($freshParent, $freshChild, $stateDefinition): void {
                // 7. Mark child as timed out
                $freshChild->markTimedOut();

                // 8. Record timeout event
                $freshParent->state->setInternalEventBehavior(
                    type: InternalEvent::CHILD_MACHINE_TIMEOUT,
                    placeholder: $this->childMachineClass,
                );

                // 9. Route @timeout transition on parent
                $timeoutEvent = new EventDefinition(
                    type: InternalEvent::CHILD_MACHINE_TIMEOUT->value,
                    payload: [
                        'machine_child_id' => $this->machineChildId,
                        'child_class'      => $this->childMachineClass,
                        'timeout_seconds'  => $this->timeoutSeconds,
                    ],
                );

                $freshParent->definition->routeChildTimeoutEvent(
                    $freshParent->state,
                    $stateDefinition,
                    $timeoutEvent,
                );

                // 10. Persist parent state
                $freshParent->persist();
            });
        } finally {
            $lockHandle?->release();
        }
    }
}
