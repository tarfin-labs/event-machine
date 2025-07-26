<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;

class ArchiveMachineEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $batchSize = 100,
        protected ?array $archivalConfig = null
    ) {}

    public function handle(): void
    {
        $config = $this->archivalConfig ?? config('machine.archival', []);

        if (!($config['enabled'] ?? true)) {
            return;
        }

        $this->archiveQualifiedMachines($config);
    }

    protected function archiveQualifiedMachines(array $config): void
    {
        $triggers = $config['triggers'] ?? [];

        // Find machines that qualify for archival
        $qualifiedRootEventIds = $this->findQualifiedMachines($triggers);

        foreach ($qualifiedRootEventIds->chunk($this->batchSize) as $chunk) {
            foreach ($chunk as $rootEventId) {
                // Each machine archival is wrapped in its own transaction for safety
                DB::transaction(function () use ($rootEventId): void {
                    $this->archiveMachine($rootEventId);
                });
            }
        }
    }

    protected function findQualifiedMachines(array $triggers): \Illuminate\Support\Collection
    {
        $query = MachineEvent::query()
            ->select('root_event_id')
            ->whereNotIn('root_event_id', function ($subQuery): void {
                $subQuery->select('root_event_id')
                    ->from('machine_event_archives');
            });

        // Apply archival triggers
        if ($daysInactive = $triggers['days_inactive'] ?? null) {
            $cutoffDate = Carbon::now()->subDays($daysInactive);
            $query->where('created_at', '<', $cutoffDate);
        }

        if ($maxEvents = $triggers['max_events'] ?? null) {
            $query->havingRaw('COUNT(*) >= ?', [$maxEvents]);
        }

        if ($maxSize = $triggers['max_size'] ?? null) {
            $query->havingRaw('SUM(LENGTH(JSON_EXTRACT(payload, "$")) + LENGTH(JSON_EXTRACT(context, "$")) + LENGTH(JSON_EXTRACT(meta, "$"))) >= ?', [$maxSize]);
        }

        return $query->groupBy('root_event_id')
            ->pluck('root_event_id');
    }

    protected function archiveMachine(string $rootEventId): void
    {
        // Get all events for this machine
        $events = MachineEvent::query()
            ->where('root_event_id', $rootEventId)
            ->orderBy('sequence_number')
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        $eventCollection = new EventCollection($events->all());

        try {
            // Create archive
            MachineEventArchive::archiveEvents($eventCollection);

            // Always cleanup original events after successful archival
            MachineEvent::where('root_event_id', $rootEventId)->delete();

        } catch (\Exception $e) {
            // Log error but don't stop the job
            logger()->error('Failed to archive machine events', [
                'root_event_id' => $rootEventId,
                'error'         => $e->getMessage(),
            ]);

            // Re-throw to trigger transaction rollback
            throw $e;
        }
    }

    /**
     * Get the number of machines that qualify for archival.
     */
    public static function getQualifiedMachinesCount(?array $archivalConfig = null): int
    {
        $config = $archivalConfig ?? config('machine.archival', []);

        if (!($config['enabled'] ?? true)) {
            return 0;
        }

        $triggers = $config['triggers'] ?? [];
        $query    = MachineEvent::query()
            ->select('root_event_id')
            ->whereNotIn('root_event_id', function ($subQuery): void {
                $subQuery->select('root_event_id')
                    ->from('machine_event_archives');
            });

        // Apply the same triggers as findQualifiedMachines
        if ($daysInactive = $triggers['days_inactive'] ?? null) {
            $cutoffDate = Carbon::now()->subDays($daysInactive);
            $query->where('created_at', '<', $cutoffDate);
        }

        if ($maxEvents = $triggers['max_events'] ?? null) {
            $query->havingRaw('COUNT(*) >= ?', [$maxEvents]);
        }

        if ($maxSize = $triggers['max_size'] ?? null) {
            $query->havingRaw('SUM(LENGTH(JSON_EXTRACT(payload, "$")) + LENGTH(JSON_EXTRACT(context, "$")) + LENGTH(JSON_EXTRACT(meta, "$"))) >= ?', [$maxSize]);
        }

        return $query->groupBy('root_event_id')->get()->count();
    }

    /**
     * Create a job instance with custom configuration.
     */
    public static function withConfig(array $archivalConfig, int $batchSize = 100): self
    {
        return new self($batchSize, $archivalConfig);
    }
}
