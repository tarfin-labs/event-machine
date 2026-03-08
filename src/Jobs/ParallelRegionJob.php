<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Support\Str;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Tarfinlabs\EventMachine\Actor\Machine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\Enums\SourceType;
use Tarfinlabs\EventMachine\Support\ArrayUtils;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

class ParallelRegionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout;
    public int $tries;
    public int $backoff;

    /**
     * @param  array<string, mixed>  $contextAtDispatch  Context snapshot at parallel entry (baseline for conflict detection)
     */
    public function __construct(
        public readonly string $machineClass,
        public readonly string $rootEventId,
        public readonly string $regionId,
        public readonly string $initialStateId,
        public readonly array $contextAtDispatch = [],
    ) {
        $this->timeout = (int) config('machine.parallel_dispatch.job_timeout', 300);
        $this->tries   = (int) config('machine.parallel_dispatch.job_tries', 3);
        $this->backoff = (int) config('machine.parallel_dispatch.job_backoff', 30);
    }

    public function handle(): void
    {
        // 1. Reconstruct machine from DB
        $machine    = $this->machineClass::create(state: $this->rootEventId);
        $definition = $machine->definition;

        // 2. Guard: is machine still in a parallel state?
        if (!$machine->state->isInParallelState()) {
            return;
        }

        // 3. Find region's initial state
        $region = $definition->idMap[$this->regionId] ?? null;
        if ($region === null) {
            return;
        }

        $regionInitial = $region->findInitialStateDefinition();
        if ($regionInitial === null || $regionInitial->entry === null || $regionInitial->entry === []) {
            return;
        }

        // 4. Guard: is this region still at its initial state?
        if (!in_array($regionInitial->id, $machine->state->value, true)) {
            return;
        }

        // 5. Snapshot context BEFORE entry actions (inner data, not wrapped toArray)
        $contextBefore = $machine->state->context->data;

        // 6. RUN ENTRY ACTIONS (expensive part — NO LOCK held)
        $regionInitial->runEntryActions($machine->state);

        // 7. Capture side effects
        $contextAfter = $machine->state->context->data;
        $contextDiff  = $this->computeContextDiff($contextBefore, $contextAfter);

        $raisedEvents = [];
        while ($definition->eventQueue->isNotEmpty()) {
            $raisedEvents[] = $definition->eventQueue->shift();
        }

        // 8. ACQUIRE DATABASE LOCK (blocking)
        $lockHandle = MachineLockManager::acquire(
            rootEventId: $this->rootEventId,
            timeout: (int) config('machine.parallel_dispatch.lock_timeout', 30),
            ttl: (int) config('machine.parallel_dispatch.lock_ttl', 60),
            context: "parallel_region:{$this->regionId}",
        );

        $shouldDispatch = false;

        try {
            // 9. Reload FRESH state from DB
            $freshMachine = $this->machineClass::create(state: $this->rootEventId);

            // 10. Guard: re-check under lock (double-guard pattern)
            //     Guards stay OUTSIDE DB::transaction() — return inside a
            //     Closure only exits the Closure, not the parent method.
            if (!$freshMachine->state->isInParallelState()) {
                $this->recordDoubleGuardAbort($freshMachine, $contextDiff, $raisedEvents, 'machine left parallel state');

                return;
            }

            $freshRegionInitial = $freshMachine->definition->idMap[$this->initialStateId] ?? null;
            if ($freshRegionInitial === null || !in_array($freshRegionInitial->id, $freshMachine->state->value, true)) {
                $this->recordDoubleGuardAbort($freshMachine, $contextDiff, $raisedEvents, 'region already advanced');

                return;
            }

            // 11-15. Mutation section — wrapped in DB::transaction() for atomicity.
            //        If persist (or any action side-effect) fails, everything rolls back.
            DB::transaction(function () use ($freshMachine, $contextDiff, $region, $raisedEvents): void {
                // 11. Apply context diff (deep merge, not overwrite)
                $conflictedKeys = [];

                foreach ($contextDiff as $key => $value) {
                    $existingValue = $freshMachine->state->context->data[$key] ?? null;

                    // Detect LWW conflict: compare against baseline (context at dispatch time).
                    // If the DB value differs from what it was when the parallel state was entered,
                    // a sibling region already modified this key.
                    $baselineValue = $this->contextAtDispatch[$key] ?? null;

                    if ($existingValue !== $baselineValue) {
                        $conflictedKeys[] = $key;
                    }

                    if (is_array($value) && is_array($existingValue)) {
                        $freshMachine->state->context->set($key, ArrayUtils::recursiveMerge($existingValue, $value));
                    } else {
                        $freshMachine->state->context->set($key, $value);
                    }
                }

                // 11b. Record context conflict if sibling regions wrote to same keys
                if ($conflictedKeys !== []) {
                    $freshMachine->state->setInternalEventBehavior(
                        type: InternalEvent::PARALLEL_CONTEXT_CONFLICT,
                        placeholder: $region->route,
                        payload: [
                            'region_id'       => $this->regionId,
                            'conflicted_keys' => $conflictedKeys,
                        ],
                    );
                }

                // 12. Record region action completion event (captures context snapshot for persist)
                $freshMachine->state->setInternalEventBehavior(
                    type: InternalEvent::PARALLEL_REGION_ENTER,
                    placeholder: $region->route,
                );

                // 13. Process raised events
                foreach ($raisedEvents as $event) {
                    $freshMachine->state = $freshMachine->definition->transition($event, $freshMachine->state);
                }

                // 14. Check parallel completion
                $freshRegion    = $freshMachine->definition->idMap[$this->regionId] ?? null;
                $parallelParent = $freshRegion?->parent;

                if ($parallelParent !== null && $freshMachine->definition->areAllRegionsFinal($parallelParent, $freshMachine->state)) {
                    $freshMachine->state = $freshMachine->definition->processParallelOnDone($parallelParent, $freshMachine->state);
                }

                // 14b. Stall detection: region completed entry actions but did not advance
                if ($raisedEvents === [] && in_array($this->initialStateId, $freshMachine->state->value, true)) {
                    $freshMachine->state->setInternalEventBehavior(
                        type: InternalEvent::PARALLEL_REGION_STALLED,
                        placeholder: $region->route,
                        payload: [
                            'region_id'        => $this->regionId,
                            'initial_state_id' => $this->initialStateId,
                            'context_changed'  => $contextDiff !== [],
                        ],
                    );
                }

                // 15. Persist
                $freshMachine->persist();
            });

            $shouldDispatch = true;
        } finally {
            $lockHandle?->release();

            // 16. Dispatch any new pending parallel jobs (only after successful persist)
            if ($shouldDispatch && isset($freshMachine)) {
                $freshMachine->dispatchPendingParallelJobs();
            } elseif (isset($freshMachine)) {
                // Clear pending dispatches to prevent stale references
                $freshMachine->definition->pendingParallelDispatches = [];
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        try {
            // Use a shorter timeout for fail handler — best-effort, avoid deadlock with sibling failures
            $failLockTimeout = min(5, (int) config('machine.parallel_dispatch.lock_timeout', 30));
            $lockHandle      = MachineLockManager::acquire(
                rootEventId: $this->rootEventId,
                timeout: $failLockTimeout,
                ttl: (int) config('machine.parallel_dispatch.lock_ttl', 60),
                context: "parallel_region_fail:{$this->regionId}",
            );

            try {
                $machine = $this->machineClass::create(state: $this->rootEventId);

                if (!$machine->state->isInParallelState()) {
                    return;
                }

                $region         = $machine->definition->idMap[$this->regionId] ?? null;
                $parallelParent = $region?->parent;

                if ($parallelParent === null) {
                    return;
                }

                $failEvent = new EventDefinition(
                    type: InternalEvent::PARALLEL_FAIL->value,
                    payload: [
                        'region_id' => $this->regionId,
                        'error'     => $exception->getMessage(),
                        'exception' => $exception::class,
                        'attempts'  => $this->attempts(),
                    ],
                );

                $machine->state = $machine->definition->processParallelOnFail(
                    $parallelParent,
                    $machine->state,
                    $failEvent,
                );

                $machine->persist();
            } finally {
                $lockHandle?->release();

                if (isset($machine)) {
                    $machine->dispatchPendingParallelJobs();
                }
            }
        } catch (\Throwable $e) {
            logger()->error('ParallelRegionJob: @fail handler also failed', [
                'machine_class'      => $this->machineClass,
                'root_event_id'      => $this->rootEventId,
                'region_id'          => $this->regionId,
                'original_error'     => $exception->getMessage(),
                'fail_handler_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record a double-guard abort as a durable internal event in machine_events.
     *
     * When entry actions ran without lock but the under-lock guard detects the
     * machine has moved on, any computed context diff and raised events are
     * discarded. This method persists an audit trail event so the discard is
     * observable in the machine's event history.
     *
     * @param  array<string, mixed>  $contextDiff
     * @param  array<mixed>  $raisedEvents
     */
    private function recordDoubleGuardAbort(
        Machine $freshMachine,
        array $contextDiff,
        array $raisedEvents,
        string $reason,
    ): void {
        $lastEvent = $freshMachine->state->history->last();

        MachineEvent::create([
            'id'              => Str::ulid()->toBase32(),
            'sequence_number' => $lastEvent->sequence_number + 1,
            'created_at'      => now(),
            'machine_id'      => $lastEvent->machine_id,
            'machine_value'   => $freshMachine->state->value,
            'root_event_id'   => $this->rootEventId,
            'source'          => SourceType::INTERNAL,
            'type'            => InternalEvent::PARALLEL_REGION_GUARD_ABORT
                ->generateInternalEventName(
                    machineId: $lastEvent->machine_id,
                    placeholder: $this->regionId,
                ),
            'version' => 1,
            'payload' => [
                'reason'             => $reason,
                'discarded_context'  => array_keys($contextDiff),
                'discarded_events'   => count($raisedEvents),
                'work_was_discarded' => $contextDiff !== [] || $raisedEvents !== [],
            ],
            'context' => $freshMachine->state->context->toArray(),
        ]);
    }

    /**
     * Compute the diff between context before and after entry action execution.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     *
     * @return array<string, mixed>
     */
    protected function computeContextDiff(array $before, array $after): array
    {
        $diff = [];

        foreach ($after as $key => $value) {
            if (!array_key_exists($key, $before)) {
                $diff[$key] = $value;
            } elseif (is_array($value) && is_array($before[$key])) {
                $nestedDiff = $this->computeContextDiff($before[$key], $value);
                if ($nestedDiff !== []) {
                    $diff[$key] = $value;
                }
            } elseif ($before[$key] !== $value) {
                $diff[$key] = $value;
            }
        }

        return $diff;
    }
}
