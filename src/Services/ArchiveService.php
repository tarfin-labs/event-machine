<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Services;

use Exception;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;

class ArchiveService
{
    public function __construct(
        protected array $config = []
    ) {
        $this->config = array_merge(
            config('machine.archival', []),
            $this->config
        );
    }

    /**
     * Archive events for a specific machine by root event ID.
     * Events are always moved to archive (removed from active table after successful archival).
     */
    public function archiveMachine(string $rootEventId, ?int $compressionLevel = null): ?MachineEventArchive
    {
        if (!$this->isArchivalEnabled()) {
            return null;
        }

        // Check if already archived
        if (MachineEventArchive::find($rootEventId)) {
            return null;
        }

        // Get all events for this machine
        $events = MachineEvent::query()
            ->where('root_event_id', $rootEventId)
            ->orderBy('sequence_number')
            ->get();

        if ($events->isEmpty()) {
            return null;
        }

        $eventCollection = new EventCollection($events->all());
        $compressionLevel ??= $this->config['level'] ?? 6;

        return DB::transaction(function () use ($eventCollection, $compressionLevel, $rootEventId): MachineEventArchive {
            try {
                // Create archive
                $archive = MachineEventArchive::archiveEvents($eventCollection, $compressionLevel);

                // Track restoration metadata
                $this->trackArchiveCreation($archive);

                // Always cleanup original events after successful archival
                MachineEvent::where('root_event_id', $rootEventId)->delete();

                return $archive;

            } catch (Exception $e) {
                logger()->error('Failed to archive machine events', [
                    'root_event_id' => $rootEventId,
                    'error'         => $e->getMessage(),
                ]);

                // Re-throw to trigger transaction rollback
                throw $e;
            }
        });
    }

