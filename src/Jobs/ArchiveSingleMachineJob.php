<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Models\MachineEventArchive;

/**
 * Archives a single machine instance (all events for one root_event_id).
 *
 * Designed to be dispatched by ArchiveEventsCommand (fan-out pattern).
 * Each job processes exactly one machine, enabling parallel processing
 * across multiple queue workers.
 */
class ArchiveSingleMachineJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** The number of seconds the job can run before timing out. */
    public int $timeout = 300;

    /** The number of times the job may be attempted. */
    public int $tries = 3;

    /** The number of seconds to wait before retrying the job. */
    public int $backoff = 60;

    /** Unique lock duration in seconds. */
    public int $uniqueFor = 600;

    public function __construct(
        protected string $rootEventId
    ) {
        $this->configureQueue();
    }

    /**
     * Unique key prevents concurrent archival of same machine.
     */
    public function uniqueId(): string
    {
        return 'archive-'.$this->rootEventId;
    }

    public function handle(): void
    {
        $config = config('machine.archival', []);

        if (!($config['enabled'] ?? true)) {
            return;
        }

        // Check if already archived (race condition protection)
        if (MachineEventArchive::where('root_event_id', $this->rootEventId)->exists()) {
            logger()->debug('ArchiveSingleMachineJob: Already archived', [
                'root_event_id' => $this->rootEventId,
            ]);

            return;
        }

        DB::transaction(function () use ($config): void {
            $this->archiveMachine($config);
        });
    }

    protected function archiveMachine(array $config): void
    {
        $archiveService   = new ArchiveService($config);
        $compressionLevel = $config['level'] ?? 6;
        $archive          = $archiveService->archiveMachine($this->rootEventId, $compressionLevel);

        if ($archive instanceof \Tarfinlabs\EventMachine\Models\MachineEventArchive) {
            logger()->info('ArchiveSingleMachineJob: Archived machine', [
                'root_event_id'     => $this->rootEventId,
                'machine_id'        => $archive->machine_id,
                'event_count'       => $archive->event_count,
                'compression_ratio' => $archive->compression_ratio,
            ]);
        }
    }

    protected function configureQueue(): void
    {
        $queue = config('machine.archival.advanced.queue');

        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        logger()->error('ArchiveSingleMachineJob: Failed to archive machine', [
            'root_event_id' => $this->rootEventId,
            'error'         => $exception->getMessage(),
        ]);
    }
}
