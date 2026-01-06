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

    /**
     * The number of seconds the job can run before timing out.
     * Default: 30 minutes for large dataset processing.
     */
    public int $timeout = 1800;

    /** The number of times the job may be attempted. */
    public int $tries = 3;

    /** The number of seconds to wait before retrying the job. */
    public int $backoff = 60;

    public function __construct(
        protected int $batchSize = 100,
        protected ?array $archivalConfig = null
    ) {
        $this->onQueue(config('machine.archival.advanced.queue', 'default'));
    }

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
        $daysInactive = $config['days_inactive'] ?? 30;

        // Find machines that qualify for archival
        $qualifiedRootEventIds = $this->findQualifiedMachines($daysInactive);

        foreach ($qualifiedRootEventIds->chunk($this->batchSize) as $chunk) {
            foreach ($chunk as $rootEventId) {
                // Each machine archival is wrapped in its own transaction for safety
                DB::transaction(function () use ($rootEventId): void {
                    $this->archiveMachine($rootEventId);
                });
            }
        }
    }

    protected function findQualifiedMachines(int $daysInactive): \Illuminate\Support\Collection
    {
        $cutoffDate = Carbon::now()->subDays($daysInactive);

        return MachineEvent::query()
            ->select('root_event_id', DB::raw('MAX(created_at) as last_activity'))
            ->whereNotIn('root_event_id', function ($subQuery): void {
                $subQuery->select('root_event_id')
                    ->from('machine_event_archives');
            })
            ->groupBy('root_event_id')
            ->having('last_activity', '<', $cutoffDate)
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

        $daysInactive = $config['days_inactive'] ?? 30;
        $cutoffDate   = Carbon::now()->subDays($daysInactive);

        return MachineEvent::query()
            ->select('root_event_id', DB::raw('MAX(created_at) as last_activity'))
            ->whereNotIn('root_event_id', function ($subQuery): void {
                $subQuery->select('root_event_id')
                    ->from('machine_event_archives');
            })
            ->groupBy('root_event_id')
            ->having('last_activity', '<', $cutoffDate)
            ->get()
            ->count();
    }

    /**
     * Create a job instance with custom configuration.
     */
    public static function withConfig(array $archivalConfig, int $batchSize = 100): self
    {
        return new self($batchSize, $archivalConfig);
    }
}
