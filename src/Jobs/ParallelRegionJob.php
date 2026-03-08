<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\Support\ArrayUtils;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

class ParallelRegionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;
    public int $tries   = 3;
    public int $backoff = 30;

    public function __construct(
        public readonly string $machineClass,
        public readonly string $rootEventId,
        public readonly string $regionId,
        public readonly string $initialStateId,
    ) {}

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

        try {
            // 9. Reload FRESH state from DB
            $freshMachine = $this->machineClass::create(state: $this->rootEventId);

            // 10. Guard: re-check under lock (double-guard pattern)
            if (!$freshMachine->state->isInParallelState()) {
                return;
            }

            $freshRegionInitial = $freshMachine->definition->idMap[$this->initialStateId] ?? null;
            if ($freshRegionInitial === null || !in_array($freshRegionInitial->id, $freshMachine->state->value, true)) {
                return;
            }

            // 11. Apply context diff (deep merge, not overwrite)
            foreach ($contextDiff as $key => $value) {
                $existingValue = $freshMachine->state->context->data[$key] ?? null;

                if (is_array($value) && is_array($existingValue)) {
                    $freshMachine->state->context->set($key, ArrayUtils::recursiveMerge($existingValue, $value));
                } else {
                    $freshMachine->state->context->set($key, $value);
                }
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

            // 15. Persist
            $freshMachine->persist();
        } finally {
            $lockHandle?->release();

            // 16. Dispatch any new pending parallel jobs (after lock release)
            if (isset($freshMachine)) {
                $freshMachine->dispatchPendingParallelJobs();
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
