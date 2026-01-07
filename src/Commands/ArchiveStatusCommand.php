<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;

/**
 * Simple archive status and operations command.
 *
 * Shows archive summary and provides restore/cleanup operations.
 */
class ArchiveStatusCommand extends Command
{
    protected $signature = 'machine:archive-status
                           {--restore= : Restore archived events for specific root_event_id}';
    protected $description = 'Show archive summary and manage archived machine events';

    public function handle(): int
    {
        if ($rootEventId = $this->option('restore')) {
            return $this->restoreArchive($rootEventId);
        }

        return $this->showSummary();
    }

    /**
     * Show simple archive summary.
     */
    protected function showSummary(): int
    {
        $activeCount  = MachineEvent::distinct('root_event_id')->count();
        $activeEvents = MachineEvent::count();

        $stats = MachineEventArchive::query()
            ->selectRaw('
                COUNT(*) as count,
                COALESCE(SUM(event_count), 0) as events,
                COALESCE(SUM(original_size), 0) as original_total,
                COALESCE(SUM(compressed_size), 0) as compressed_total
            ')
            ->first();

        $archiveCount   = (int) $stats->count;
        $archiveEvents  = (int) $stats->events;
        $originalSize   = (int) $stats->original_total;
        $compressedSize = (int) $stats->compressed_total;
        $saved          = $originalSize - $compressedSize;
        $ratio          = $originalSize > 0 ? round(($saved / $originalSize) * 100, 1) : 0;

        $this->info('Machine Events Archive Status');
        $this->newLine();

        $this->table(
            ['', 'Instances', 'Events', 'Size'],
            [
                ['Active', number_format($activeCount), number_format($activeEvents), '-'],
                ['Archived', number_format($archiveCount), number_format($archiveEvents), $this->formatBytes($compressedSize)],
            ]
        );

        if ($archiveCount > 0) {
            $this->newLine();
            $this->line("Compression: {$ratio}% saved ({$this->formatBytes($saved)})");
        }

        return self::SUCCESS;
    }

    /**
     * Restore events from archive.
     */
    protected function restoreArchive(string $rootEventId): int
    {
        $archive = MachineEventArchive::find($rootEventId);

        if (!$archive) {
            $this->error("Archive not found: {$rootEventId}");

            return self::FAILURE;
        }

        if (!$this->confirm("Restore {$archive->event_count} events for {$archive->machine_id}?")) {
            return self::SUCCESS;
        }

        $eventCount = $archive->event_count;

        try {
            $archiveService = new ArchiveService();
            $result         = $archiveService->restoreAndDelete($rootEventId);

            if ($result) {
                $this->info("Restored {$eventCount} events.");

                return self::SUCCESS;
            }

            $this->error('Restore failed: archive may have been already restored.');

            return self::FAILURE;

        } catch (\Throwable $e) {
            $this->error("Restore failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
