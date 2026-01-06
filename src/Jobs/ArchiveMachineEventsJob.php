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
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;

/**
 * Cursor-based archival job optimized for large datasets (100GB+).
 *
 * Instead of GROUP BY on millions of rows (slow), this job uses NOT EXISTS
 * which allows MySQL to use indexes efficiently:
 * 1. Find old events (uses created_at index)
 * 2. Exclude machines with recent activity via NOT EXISTS (uses composite index)
 * 3. Self-dispatches for continuation
 */
class ArchiveMachineEventsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The number of seconds the job can run before timing out. */
    public int $timeout = 1800;

    /** The number of times the job may be attempted. */
    public int $tries = 3;

    /** The number of seconds to wait before retrying the job. */
    public int $backoff = 60;

    /** Unique lock duration in seconds. */
    public int $uniqueFor = 3600;

    public function __construct(
        protected int $batchSize = 100,
        protected ?string $processUntilDate = null,
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

        $daysInactive = $config['days_inactive'] ?? 30;
        $cutoffDate   = Carbon::now()->subDays($daysInactive);

        // Find inactive machines to archive
        $machinesToArchive = $this->findInactiveMachines($cutoffDate, $this->batchSize);

        if ($machinesToArchive->isEmpty()) {
            logger()->info('ArchiveMachineEventsJob: No machines to archive in this batch.');

            return;
        }

        // Archive each machine
        $archived = 0;
        $failed   = 0;

        foreach ($machinesToArchive as $rootEventId) {
            try {
                DB::transaction(function () use ($rootEventId): void {
                    $this->archiveMachine($rootEventId);
                });
                $archived++;
            } catch (\Exception $e) {
                $failed++;
                logger()->error('Failed to archive machine', [
                    'root_event_id' => $rootEventId,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        logger()->info('ArchiveMachineEventsJob batch completed', [
            'archived' => $archived,
            'failed'   => $failed,
        ]);

        // Self-dispatch for next batch if there might be more
        if ($archived > 0 && $this->shouldContinue($cutoffDate)) {
            static::dispatch($this->batchSize, $this->processUntilDate, $this->archivalConfig)
                ->delay(now()->addSeconds(5));
        }
    }

    /**
     * Find inactive machines to archive using NOT EXISTS pattern.
     *
     * This query is optimized for large tables:
     * - Uses created_at index to filter old events
     * - Uses NOT EXISTS with composite index (root_event_id, created_at) for activity check
     * - No GROUP BY = no full table scan
     */
    protected function findInactiveMachines(Carbon $cutoffDate, int $limit): \Illuminate\Support\Collection
    {
        return MachineEvent::query()
            ->select('root_event_id')
            ->where('created_at', '<', $cutoffDate)
            // Exclude already archived
            ->whereNotIn('root_event_id', function ($subQuery): void {
                $subQuery->select('root_event_id')
                    ->from('machine_event_archives');
            })
            // Exclude machines with recent activity (NOT EXISTS uses index efficiently)
            ->whereNotExists(function ($subQuery) use ($cutoffDate): void {
                $subQuery->select(DB::raw(1))
                    ->from('machine_events as recent')
                    ->whereColumn('recent.root_event_id', 'machine_events.root_event_id')
                    ->where('recent.created_at', '>=', $cutoffDate);
            })
            ->distinct()
            ->limit($limit)
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

        // Create archive
        MachineEventArchive::archiveEvents($eventCollection);

        // Cleanup original events after successful archival
        MachineEvent::where('root_event_id', $rootEventId)->delete();
    }

    /**
     * Determine if the job should continue with another batch.
     */
    protected function shouldContinue(Carbon $cutoffDate): bool
    {
        return MachineEvent::query()
            ->where('created_at', '<', $cutoffDate)
            ->whereNotIn('root_event_id', function ($subQuery): void {
                $subQuery->select('root_event_id')
                    ->from('machine_event_archives');
            })
            ->whereNotExists(function ($subQuery) use ($cutoffDate): void {
                $subQuery->select(DB::raw(1))
                    ->from('machine_events as recent')
                    ->whereColumn('recent.root_event_id', 'machine_events.root_event_id')
                    ->where('recent.created_at', '>=', $cutoffDate);
            })
            ->exists();
    }

    /**
     * Get the number of machines that qualify for archival.
     *
     * WARNING: This can be slow on very large tables. Use sparingly.
     * For progress tracking, use shouldContinue() instead.
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
            ->select('root_event_id')
            ->where('created_at', '<', $cutoffDate)
            ->whereNotIn('root_event_id', function ($subQuery): void {
                $subQuery->select('root_event_id')
                    ->from('machine_event_archives');
            })
            ->whereNotExists(function ($subQuery) use ($cutoffDate): void {
                $subQuery->select(DB::raw(1))
                    ->from('machine_events as recent')
                    ->whereColumn('recent.root_event_id', 'machine_events.root_event_id')
                    ->where('recent.created_at', '>=', $cutoffDate);
            })
            ->distinct()
            ->count('root_event_id');
    }

    /**
     * Create a job instance with custom configuration.
     */
    public static function withConfig(array $archivalConfig, int $batchSize = 100): self
    {
        return new self($batchSize, null, $archivalConfig);
    }

    /**
     * Create a job to process events until a specific date.
     */
    public static function until(string $date, int $batchSize = 100): self
    {
        return new self($batchSize, $date, null);
    }
}
