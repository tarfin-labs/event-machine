<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Jobs\ArchiveMachineEventsJob;

class ArchiveEventsCommand extends Command
{
    protected $signature = 'machine:archive-events
                           {--batch-size=100 : Number of machines to process per batch}
                           {--dry-run : Show statistics without archiving}
                           {--force : Skip confirmation prompt}
                           {--queue : Dispatch to queue (recommended for production)}
                           {--once : Run single batch only, no self-dispatch}';
    protected $description = 'Archive inactive machine events to compressed storage';

    public function handle(): int
    {
        $config = config('machine.archival', []);

        if (!($config['enabled'] ?? true)) {
            $this->error('Archival is disabled. Set MACHINE_EVENTS_ARCHIVAL_ENABLED=true');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($config);
        }

        if ($this->option('queue')) {
            return $this->dispatchToQueue($config);
        }

        return $this->runSynchronous($config);
    }

    /**
     * Show archival statistics without making changes.
     * Optimized for large tables - uses sampling instead of full COUNT.
     */
    protected function dryRun(array $config): int
    {
        $this->info('Machine Events Archival - Dry Run');
        $this->info('==================================');
        $this->newLine();

        $daysInactive = $config['days_inactive'] ?? 30;
        $cutoffDate   = Carbon::now()->subDays($daysInactive);

        // Fast: Count active machines (uses created_at index)
        $this->output->write('Counting active machines... ');
        $start          = microtime(true);
        $activeMachines = MachineEvent::query()
            ->where('created_at', '>=', $cutoffDate)
            ->distinct()
            ->count('root_event_id');
        $this->line(sprintf('%s (%.2fs)', number_format($activeMachines), microtime(true) - $start));

        // Get total machines from index cardinality (instant)
        $this->output->write('Estimating total machines... ');
        $indexStats = DB::select("
            SELECT CARDINALITY
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'machine_events'
            AND INDEX_NAME = 'machine_events_root_event_id_index'
            LIMIT 1
        ");
        $totalMachines = $indexStats[0]->CARDINALITY ?? 0;
        $this->line(number_format($totalMachines).' (from index)');

        // Check archive stats if table exists
        $archiveStats = $this->getArchiveStats();

        // Calculate estimates
        $archivedCount      = $archiveStats['total_archives'] ?? 0;
        $archivableMachines = max(0, $totalMachines - $activeMachines - $archivedCount);

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Days Inactive Threshold', $daysInactive],
            ['Cutoff Date', $cutoffDate->format('Y-m-d H:i:s')],
            ['Total Machines (est.)', number_format($totalMachines)],
            ['Active Machines', number_format($activeMachines)],
            ['Already Archived', number_format($archivedCount)],
            ['Archivable (est.)', number_format($archivableMachines)],
        ]);

        if ($archivedCount > 0) {
            $this->newLine();
            $this->info('Archive Statistics:');
            $this->table(['Metric', 'Value'], [
                ['Total Archives', number_format($archiveStats['total_archives'])],
                ['Events Archived', number_format($archiveStats['total_events_archived'])],
                ['Space Saved', ($archiveStats['total_space_saved_mb'] ?? 0).' MB'],
                ['Avg Compression', round(($archiveStats['average_compression_ratio'] ?? 0) * 100, 1).'%'],
            ]);
        }

        // Estimate time
        if ($archivableMachines > 0) {
            $batchSize       = (int) $this->option('batch-size');
            $batches         = ceil($archivableMachines / $batchSize);
            $estTimePerBatch = 15; // seconds
            $totalSeconds    = $batches * ($estTimePerBatch + 5); // +5s delay

            $this->newLine();
            $this->info('Estimated Archival Time:');
            $this->table(['Parameter', 'Value'], [
                ['Batch Size', $batchSize],
                ['Total Batches', number_format($batches)],
                ['Est. Time/Batch', $estTimePerBatch.'s'],
                ['Est. Total Time', $this->formatDuration($totalSeconds)],
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Dispatch archival job to queue (recommended for production).
     */
    protected function dispatchToQueue(array $config): int
    {
        $batchSize = (int) $this->option('batch-size');
        $once      = $this->option('once');

        $this->info('Dispatching archival job to queue...');
        $this->table(['Setting', 'Value'], [
            ['Batch Size', $batchSize],
            ['Mode', $once ? 'Single batch' : 'Continuous (self-dispatch)'],
            ['Queue', $config['advanced']['queue'] ?? 'default'],
        ]);

        if ($once) {
            // Single batch, no self-dispatch
            ArchiveMachineEventsJob::dispatch($batchSize, null, array_merge($config, ['once' => true]));
        } else {
            // Continuous with self-dispatch
            ArchiveMachineEventsJob::dispatch($batchSize, null, $config);
        }

        $this->newLine();
        $this->info('✓ Job dispatched. Monitor queue workers for progress.');
        $this->line('  Logs: storage/logs/laravel.log');

        return self::SUCCESS;
    }

    /**
     * Run archival synchronously (for small datasets or testing).
     */
    protected function runSynchronous(array $config): int
    {
        $batchSize = (int) $this->option('batch-size');

        $this->warn('Running synchronously. For production, use --queue');
        $this->newLine();

        if (!$this->option('force')) {
            if (!$this->confirm('This may take a long time on large datasets. Continue?')) {
                return self::SUCCESS;
            }
        }

        $job = new ArchiveMachineEventsJob($batchSize, null, $config);

        try {
            $job->handle();
            $this->info('✓ Archival batch completed.');
        } catch (\Exception $e) {
            $this->error('Archival failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function getArchiveStats(): array
    {
        try {
            $stats = DB::table('machine_event_archives')
                ->selectRaw('
                    COUNT(*) as total_archives,
                    COALESCE(SUM(event_count), 0) as total_events_archived,
                    COALESCE(SUM(original_size - compressed_size), 0) as total_space_saved,
                    COALESCE(AVG(compressed_size / NULLIF(original_size, 0)), 0) as average_compression_ratio
                ')
                ->first();

            return [
                'total_archives'            => (int) ($stats->total_archives ?? 0),
                'total_events_archived'     => (int) ($stats->total_events_archived ?? 0),
                'total_space_saved_mb'      => round(($stats->total_space_saved ?? 0) / 1024 / 1024, 2),
                'average_compression_ratio' => (float) ($stats->average_compression_ratio ?? 0),
            ];
        } catch (\Exception $e) {
            // Table doesn't exist yet
            return [
                'total_archives'            => 0,
                'total_events_archived'     => 0,
                'total_space_saved_mb'      => 0,
                'average_compression_ratio' => 0,
            ];
        }
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' seconds';
        }
        if ($seconds < 3600) {
            return round($seconds / 60, 1).' minutes';
        }

        return round($seconds / 3600, 1).' hours';
    }
}
