<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Behavior\ChildMachineFailEvent;

/**
 * Queue job that restores a parent machine and routes @done/@fail
 * after a child machine completes asynchronously.
 *
 * Idempotent: checks that the parent is still in the invoking state
 * before routing. Handles missing parent gracefully (logs and discards).
 */
class ChildMachineCompletionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries   = 5;
    public int $backoff = 5;

    /**
     * @param  string  $parentRootEventId  The parent machine's root_event_id.
     * @param  string  $parentMachineClass  FQCN of the parent machine.
     * @param  string  $parentStateId  The parent state that invoked the child.
     * @param  string  $childMachineClass  FQCN of the child machine.
     * @param  string|null  $childRootEventId  The child's root_event_id (null on pre-start failure).
     * @param  bool  $success  Whether the child completed successfully.
     * @param  mixed  $result  The child's ResultBehavior output (for @done).
     * @param  array  $childContextData  The child's final context data (for @done).
     * @param  string|null  $errorMessage  Error message (for @fail).
     * @param  int|string|null  $errorCode  Error code from the exception (for @fail).
     * @param  array|null  $outputData  The child's filtered output (from final state `output` key).
     */
    public function __construct(
        public readonly string $parentRootEventId,
        public readonly string $parentMachineClass,
        public readonly string $parentStateId,
        public readonly string $childMachineClass,
        public readonly ?string $childRootEventId = null,
        public readonly bool $success = true,
        public readonly mixed $result = null,
        public readonly array $childContextData = [],
        public readonly ?string $errorMessage = null,
        public readonly int|string|null $errorCode = null,
        public readonly ?array $outputData = null,
        public readonly ?string $childFinalState = null,
    ) {}

    public function handle(): void
    {
        // 1. Restore parent machine from DB
        try {
            /** @var Machine $parentMachine */
            $parentMachine = $this->parentMachineClass::create(state: $this->parentRootEventId);
        } catch (\Throwable $e) {
            logger()->warning('ChildMachineCompletionJob: could not restore parent machine', [
                'parent_root_event_id' => $this->parentRootEventId,
                'parent_machine_class' => $this->parentMachineClass,
                'error'                => $e->getMessage(),
            ]);

            return;
        }

        // 2. Pre-lock idempotency check: is parent still in the invoking state?
        // For parallel states, currentStateDefinition is the parallel ancestor,
        // not the region's atomic state. Check the value array instead.
        if (!in_array($this->parentStateId, $parentMachine->state->value, true)) {
            return;
        }

        // 3. Acquire lock for parent state mutation
        $lockHandle = MachineLockManager::acquire(
            rootEventId: $this->parentRootEventId,
            timeout: 30,
            ttl: 60,
            context: 'child_machine_completion',
        );

        try {
            // 4. Re-check under lock (double-guard pattern)
            /** @var Machine $freshParent */
            $freshParent = $this->parentMachineClass::create(state: $this->parentRootEventId);

            // Re-check under lock: parent state must still contain the invoking state
            if (!in_array($this->parentStateId, $freshParent->state->value, true)) {
                return;
            }

            // Resolve the invoking state definition from idMap (not currentStateDefinition,
            // which for parallel states points to the parallel ancestor, not the region's atomic state)
            $stateDefinition = $freshParent->definition->idMap[$this->parentStateId] ?? null;

            if ($stateDefinition === null) {
                return;
            }

            // Track whether this is a parallel context for value preservation
            $isParallelContext = count($freshParent->state->value) > 1;
            $parallelValues    = $isParallelContext ? $freshParent->state->value : null;
            $parallelCSD       = $isParallelContext ? $freshParent->state->currentStateDefinition : null;

            // 5. Route @done or @fail on the parent
            if ($this->success) {
                $freshParent->state->setInternalEventBehavior(
                    type: InternalEvent::CHILD_MACHINE_DONE,
                    placeholder: $this->childMachineClass,
                );

                $doneEvent = ChildMachineDoneEvent::forChild([
                    'result'        => $this->result,
                    'output'        => $this->outputData,
                    'machine_id'    => $this->childRootEventId ?? '',
                    'machine_class' => $this->childMachineClass,
                    'final_state'   => $this->childFinalState,
                ]);

                $freshParent->definition->routeChildDoneEvent(
                    $freshParent->state,
                    $stateDefinition,
                    $doneEvent,
                );
            } else {
                $freshParent->state->setInternalEventBehavior(
                    type: InternalEvent::CHILD_MACHINE_FAIL,
                    placeholder: $this->childMachineClass,
                );

                $failEvent = ChildMachineFailEvent::forChild([
                    'error_message' => $this->errorMessage ?? 'Unknown error',
                    'error_code'    => $this->errorCode,
                    'machine_id'    => $this->childRootEventId ?? '',
                    'machine_class' => $this->childMachineClass,
                    'output'        => $this->outputData ?? $this->childContextData,
                ]);

                $freshParent->definition->routeChildFailEvent(
                    $freshParent->state,
                    $stateDefinition,
                    $failEvent,
                );
            }

            // 5b. Parallel value preservation: routeChildDoneEvent calls
            // executeChildTransitionBranch → setCurrentStateDefinition, which wipes
            // the parallel value array. Restore it with the region's new state.
            if ($isParallelContext && $parallelValues !== null) {
                $oldRegionState = $this->parentStateId;
                $historyCount   = count($freshParent->state->history);

                if ($freshParent->state->value !== $parallelValues) {
                    $newRegionState = $freshParent->state->value[0] ?? $oldRegionState;
                    $restoredValues = array_map(
                        fn (string $v): string => $v === $oldRegionState ? $newRegionState : $v,
                        $parallelValues,
                    );
                    $freshParent->state->setValues($restoredValues);

                    // Fix machine_value snapshots in events recorded during routing
                    for ($i = count($parallelValues); $i < $historyCount; $i++) {
                        if (isset($freshParent->state->history[$i])) {
                            $freshParent->state->history[$i]->machine_value = $restoredValues;
                        }
                    }
                }

                // Restore currentStateDefinition to the parallel state
                if ($parallelCSD !== null) {
                    $freshParent->state->currentStateDefinition = $parallelCSD;
                }

                // Check if all regions are now final → process parallel @done
                if ($freshParent->definition->areAllRegionsFinal($parallelCSD, $freshParent->state)) {
                    $freshParent->state = $freshParent->definition->processParallelOnDone(
                        $parallelCSD,
                        $freshParent->state,
                    );
                }
            }

            // 6. Persist parent state
            $freshParent->persist();
        } finally {
            $lockHandle?->release();
        }
    }
}
