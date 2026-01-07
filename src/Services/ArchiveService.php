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
     * Get machine instances eligible for archival based on days inactive.
     * Optimized for large tables using NOT EXISTS pattern.
     *
     * @return Collection<int, MachineEvent> Collection of eligible root_event_ids with machine_id
     */
    public function getEligibleInstances(int $limit = 100): Collection
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
            // Exclude instances with recent activity (NOT EXISTS is index-friendly)
            ->whereNotExists(function ($subQuery) use ($cutoffDate): void {
                $subQuery->select(DB::raw(1))
                    ->from('machine_events as recent')
                    ->whereColumn('recent.root_event_id', 'machine_events.root_event_id')
                    ->where('recent.created_at', '>=', $cutoffDate);
            });

        // Apply cooldown logic - exclude recently restored instances
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
     * Batch archive multiple machine instances.
     * Events are always moved to archive (removed from active table after successful archival).
     *
     * @param  array<string>  $rootEventIds  Array of root_event_ids to archive
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

    /**
     * Restore events from archive to machine_events and delete the archive.
     * Used for auto-restore when new events arrive for an archived machine.
     */
    public function restoreAndDelete(string $rootEventId): bool
    {
        return DB::transaction(function () use ($rootEventId): bool {
            $archive = MachineEventArchive::lockForUpdate()
                ->where('root_event_id', $rootEventId)
                ->first();

            if (!$archive) {
                return false;
            }

            // Restore events to machine_events table
            // Using insert() to bypass model events (avoid infinite loop)
            $events = $archive->restoreEvents();

            // Use getAttributes() to get raw database-ready values:
            // - datetime fields stay in MySQL format (not ISO8601)
            // - JSON fields stay as JSON strings (not PHP arrays)
            $insertData = $events->map(fn ($event) => $event->getAttributes())->all();

            MachineEvent::insert($insertData);

            // Delete the archive
            $archive->delete();

            return true;
        });
    }
}
