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
use Tarfinlabs\EventMachine\Models\MachineChild;
use Tarfinlabs\EventMachine\Behavior\MachineOutput;
use Tarfinlabs\EventMachine\Behavior\MachineFailure;
use Tarfinlabs\EventMachine\Scenarios\ScenarioPlayer;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\InvalidMachineClassException;

/**
 * Queue job that creates and runs a child machine asynchronously.
 *
 * Dispatched when a parent state with `machine` + `queue` is entered.
 * Creates the child machine, runs it to completion (or leaves it waiting
 * for external events in webhook patterns), then dispatches a
 * ChildMachineCompletionJob to route @done/@fail back to the parent.
 *
 * In fire-and-forget mode (no @done on parent), the child runs independently:
 * no MachineChild tracking, no ChildMachineCompletionJob.
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
     * @param  string  $machineChildId  The MachineChild tracking record ID (empty for fire-and-forget).
     * @param  array<string, mixed>  $childContext  Resolved context from parent's `with` config.
     * @param  int  $retry  Number of retry attempts (from machine config).
     * @param  bool  $fireAndForget  Whether this is a fire-and-forget invocation (no @done).
     * @param  string|null  $scenarioClass  FQCN of an active MachineScenario to apply on async boot.
     */
    public function __construct(
        public readonly string $parentRootEventId,
        public readonly string $parentMachineClass,
        public readonly string $parentStateId,
        public readonly string $childMachineClass,
        public readonly string $machineChildId,
        public readonly array $childContext = [],
        int $retry = 1,
        public readonly bool $fireAndForget = false,
        public readonly ?string $scenarioClass = null,
    ) {
        $this->tries = $retry;
    }

    public function handle(): void
    {
        if (!class_exists($this->childMachineClass) || !is_subclass_of($this->childMachineClass, Machine::class)) {
            throw InvalidMachineClassException::mustExtendMachine($this->childMachineClass);
        }

        // Async scenario propagation: when a parent scenario references a child scenario
        // (or this state has an active scenario at dispatch time), restore that scenario's
        // overrides + outcomes + isActive flag in this fresh worker process so the child
        // boot interception path (MachineDefinition::handleScenarioOutcome) can fire.
        $scenarioActivated = false;
        if ($this->scenarioClass !== null
            && class_exists($this->scenarioClass)
            && is_subclass_of($this->scenarioClass, MachineScenario::class)) {
            ScenarioPlayer::activateForAsyncBoot(new $this->scenarioClass());
            $scenarioActivated = true;
        }

        try {
            $this->processChild();
        } finally {
            if ($scenarioActivated) {
                ScenarioPlayer::deactivate();
            }
        }
    }

    protected function processChild(): void
    {
        // 1. Update tracking record to running (skip for fire-and-forget).
        //    Use lockForUpdate to prevent duplicate child creation from
        //    concurrent ChildMachineJob dispatches (race on same machineChildId).
        if (!$this->fireAndForget) {
            $childRecord = DB::transaction(function () {
                $record = MachineChild::lockForUpdate()->find($this->machineChildId);

                if ($record === null || $record->isTerminal()) {
                    return null;
                }

                $record->update(['status' => MachineChild::STATUS_RUNNING]);

                return $record;
            });

            if ($childRecord === null) {
                return;
            }
        }

        // 2. Create child machine with merged context
        /** @var Machine $childMachine */
        $childMachine                           = $this->childMachineClass::withDefinition($this->childMachineClass::definition());
        $childMachine->definition->machineClass = $this->childMachineClass;

        // Clone definition before mutating to avoid shared state if definitions are cached.
        // Merge parent's `with` context into child's initial context.
        if ($this->childContext !== []) {
            $childMachine->definition                    = clone $childMachine->definition;
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

        // 4. Fire-and-forget: persist child and stop (no tracking, no completion)
        if ($this->fireAndForget) {
            $childMachine->persist();
            $this->persistScenarioClassOnRow($childRootEventId);

            return;
        }

        // 5. Update tracking record with child's root_event_id
        $childRecord->update(['child_root_event_id' => $childRootEventId]);

        // 6. Persist child state
        $childMachine->persist();
        $this->persistScenarioClassOnRow($childRootEventId);

        // 7. Check if child reached a final state
        if ($childMachine->state->currentStateDefinition->type === StateDefinitionType::FINAL) {
            $childRecord->markCompleted();

            // Resolve output and serialize MachineOutput for typed reconstruction
            $resolvedOutput = MachineDefinition::resolveChildOutput(
                $childMachine->state->currentStateDefinition,
                $childMachine->state->context,
            );

            $outputData  = $resolvedOutput instanceof MachineOutput ? $resolvedOutput->toArray() : $resolvedOutput;
            $outputClass = $resolvedOutput instanceof MachineOutput ? $resolvedOutput::class : null;

            // Dispatch completion job to route @done to parent
            dispatch(new ChildMachineCompletionJob(
                parentRootEventId: $this->parentRootEventId,
                parentMachineClass: $this->parentMachineClass,
                parentStateId: $this->parentStateId,
                childMachineClass: $this->childMachineClass,
                childRootEventId: $childRootEventId,
                success: true,
                childContextData: $childMachine->state->context->data,
                outputData: $outputData,
                childFinalState: $childMachine->state->currentStateDefinition->key,
                outputClass: $outputClass,
            ));
        }

        // If child is NOT final, it stays persisted awaiting external events
        // (webhook pattern). Completion will be triggered by the endpoint
        // or forward event that drives the child to a final state.
    }

    /**
     * Write the active scenarioClass onto the child's machine_current_states row(s).
     * This is what the §9 Async Propagation block in Machine::restoreStateFromRootEventId
     * reads when subsequent SendToMachineJob events restore the child mid-flight.
     */
    protected function persistScenarioClassOnRow(string $childRootEventId): void
    {
        if ($this->scenarioClass === null) {
            return;
        }

        MachineCurrentState::where('root_event_id', $childRootEventId)
            ->update([
                'scenario_class'  => $this->scenarioClass,
                'scenario_params' => null,
            ]);
    }

    public function failed(\Throwable $exception): void
    {
        // Fire-and-forget: no parent notification on failure
        if ($this->fireAndForget) {
            return;
        }

        // Update tracking record
        $childRecord = MachineChild::find($this->machineChildId);
        $childRecord?->markFailed();

        // Resolve typed failure if child declares a failure class
        $outputData   = null;
        $failureClass = null;
        $childMachine = $this->childMachineClass;

        if (class_exists($childMachine) && method_exists($childMachine, 'definition')) {
            try {
                $definition   = $childMachine::definition();
                $failureClass = $definition->failureClass;

                if ($failureClass !== null && is_subclass_of($failureClass, MachineFailure::class)) {
                    $failure    = $failureClass::fromException($exception);
                    $outputData = $failure->toArray();
                }
            } catch (\Throwable) {
                // Failed to resolve failure — proceed with raw exception data
            }
        }

        // Dispatch completion job with error payload to route @fail on parent
        dispatch(new ChildMachineCompletionJob(
            parentRootEventId: $this->parentRootEventId,
            parentMachineClass: $this->parentMachineClass,
            parentStateId: $this->parentStateId,
            childMachineClass: $this->childMachineClass,
            childRootEventId: null,
            success: false,
            errorMessage: $exception->getMessage(),
            errorCode: $exception->getCode(),
            outputData: $outputData,
            failureClass: $failureClass,
        ));
    }
}
