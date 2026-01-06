<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Jobs\ArchiveMachineEventsJob;

class ArchiveEventsCommand extends Command
{
    protected $signature = 'machine:archive-events
                           {--batch-size=100 : Number of machines to process in each batch}
                           {--dry-run : Preview archival statistics without making changes}
                           {--force : Skip confirmation prompt}
                           {--queue : Dispatch archival to queue instead of running synchronously}';
    protected $description = 'Archive machine events to separate compressed storage';

    public function handle(): int
    {
        $config = config('machine.archival', []);

        if (!($config['enabled'] ?? true)) {
            $this->error('Archival is disabled in configuration. Enable it in config/machine.php');

            return self::FAILURE;
        }

        $this->info('Machine Events Archival Tool');
        $this->info('=============================');

        if ($this->option('dry-run')) {
            return $this->dryRun($config);
        }

        if ($this->option('queue')) {
            return $this->dispatchToQueue($config);
        }

        return $this->runArchival($config);
    }

    protected function dryRun(array $config): int
    {
        $this->info('Running archival analysis (dry run)...');

        $archiveService    = new ArchiveService($config);
        $qualifiedMachines = $archiveService->getEligibleMachines(1000); // Get all eligible
        $qualifiedCount    = $qualifiedMachines->count();

        if ($qualifiedCount === 0) {
            $this->info('No machines qualify for archival based on current configuration.');

            // Show current archive stats
            $stats = $archiveService->getArchiveStats();
            if ($stats['enabled'] && $stats['total_archives'] > 0) {
                $this->newLine();
                $this->info('Current Archive Statistics:');
                $this->table(['Metric', 'Value'], [
                    ['Total Archives', number_format($stats['total_archives'])],
                    ['Total Events Archived', number_format($stats['total_events_archived'])],
                    ['Space Saved', $stats['total_space_saved_mb'].' MB'],
                    ['Average Compression', round($stats['average_compression_ratio'] * 100, 1).'%'],
                ]);
            }

            return self::SUCCESS;
        }

        // Get statistics
        $totalEvents = DB::table('machine_events')
            ->whereIn('root_event_id', function ($query): void {
                $query->select('root_event_id')
                    ->from('machine_events')
                    ->whereNotIn('root_event_id', function ($subQuery): void {
                        $subQuery->select('root_event_id')
                            ->from('machine_event_archives');
                    })
                    ->groupBy('root_event_id');
            })
            ->count();

        $estimatedSize = DB::table('machine_events')
            ->whereIn('root_event_id', function ($query): void {
                $query->select('root_event_id')
                    ->from('machine_events')
                    ->whereNotIn('root_event_id', function ($subQuery): void {
                        $subQuery->select('root_event_id')
                            ->from('machine_event_archives');
                    })
                    ->groupBy('root_event_id');
            })
            ->selectRaw('SUM(LENGTH(JSON_EXTRACT(payload, "$")) + LENGTH(JSON_EXTRACT(context, "$")) + LENGTH(JSON_EXTRACT(meta, "$"))) as total_size')
            ->value('total_size') ?? 0;

        $this->table(['Metric', 'Value'], [
            ['Qualified Machines', number_format($qualifiedCount)],
            ['Total Events', number_format($totalEvents)],
            ['Estimated Data Size', $this->formatBytes($estimatedSize)],
            ['Compression Level', $config['level'] ?? 6],
            ['Estimated Compressed Size', $this->formatBytes($estimatedSize * 0.15)], // ~85% compression typical
            ['Estimated Savings', $this->formatBytes($estimatedSize * 0.85)],
        ]);

        return self::SUCCESS;
    }

    protected function dispatchToQueue(array $config): int
    {
        $batchSize = (int) $this->option('batch-size');

        $this->info('Dispatching archival job to queue...');

        ArchiveMachineEventsJob::withConfig($config, $batchSize)->dispatch();

        $this->info('Archival job dispatched successfully.');
        $this->info('Monitor your queue workers to track progress.');

        return self::SUCCESS;
    }

    protected function runArchival(array $config): int
    {
        $qualifiedCount = ArchiveMachineEventsJob::getQualifiedMachinesCount($config);

        if ($qualifiedCount === 0) {
            $this->info('No machines qualify for archival based on current configuration.');

            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm("Archive {$qualifiedCount} machines and move them to compressed storage? Continue?")) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $batchSize = (int) $this->option('batch-size');

        $this->info('Starting archival process...');
        $progressBar = $this->output->createProgressBar($qualifiedCount);

        $job = new ArchiveMachineEventsJob($batchSize, $config);

        try {
            $job->handle();
            $progressBar->finish();
            $this->newLine(2);
            $this->info('Archival completed successfully.');
        } catch (\Exception $e) {
            $progressBar->finish();
            $this->newLine(2);
            $this->error("Archival failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
