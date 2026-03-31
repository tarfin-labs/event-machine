<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Behavior\MachineOutput;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Behavior\ChildMachineFailEvent;
use Tarfinlabs\EventMachine\Exceptions\RestoringStateException;

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
     * @param  array  $childContextData  The child's final context data (for @done fallback).
     * @param  string|null  $errorMessage  Error message (for @fail).
     * @param  int|string|null  $errorCode  Error code from the exception (for @fail).
     * @param  array|null  $outputData  The child's output (from final state `output` behavior or key filter).
     * @param  string|null  $outputClass  FQCN of MachineOutput subclass (for typed reconstruction on parent side).
     * @param  string|null  $failureClass  FQCN of MachineFailure subclass (for typed reconstruction on parent side).
     */
    public function __construct(
        public readonly string $parentRootEventId,
        public readonly string $parentMachineClass,
        public readonly string $parentStateId,
        public readonly string $childMachineClass,
        public readonly ?string $childRootEventId = null,
        public readonly bool $success = true,
        public readonly array $childContextData = [],
        public readonly ?string $errorMessage = null,
        public readonly int|string|null $errorCode = null,
        public readonly ?array $outputData = null,
        public readonly ?string $childFinalState = null,
        public readonly ?string $outputClass = null,
        public readonly ?string $failureClass = null,
    ) {}

    public function handle(): void
    {
        // 1. Restore parent machine from DB
        try {
            /** @var Machine $parentMachine */
            $parentMachine = $this->parentMachineClass::create(state: $this->parentRootEventId);
        } catch (RestoringStateException) {
            // Parent may be archived — attempt auto-restore and retry
            if (config('machine.archival.enabled')) {
                $archiveService = new ArchiveService();
                $restored       = $archiveService->restoreMachine($this->parentRootEventId);

                if ($restored instanceof EventCollection) {
                    // Events restored — retry the entire handle() to restore machine from fresh events
                    $parentMachine = $this->parentMachineClass::create(state: $this->parentRootEventId);
                } else {
                    logger()->warning('ChildMachineCompletionJob: parent machine archived but could not restore', [
                        'parent_root_event_id' => $this->parentRootEventId,
                        'parent_machine_class' => $this->parentMachineClass,
                    ]);

                    return;
                }
            } else {
                logger()->warning('ChildMachineCompletionJob: could not restore parent machine (no events found)', [
                    'parent_root_event_id' => $this->parentRootEventId,
                    'parent_machine_class' => $this->parentMachineClass,
                ]);

                return;
            }
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

        // 3. Acquire lock for parent state mutation.
        //    Re-entrant check: in sync queue mode, send() on the parent may already
        //    hold the lock (send → transition → ChildMachineJob → ChildMachineCompletionJob).
        $alreadyLocked = isset(Machine::$heldLockIds[$this->parentRootEventId]);
        $lockHandle    = null;

        if (!$alreadyLocked) {
            $lockHandle = MachineLockManager::acquire(
                rootEventId: $this->parentRootEventId,
                timeout: 30,
                ttl: 60,
                context: 'child_machine_completion',
            );
        }

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
                    'output'        => $this->outputData ?? $this->childContextData,
                    'output_class'  => $this->outputClass,
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
                    'failure_class' => $this->failureClass,
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
            $this->propagateChainCompletion($freshParent, $this->success);
        }
    }

    /**
     * If this machine is itself a managed child and just reached a final state,
     * dispatch a ChildMachineCompletionJob to route @done/@fail to its own parent.
     *
     * This enables deep delegation chains: Parent → Child → Grandchild → ...
     *
     * The $upstreamSuccess flag propagates the success/failure status through the chain.
     * When a child fails, the middle machine routes @fail to a "failed" final state.
     * The grandparent should receive this as a failure, not a success — so we carry
     * the original success flag forward. Each level can also define its own failure
     * class to re-wrap the error at its level.
     *
     * @param  bool  $upstreamSuccess  Whether the upstream child completed successfully.
     */
    protected function propagateChainCompletion(Machine $machine, bool $upstreamSuccess): void
    {
        $rootEventId = $machine->state->history->first()->root_event_id;

        $childRecord = MachineChild::where('child_root_event_id', $rootEventId)
            ->where('status', MachineChild::STATUS_RUNNING)
            ->first();

        if ($childRecord === null) {
            return;
        }

        if ($upstreamSuccess) {
            $childRecord->markCompleted();
        } else {
            $childRecord->markFailed();
        }

        $resolvedOutput = MachineDefinition::resolveChildOutput(
            $machine->state->currentStateDefinition,
            $machine->state->context,
        );

        // Serialize MachineOutput instances — store class FQCN for typed reconstruction
        $outputData  = $resolvedOutput instanceof MachineOutput ? $resolvedOutput->toArray() : $resolvedOutput;
        $outputClass = $resolvedOutput instanceof MachineOutput ? $resolvedOutput::class : null;

        if ($upstreamSuccess) {
            dispatch(new self(
                parentRootEventId: $childRecord->parent_root_event_id,
                parentMachineClass: $childRecord->parent_machine_class,
                parentStateId: $childRecord->parent_state_id,
                childMachineClass: $childRecord->child_machine_class,
                childRootEventId: $rootEventId,
                success: true,
                childContextData: $machine->state->context->data,
                outputData: $outputData,
                childFinalState: $machine->state->currentStateDefinition->key,
                outputClass: $outputClass,
            ));
        } else {
            // Propagate failure: the middle machine captured the error in its context
            // and may define its own failure class for re-wrapping.
            $failureClass = $machine->definition->failureClass;

            dispatch(new self(
                parentRootEventId: $childRecord->parent_root_event_id,
                parentMachineClass: $childRecord->parent_machine_class,
                parentStateId: $childRecord->parent_state_id,
                childMachineClass: $childRecord->child_machine_class,
                childRootEventId: $rootEventId,
                success: false,
                childContextData: $machine->state->context->data,
                errorMessage: $machine->state->context->get('error') ?? $this->errorMessage ?? 'Child machine failed',
                errorCode: $this->errorCode,
                outputData: $outputData,
                childFinalState: $machine->state->currentStateDefinition->key,
                failureClass: $failureClass,
            ));
        }
    }
}
