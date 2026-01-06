<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;

class ArchiveStatusCommand extends Command
{
    protected $signature = 'machine:archive-status
                           {--machine-id= : Show details for specific machine ID}
                           {--restore= : Restore archived events for specific root_event_id}
                           {--cleanup-archive= : Delete archived events for specific root_event_id}';
    protected $description = 'Show archival status and manage archived machine events';

    public function handle(): int
    {
        if ($machineId = $this->option('machine-id')) {
            return $this->showMachineDetails($machineId);
        }

        if ($rootEventId = $this->option('restore')) {
            return $this->restoreEvents($rootEventId);
        }

        if ($rootEventId = $this->option('cleanup-archive')) {
            return $this->cleanupArchive($rootEventId);
        }

        return $this->showOverallStatus();
    }

    protected function showOverallStatus(): int
    {
        $this->info('Machine Events Archive Status');
        $this->info('=============================');

        // Overall statistics
        $activeEvents   = MachineEvent::count();
        $activeMachines = MachineEvent::distinct('root_event_id')->count();

        $archivedMachines    = MachineEventArchive::count();
        $totalArchivedEvents = MachineEventArchive::sum('event_count');
        $totalOriginalSize   = MachineEventArchive::sum('original_size');
        $totalCompressedSize = MachineEventArchive::sum('compressed_size');

        $compressionRatio = $totalOriginalSize > 0 ? $totalCompressedSize / $totalOriginalSize : 0;
        $savings          = $totalOriginalSize - $totalCompressedSize;

        $this->table(['Metric', 'Active Events', 'Archived Events'], [
            ['Machines', number_format($activeMachines), number_format($archivedMachines)],
            ['Events', number_format($activeEvents), number_format($totalArchivedEvents)],
            ['Storage Size', '-', $this->formatBytes($totalCompressedSize)],
            ['Original Size', '-', $this->formatBytes($totalOriginalSize)],
            ['Compression Ratio', '-', round($compressionRatio * 100, 1).'%'],
            ['Space Saved', '-', $this->formatBytes($savings)],
        ]);

        // Recent archival activity
        $recentArchives = MachineEventArchive::query()
            ->latest('archived_at')
            ->limit(10)
            ->get(['machine_id', 'event_count', 'compression_level', 'savings_percent', 'archived_at', 'restore_count', 'last_restored_at']);

        if ($recentArchives->isNotEmpty()) {
            $this->newLine();
            $this->info('Recent Archival Activity (Last 10)');
            $this->table(
                ['Machine ID', 'Events', 'Compression', 'Savings %', 'Restores', 'Archived At'],
                $recentArchives->map(function ($archive): array {
                    return [
                        $archive->machine_id,
                        $archive->event_count,
                        "Level {$archive->compression_level}",
                        round($archive->savings_percent, 1).'%',
                        $archive->restore_count,
                        $archive->archived_at->format('Y-m-d H:i:s'),
                    ];
                })->all()
            );
        }

        // Show archives with recent restoration activity
        $recentRestores = MachineEventArchive::query()
            ->whereNotNull('last_restored_at')
            ->latest('last_restored_at')
            ->limit(5)
            ->get(['machine_id', 'restore_count', 'last_restored_at', 'archived_at']);

        if ($recentRestores->isNotEmpty()) {
            $this->newLine();
            $this->info('Recent Restore Activity (Last 5)');
            $this->table(
                ['Machine ID', 'Total Restores', 'Last Restored', 'Originally Archived'],
                $recentRestores->map(function ($archive): array {
                    return [
                        $archive->machine_id,
                        $archive->restore_count,
                        $archive->last_restored_at->format('Y-m-d H:i:s'),
                        $archive->archived_at->format('Y-m-d H:i:s'),
                    ];
                })->all()
            );
        }

        return self::SUCCESS;
    }

    protected function showMachineDetails(string $machineId): int
    {
        $archives = MachineEventArchive::forMachine($machineId)
            ->orderBy('archived_at', 'desc')
            ->get();

        if ($archives->isEmpty()) {
            $this->info("No archived events found for machine: {$machineId}");

            // Check if machine has active events
            $activeEvents = MachineEvent::where('machine_id', $machineId)->count();
            if ($activeEvents > 0) {
                $this->info("Machine has {$activeEvents} active events that haven't been archived yet.");
            }

            return self::SUCCESS;
        }

        $this->info("Archived Events for Machine: {$machineId}");
        $this->info(str_repeat('=', 50));

        $this->table(
            ['Root Event ID', 'Events', 'Original Size', 'Compressed Size', 'Savings %', 'Restores', 'Last Restored'],
            $archives->map(function ($archive): array {
                return [
                    substr((string) $archive->root_event_id, 0, 8).'...',
                    $archive->event_count,
                    $this->formatBytes($archive->original_size),
                    $this->formatBytes($archive->compressed_size),
                    round($archive->savings_percent, 1).'%',
                    $archive->restore_count,
                    $archive->last_restored_at ? $archive->last_restored_at->format('Y-m-d H:i:s') : 'Never',
                ];
            })->toArray()
        );

        $totalEvents  = $archives->sum('event_count');
        $totalSavings = $archives->sum('original_size') - $archives->sum('compressed_size');

        $this->newLine();
        $this->info("Total: {$totalEvents} events archived, ".$this->formatBytes($totalSavings).' saved');

        return self::SUCCESS;
    }

    protected function restoreEvents(string $rootEventId): int
    {
        $archive = MachineEventArchive::find($rootEventId);

        if (!$archive) {
            $this->error("No archived events found for root_event_id: {$rootEventId}");

            return self::FAILURE;
        }

        if (!$this->confirm("Restore {$archive->event_count} events for machine {$archive->machine_id}?")) {
            $this->info('Restore cancelled.');

            return self::SUCCESS;
        }

        $archiveService = new ArchiveService();

        try {
            DB::transaction(function () use ($archiveService, $rootEventId): void {
                // Restore events using ArchiveService (this will track restoration)
                $events = $archiveService->restoreMachine($rootEventId, false); // Don't keep archive

                // Re-create active events
                foreach ($events as $event) {
                    MachineEvent::create($event->toArray());
                }
            });

            $this->info("Successfully restored {$archive->event_count} events and deleted archive.");

        } catch (\Exception $e) {
            $this->error("Failed to restore events: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function cleanupArchive(string $rootEventId): int
    {
        $archive = MachineEventArchive::find($rootEventId);

        if (!$archive) {
            $this->error("No archived events found for root_event_id: {$rootEventId}");

            return self::FAILURE;
        }

        if (!$this->confirm("Permanently delete archived events for machine {$archive->machine_id}? This cannot be undone.")) {
            $this->info('Cleanup cancelled.');

            return self::SUCCESS;
        }

        $archive->delete();
        $this->info('Archived events permanently deleted.');

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