    /**
     * Restore events from archive transparently.
     */
    public function restoreMachine(string $rootEventId, bool $keepArchive = true): ?EventCollection
    {
        $archive = MachineEventArchive::find($rootEventId);

        if (!$archive) {
            return null;
        }

        try {
            // Restore events
            $events = $archive->restoreEvents();

            // Track restoration
            $this->trackArchiveRestoration($archive);

            // Remove archive if requested
            if (!$keepArchive) {
                $archive->delete();
            }

            return $events;

        } catch (Exception $e) {
            logger()->error('Failed to restore machine events from archive', [
                'root_event_id' => $rootEventId,
                'error'         => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get machines eligible for archival based on days inactive.
     * Optimized for large tables using NOT EXISTS pattern.
     */
    public function getEligibleMachines(int $limit = 100): Collection
    {
        if (!$this->isArchivalEnabled()) {
            return collect();
        }

        $daysInactive = $this->config['days_inactive'] ?? 30;
        $cutoffDate   = Carbon::now()->subDays($daysInactive);

        $query = MachineEvent::query()
            ->select('root_event_id', 'machine_id')
            ->where('created_at', '<', $cutoffDate)
            // Exclude already archived
            ->whereNotIn('root_event_id', function ($subQuery): void {
                $subQuery->select('root_event_id')
                    ->from('machine_event_archives');
            })
            // Exclude machines with recent activity (NOT EXISTS is index-friendly)
            ->whereNotExists(function ($subQuery) use ($cutoffDate): void {
                $subQuery->select(DB::raw(1))
                    ->from('machine_events as recent')
                    ->whereColumn('recent.root_event_id', 'machine_events.root_event_id')
                    ->where('recent.created_at', '>=', $cutoffDate);
            });

        // Apply cooldown logic - exclude recently restored machines
        $cooldownHours = $this->config['restore_cooldown_hours'] ?? 24;
        if ($cooldownHours > 0) {
            $cooldownCutoff = Carbon::now()->subHours($cooldownHours);
            $query->whereNotIn('root_event_id', function ($subQuery) use ($cooldownCutoff): void {
                $subQuery->select('root_event_id')
                    ->from('machine_event_archives')
                    ->where('last_restored_at', '>', $cooldownCutoff);
            });
        }

        return $query->distinct()
            ->limit($limit)
            ->get();
    }

    /**
     * Get archive statistics.
     */
    public function getArchiveStats(): array
    {
        if (!$this->isArchivalEnabled()) {
            return [
                'enabled'                   => false,
                'total_archives'            => 0,
                'total_events_archived'     => 0,
                'total_space_saved'         => 0,
                'average_compression_ratio' => 0,
            ];
        }

        $stats = MachineEventArchive::query()
            ->selectRaw('
                COUNT(*) as total_archives,
                SUM(event_count) as total_events_archived,
                SUM(original_size - compressed_size) as total_space_saved,
                AVG(compressed_size / original_size) as average_compression_ratio
            ')
            ->first();

        return [
            'enabled'                   => true,
            'total_archives'            => $stats->total_archives ?? 0,
            'total_events_archived'     => $stats->total_events_archived ?? 0,
            'total_space_saved'         => $stats->total_space_saved ?? 0,
            'total_space_saved_mb'      => round(($stats->total_space_saved ?? 0) / 1024 / 1024, 2),
            'average_compression_ratio' => round($stats->average_compression_ratio ?? 0, 3),
            'space_savings_percent'     => $stats->total_space_saved > 0
                ? round((($stats->total_space_saved ?? 0) / (($stats->total_space_saved ?? 0) + DB::table('machine_event_archives')->sum('compressed_size'))) * 100, 1)
                : 0,
        ];
    }

    /**
     * Check if a machine can be re-archived based on cooldown settings.
     */
    public function canReArchive(string $rootEventId): bool
    {
        if (!$this->isArchivalEnabled()) {
            return false;
        }

        $archive = MachineEventArchive::find($rootEventId);

        if (!$archive || !$archive->last_restored_at) {
            return true;
        }

        $cooldownHours = $this->config['restore_cooldown_hours'] ?? 24;

        return $archive->last_restored_at->addHours($cooldownHours)->isPast();
    }

    /**
     * Batch archive multiple machines.
     * Events are always moved to archive (removed from active table after successful archival).
     */
    public function batchArchive(array $rootEventIds, ?int $compressionLevel = null): array
    {
        $results = [
            'archived' => [],
            'failed'   => [],
            'skipped'  => [],
        ];

        foreach ($rootEventIds as $rootEventId) {
            // Check cooldown
            if (!$this->canReArchive($rootEventId)) {
                $results['skipped'][] = [
                    'root_event_id' => $rootEventId,
                    'reason'        => 'cooldown_period_active',
                ];

                continue;
            }

            $archive = $this->archiveMachine($rootEventId, $compressionLevel);

            if ($archive instanceof MachineEventArchive) {
                $results['archived'][] = [
                    'root_event_id'     => $rootEventId,
                    'machine_id'        => $archive->machine_id,
                    'event_count'       => $archive->event_count,
                    'compression_ratio' => $archive->compression_ratio,
                ];
            } else {
                $results['failed'][] = [
                    'root_event_id' => $rootEventId,
                    'reason'        => 'archive_creation_failed',
                ];
            }
        }

        return $results;
    }

    /**
     * Clean up old archives based on retention policy.
     */
    public function cleanupOldArchives(): int
    {
        if (!$this->isArchivalEnabled()) {
            return 0;
        }

        $retentionDays = $this->config['archive_retention_days'] ?? null;

        if (!$retentionDays) {
            return 0;
        }

        $cutoffDate = Carbon::now()->subDays($retentionDays);

        return MachineEventArchive::where('archived_at', '<', $cutoffDate)->delete();
    }

    /**
     * Check if archival is enabled.
     */
    protected function isArchivalEnabled(): bool
    {
        return $this->config['enabled'] ?? true;
    }

    /**
     * Track archive creation metadata.
     */
    protected function trackArchiveCreation(MachineEventArchive $archive): void
    {
        // Set initial tracking metadata
        $archive->update([
            'restore_count'    => 0,
            'last_restored_at' => null,
        ]);
    }

    /**
     * Track archive restoration.
     */
    protected function trackArchiveRestoration(MachineEventArchive $archive): void
    {
        $archive->increment('restore_count');
        $archive->update(['last_restored_at' => Carbon::now()]);
    }
}
