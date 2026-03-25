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
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
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

        $shouldPropagate = false;

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

            // Track parallel context for value preservation after routing
            $isParallelContext  = count($freshParent->state->value) > 1;
            $parallelValues     = $isParallelContext ? $freshParent->state->value : null;
            $parallelCSD        = $isParallelContext ? $freshParent->state->currentStateDefinition : null;
            $historyCountBefore = count($freshParent->state->history);

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

            // 5b. Parallel value preservation
            if ($isParallelContext && $parallelValues !== null) {
                $freshParent->definition->restoreParallelValues(
                    $freshParent->state,
                    $parallelValues,
                    $this->parentStateId,
                    $historyCountBefore,
                );

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

            // 7. Chain propagation: if the parent just reached a final state
            // and is itself a managed child, dispatch completion to grandparent.
            $shouldPropagate = $freshParent->state->currentStateDefinition->type === StateDefinitionType::FINAL;
        } finally {
            $lockHandle?->release();
        }

        if ($shouldPropagate) {
            $this->propagateChainCompletion($freshParent);
        }
    }

    /**
     * If this machine is itself a managed child and just reached a final state,
     * dispatch a ChildMachineCompletionJob to route @done/@fail to its own parent.
     *
     * This enables deep delegation chains: Parent → Child → Grandchild → ...
     */
    protected function propagateChainCompletion(Machine $machine): void
    {
        $rootEventId = $machine->state->history->first()->root_event_id;

        $childRecord = MachineChild::where('child_root_event_id', $rootEventId)
            ->where('status', MachineChild::STATUS_RUNNING)
            ->first();

        if ($childRecord === null) {
            return;
        }

        $childRecord->markCompleted();

        dispatch(new self(
            parentRootEventId: $childRecord->parent_root_event_id,
            parentMachineClass: $childRecord->parent_machine_class,
            parentStateId: $childRecord->parent_state_id,
            childMachineClass: $childRecord->child_machine_class,
            childRootEventId: $rootEventId,
            success: true,
            result: $machine->result(),
            childContextData: $machine->state->context->data,
            outputData: MachineDefinition::resolveChildOutput(
                $machine->state->currentStateDefinition,
                $machine->state->context,
            ),
            childFinalState: $machine->state->currentStateDefinition->key,
        ));
    }
}
