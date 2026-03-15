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
        $rootEventIds = $this->resolveInstances($scheduleDef, $machineClass);

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
        string $machineClass,
    ): Collection {
        if (!$scheduleDef->hasResolver()) {
            return collect();
        }

        try {
            $resolver = is_string($scheduleDef->resolver)
                ? resolve($scheduleDef->resolver)
                : $scheduleDef->resolver;

            $rootEventIds = $resolver();
        } catch (Throwable $e) {
            $this->error("Resolver failed: {$e->getMessage()}");

            return collect();
        }

        if ($rootEventIds->isEmpty()) {
            return collect();
        }

        // Safety cross-check: only keep IDs for this machine class
        return MachineCurrentState::query()
            ->whereIn('root_event_id', $rootEventIds)
            ->where('machine_class', $machineClass)
            ->pluck('root_event_id');
    }
}
