<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Jobs\ArchiveSingleMachineJob;

/**
 * Command-based dispatcher for archival (Fan-out pattern).
 *
 * Finds eligible machine instances and dispatches ArchiveSingleMachineJob
 * for each. Designed to be called from scheduler.
 *
 * Usage in Kernel.php:
 *   $schedule->command('machine:archive-events')
 *       ->everyFiveMinutes()
 *       ->withoutOverlapping()
 *       ->onOneServer()
 *       ->runInBackground();
 */
class ArchiveEventsCommand extends Command
{
    protected $signature = 'machine:archive-events
                           {--dispatch-limit=50 : Max workflows to dispatch per run}
                           {--dry-run : Show what would be dispatched without dispatching}
                           {--sync : Run synchronously (testing only)}';
    protected $description = 'Dispatch archival jobs for inactive machine events (fan-out pattern)';

    public function handle(): int
    {
        $config = config('machine.archival', []);

        if (!($config['enabled'] ?? true)) {
            $this->error('Archival is disabled. Set MACHINE_EVENTS_ARCHIVAL_ENABLED=true');

            return self::FAILURE;
        }

        $dispatchLimit = (int) $this->option('dispatch-limit');
        $dryRun        = $this->option('dry-run');
        $sync          = $this->option('sync');

        // Find eligible machines
        $cutoffDate = Carbon::now()->subDays($config['days_inactive'] ?? 30);
        $machines   = $this->findEligibleMachines($cutoffDate, $dispatchLimit);

        if ($machines->isEmpty()) {
            $this->info('No eligible machines found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            return $this->showDryRun($machines);
        }

        // Dispatch jobs
        return $this->dispatchJobs($machines, $sync);
    }

    /**
     * Find eligible machine instances using NOT EXISTS pattern.
     * Optimized for large tables (100GB+).
     */
    protected function findEligibleMachines(Carbon $cutoffDate, int $limit): Collection
    {
        return MachineEvent::query()
            ->select('root_event_id')
            ->where('created_at', '<', $cutoffDate)
            // Exclude already archived
            ->whereNotIn('root_event_id', function ($subQuery): void {
                $subQuery->select('root_event_id')
                    ->from('machine_event_archives');
            })
            // Exclude instances with recent activity (NOT EXISTS is index-friendly)
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

    protected function dispatchJobs(Collection $machines, bool $sync): int
    {
        $queue = config('machine.archival.advanced.queue');

        try {
            foreach ($machines as $rootEventId) {
                $job = new ArchiveSingleMachineJob($rootEventId);

                if ($queue !== null) {
                    $job->onQueue($queue);
                }

                if ($sync) {
                    dispatch_sync($job);
                } else {
                    dispatch($job);
                }
            }
        } catch (Exception $e) {
            $this->error('Failed to dispatch jobs: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Dispatched {$machines->count()} archival jobs.");

        if ($queue) {
            $this->line("  Queue: {$queue}");
        }

        return self::SUCCESS;
    }

    protected function showDryRun(Collection $machines): int
    {
        $this->info('Dry Run - Would dispatch:');
        $this->table(
            ['#', 'Root Event ID'],
            $machines->map(fn ($id, $i): array => [$i + 1, $id])->all()
        );

        $this->newLine();
        $this->line("Total: {$machines->count()} machines");
        $this->line('Queue: '.config('machine.archival.advanced.queue', 'default'));

        return self::SUCCESS;
    }
}
