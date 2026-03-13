<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;

/**
 * Queue job that creates and runs a child machine asynchronously.
 *
 * Dispatched when a parent state with `machine` + `queue` is entered.
 * Creates the child machine, runs it to completion (or leaves it waiting
 * for external events in webhook patterns), then dispatches a
 * ChildMachineCompletionJob to route @done/@fail back to the parent.
 */
class ChildMachineJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    /**
     * @param  string  $parentRootEventId  The parent machine's root_event_id.
     * @param  string  $parentMachineClass  FQCN of the parent machine.
     * @param  string  $parentStateId  The parent state that invoked this child.
     * @param  string  $childMachineClass  FQCN of the child machine.
     * @param  string  $machineChildId  The MachineChild tracking record ID.
     * @param  array  $childContext  Resolved context from parent's `with` config.
     * @param  int  $retry  Number of retry attempts (from machine config).
     */
    public function __construct(
        public readonly string $parentRootEventId,
        public readonly string $parentMachineClass,
        public readonly string $parentStateId,
        public readonly string $childMachineClass,
        public readonly string $machineChildId,
        public readonly array $childContext = [],
        int $retry = 1,
    ) {
        $this->tries = $retry;
    }

    public function handle(): void
    {
        // 1. Update tracking record to running
        $childRecord = MachineChild::find($this->machineChildId);
        if ($childRecord === null || $childRecord->isTerminal()) {
            return;
        }

        $childRecord->update(['status' => MachineChild::STATUS_RUNNING]);

        // 2. Create child machine with merged context
        /** @var Machine $childMachine */
        $childMachine                           = $this->childMachineClass::withDefinition($this->childMachineClass::definition());
        $childMachine->definition->machineClass = $this->childMachineClass;

        // Merge parent's `with` context into child's initial context
        if ($this->childContext !== []) {
            $childMachine->definition->config['context'] = array_merge(
                $childMachine->definition->config['context'] ?? [],
                $this->childContext,
            );
        }

        // 3. Start the child (runs entry actions, @always transitions)
        $childMachine->start();

        // Inject parent identity into child's context
        $childRootEventId = $childMachine->state->history->first()->root_event_id;
        $childMachine->state->context->setMachineIdentity(
            machineId: $childRootEventId,
            parentRootEventId: $this->parentRootEventId,
            parentMachineClass: $this->parentMachineClass,
        );

        // 4. Update tracking record with child's root_event_id
        $childRecord->update(['child_root_event_id' => $childRootEventId]);

        // 5. Persist child state
        $childMachine->persist();

        // 6. Check if child reached a final state
        if ($childMachine->state->currentStateDefinition->type === StateDefinitionType::FINAL) {
            $childRecord->markCompleted();

            // Dispatch completion job to route @done to parent
            dispatch(new ChildMachineCompletionJob(
                parentRootEventId: $this->parentRootEventId,
                parentMachineClass: $this->parentMachineClass,
                parentStateId: $this->parentStateId,
                childMachineClass: $this->childMachineClass,
                childRootEventId: $childRootEventId,
                success: true,
                result: $childMachine->result(),
                childContextData: $childMachine->state->context->data,
            ));
        }

        // If child is NOT final, it stays persisted awaiting external events
        // (webhook pattern). Completion will be triggered by the endpoint
        // or forward event that drives the child to a final state.
    }

    public function failed(\Throwable $exception): void
    {
        // Update tracking record
        $childRecord = MachineChild::find($this->machineChildId);
        $childRecord?->markFailed();

        // Dispatch completion job with error payload to route @fail on parent
        dispatch(new ChildMachineCompletionJob(
            parentRootEventId: $this->parentRootEventId,
            parentMachineClass: $this->parentMachineClass,
            parentStateId: $this->parentStateId,
            childMachineClass: $this->childMachineClass,
            childRootEventId: null,
            success: false,
            errorMessage: $exception->getMessage(),
        ));
    }
}
