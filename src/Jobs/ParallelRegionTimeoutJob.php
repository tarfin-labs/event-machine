<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\Enums\InternalEvent;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

/**
 * Delayed check job that detects stuck parallel states.
 *
 * Dispatched alongside ParallelRegionJob with a configurable delay
 * (region_timeout). When the delay expires, checks whether the parallel
 * state has completed. If any region is still not final, records a
 * PARALLEL_REGION_TIMEOUT event and triggers @fail on the parallel state.
 */
class ParallelRegionTimeoutJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $machineClass,
        public readonly string $rootEventId,
        public readonly string $parallelStateId,
    ) {}

    public function handle(): void
    {
        // 1. Reconstruct machine from DB
        $machine = $this->machineClass::create(state: $this->rootEventId);

        // 2. Pre-lock guard: is machine still in this parallel state?
        if (!$machine->state->isInParallelState()) {
            return;
        }

        $parallelState = $machine->definition->idMap[$this->parallelStateId] ?? null;
        if ($parallelState === null || $parallelState->type !== StateDefinitionType::PARALLEL) {
            return;
        }

        // 3. Already completed? Nothing to do.
        if ($machine->definition->areAllRegionsFinal($parallelState, $machine->state)) {
            return;
        }

        // 4. Timeout confirmed — acquire lock for state mutation
        $lockHandle = MachineLockManager::acquire(
            rootEventId: $this->rootEventId,
            timeout: (int) config('machine.parallel_dispatch.lock_timeout', 30),
            ttl: (int) config('machine.parallel_dispatch.lock_ttl', 60),
            context: "parallel_region_timeout:{$this->parallelStateId}",
        );

        $shouldDispatch = false;

        try {
            // 5. Re-check under lock (double-guard pattern)
            $freshMachine = $this->machineClass::create(state: $this->rootEventId);

            if (!$freshMachine->state->isInParallelState()) {
                return;
            }

            $freshParallelState = $freshMachine->definition->idMap[$this->parallelStateId] ?? null;
            if ($freshParallelState === null || $freshParallelState->type !== StateDefinitionType::PARALLEL) {
                return;
            }

            if ($freshMachine->definition->areAllRegionsFinal($freshParallelState, $freshMachine->state)) {
                return;
            }

            // 6. Identify which regions are still not final
            $stalledRegions = $this->findStalledRegions($freshMachine, $freshParallelState);

            $timeoutSeconds = (int) config('machine.parallel_dispatch.region_timeout', 0);

            // 7. Record timeout event and trigger @fail
            DB::transaction(function () use ($freshMachine, $freshParallelState, $stalledRegions, $timeoutSeconds): void {
                $freshMachine->state->setInternalEventBehavior(
                    type: InternalEvent::PARALLEL_REGION_TIMEOUT,
                    placeholder: $freshParallelState->route,
                    payload: [
                        'parallel_state_id' => $this->parallelStateId,
                        'timeout_seconds'   => $timeoutSeconds,
                        'stalled_regions'   => $stalledRegions,
                    ],
                );

                $timeoutEvent = new EventDefinition(
                    type: InternalEvent::PARALLEL_REGION_TIMEOUT->value,
                    payload: [
                        'parallel_state_id' => $this->parallelStateId,
                        'timeout_seconds'   => $timeoutSeconds,
                        'stalled_regions'   => $stalledRegions,
                    ],
                );

                $freshMachine->state = $freshMachine->definition->processParallelOnFail(
                    $freshParallelState,
                    $freshMachine->state,
                    $timeoutEvent,
                );

                $freshMachine->persist();
            });

            $shouldDispatch = true;
        } finally {
            $lockHandle?->release();

            if ($shouldDispatch && isset($freshMachine)) {
                $freshMachine->dispatchPendingParallelJobs();
            }
        }
    }

    /**
     * Find region IDs that have not reached a final state.
     *
     * @return array<int, string>
     */
    private function findStalledRegions(
        \Tarfinlabs\EventMachine\Actor\Machine $machine,
        \Tarfinlabs\EventMachine\Definition\StateDefinition $parallelState,
    ): array {
        $stalledRegions = [];

        if ($parallelState->stateDefinitions === null) {
            return $stalledRegions;
        }

        foreach ($parallelState->stateDefinitions as $region) {
            $regionIsFinal = false;

            foreach ($machine->state->value as $activeStateId) {
                if (str_starts_with($activeStateId, $region->id)) {
                    $activeState = $machine->definition->idMap[$activeStateId] ?? null;

                    if ($activeState !== null && $activeState->type === StateDefinitionType::FINAL && $activeState->parent === $region) {
                        $regionIsFinal = true;

                        break;
                    }
                }
            }

            if (!$regionIsFinal) {
                $stalledRegions[] = $region->id;
            }
        }

        return $stalledRegions;
    }
}
