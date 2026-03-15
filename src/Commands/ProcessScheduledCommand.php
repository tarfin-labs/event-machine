<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Throwable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Definition\ScheduleDefinition;

/**
 * Processes a scheduled event for a machine class.
 *
 * Called by MachineScheduler registration (via Laravel Scheduler).
 * Resolves target instances using the schedule's resolver,
 * cross-checks with machine_current_states, and dispatches
 * SendToMachineJob for each valid instance via Bus::batch.
 */
class ProcessScheduledCommand extends Command
{
    protected $signature = 'machine:process-scheduled
        {--class= : Machine class FQCN (required)}
        {--event= : Event type to send (required)}';
    protected $description = 'Process a scheduled event for machine instances';

    public function handle(): int
    {
        $machineClass = (string) $this->option('class');
        $eventType    = (string) $this->option('event');

        if ($machineClass === '' || $eventType === '') {
            $this->error('Both --class and --event are required.');

            return self::FAILURE;
        }

        try {
            /** @var MachineDefinition $definition */
            $definition = $machineClass::definition();
        } catch (Throwable $e) {
            $this->error("Failed to load definition: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (!isset($definition->parsedSchedules[$eventType])) {
            $this->warn("Event '{$eventType}' not found in schedules for {$machineClass}.");

            return self::FAILURE;
        }

        $scheduleDef  = $definition->parsedSchedules[$eventType];
        $rootEventIds = $this->resolveInstances($scheduleDef, $definition, $machineClass, $eventType);

        if ($rootEventIds->isEmpty()) {
            $this->info('No matching instances found.');

            return self::SUCCESS;
        }

        $jobs = $rootEventIds->map(fn (string $id): SendToMachineJob => new SendToMachineJob(
            machineClass: $machineClass,
            rootEventId: $id,
            event: ['type' => $eventType],
        ));

        Bus::batch($jobs->all())
            ->name("schedule:{$machineClass}:{$eventType}")
            ->allowFailures()
            ->dispatch();

        $this->info("Dispatched {$rootEventIds->count()} jobs for {$eventType}.");

        return self::SUCCESS;
    }

    /**
     * Resolve target machine instances using the schedule's resolver.
     *
     * The resolver returns root_event_ids from a model-level query.
     * We cross-check against machine_current_states to ensure the IDs
     * belong to this machine class (handles conditional machine mapping).
     */
    protected function resolveInstances(
        ScheduleDefinition $scheduleDef,
        MachineDefinition $definition,
        string $machineClass,
        string $eventType,
    ): Collection {
        // Priority 1: Resolver (class or closure) — model-level query
        if ($scheduleDef->hasResolver()) {
            return $this->resolveViaResolver($scheduleDef, $machineClass);
        }

        // Priority 2: Auto-detect from idMap — machine_current_states query
        return $this->resolveViaAutoDetect($definition, $machineClass, $eventType);
    }

    /**
     * Resolve instances using the schedule's class or closure resolver.
     *
     * Cross-checks returned IDs against machine_current_states
     * to filter out IDs belonging to a different machine class.
     */
    protected function resolveViaResolver(
        ScheduleDefinition $scheduleDef,
        string $machineClass,
    ): Collection {
        try {
            $resolver = is_string($scheduleDef->resolver)
                ? resolve($scheduleDef->resolver)
                : $scheduleDef->resolver;

            $rootEventIds = $resolver();

            if (!$rootEventIds instanceof Collection || $rootEventIds->isEmpty()) {
                return collect();
            }
        } catch (Throwable $e) {
            $this->error("Resolver failed: {$e->getMessage()}");

            return collect();
        }

        // Safety cross-check: only keep IDs for this machine class
        return MachineCurrentState::query()
            ->whereIn('root_event_id', $rootEventIds)
            ->where('machine_class', $machineClass)
            ->pluck('root_event_id');
    }

    /**
     * Auto-detect target instances by scanning the definition's idMap.
     *
     * - Root-level `on` handler → send to ALL instances of this machine class
     * - State-level handlers → send only to instances in those states
     */
    protected function resolveViaAutoDetect(
        MachineDefinition $definition,
        string $machineClass,
        string $eventType,
    ): Collection {
        $isRootLevel  = isset($definition->root->transitionDefinitions[$eventType]);
        $targetStates = $this->detectTargetStates($definition, $eventType);

        // Guard: event is not root-level and no state handles it → nothing to dispatch
        if (!$isRootLevel && $targetStates === []) {
            $this->warn("Event '{$eventType}' is not handled by any state. Dispatching nothing.");

            return collect();
        }

        $query = MachineCurrentState::query()
            ->where('machine_class', $machineClass);

        if (!$isRootLevel) {
            $query->whereIn('state_id', $targetStates);
        }

        return $query->pluck('root_event_id');
    }

    /**
     * Find states that handle the given event type.
     *
     * If a root-level `on` handler exists, returns empty array
     * (means all states via event bubbling).
     *
     * @return array<string>
     */
    protected function detectTargetStates(MachineDefinition $definition, string $eventType): array
    {
        if (isset($definition->root->transitionDefinitions[$eventType])) {
            return []; // root handles it → all states
        }

        $handlerStates = [];
        foreach ($definition->idMap as $stateId => $stateDef) {
            if ($stateDef->transitionDefinitions !== null
                && isset($stateDef->transitionDefinitions[$eventType])) {
                $handlerStates[] = $stateId;
            }
        }

        // Expand parent states to include all descendant IDs.
        // machine_current_states records leaf state IDs, but event bubbling
        // means child states inherit parent's transitions.
        $expanded = [];
        foreach ($handlerStates as $handlerStateId) {
            $expanded[] = $handlerStateId;

            foreach (array_keys($definition->idMap) as $childId) {
                if (str_starts_with((string) $childId, $handlerStateId.'.')) {
                    $expanded[] = $childId;
                }
            }
        }

        return array_values(array_unique($expanded));
    }
}
