<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Support\CompressionManager;

class CompressMachineEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes
    public int $tries   = 3;

    public function __construct(
        public readonly int $chunkSize = 1000,
        public readonly ?string $startFromId = null
    ) {}

    public function handle(): void
    {
        if (!CompressionManager::isEnabled()) {
            Log::warning('Machine event compression job skipped - compression is disabled');

            return;
        }

        $processed = $this->compressEventsBatch();

        Log::info('Machine event compression batch completed', [
            'processed'     => $processed['total'],
            'compressed'    => $processed['compressed'],
            'skipped'       => $processed['skipped'],
            'chunk_size'    => $this->chunkSize,
            'start_from_id' => $this->startFromId,
        ]);

        // Dispatch next batch if there are more records to process
        if ($processed['has_more']) {
            self::dispatch($this->chunkSize, $processed['last_id']);
        }
    }

    protected function compressEventsBatch(): array
    {
        $totalProcessed  = 0;
        $totalCompressed = 0;
        $totalSkipped    = 0;
        $lastProcessedId = null;
        $hasMore         = false;

        $query = MachineEvent::select(['id', 'payload', 'context', 'meta'])
            ->where(function ($query): void {
                $query->whereNotNull('payload')
                    ->orWhereNotNull('context')
                    ->orWhereNotNull('meta');
            })
            ->orderBy('id');

        if ($this->startFromId) {
            $query->where('id', '>', $this->startFromId);
        }

        $events = $query->limit($this->chunkSize + 1)->get();

        // Check if there are more records after this batch
        if ($events->count() > $this->chunkSize) {
            $hasMore = true;
            $events  = $events->take($this->chunkSize);
        }

        $updates = [];

        foreach ($events as $event) {
            $update     = ['id' => $event->id];
            $hasChanges = false;

            foreach (['payload', 'context', 'meta'] as $field) {
                if ($event->$field !== null) {
                    // Get raw value from database to check if already compressed
                    $rawValue = $event->getAttributes()[$field];

                    // Skip if already compressed
                    if (CompressionManager::isCompressed($rawValue)) {
                        continue;
                    }

                    // Compress the data
                    $compressed = CompressionManager::compress($event->$field, $field);

                    if ($compressed !== $rawValue) {
                        $update[$field] = $compressed;
                        $hasChanges     = true;
                    }
                }
            }

            if ($hasChanges) {
                $updates[] = $update;
                $totalCompressed++;
            } else {
                $totalSkipped++;
            }

            $totalProcessed++;
            $lastProcessedId = $event->id;
        }

        // Batch update records
        if (!empty($updates)) {
            DB::transaction(function () use ($updates): void {
                foreach ($updates as $update) {
                    DB::table('machine_events')
                        ->where('id', $update['id'])
                        ->update(array_filter($update, fn ($key) => $key !== 'id', ARRAY_FILTER_USE_KEY));
                }
            });
        }

        return [
            'total'      => $totalProcessed,
            'compressed' => $totalCompressed,
            'skipped'    => $totalSkipped,
            'last_id'    => $lastProcessedId,
            'has_more'   => $hasMore,
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Machine event compression job failed', [
            'exception'     => $exception->getMessage(),
            'trace'         => $exception->getTraceAsString(),
            'chunk_size'    => $this->chunkSize,
            'start_from_id' => $this->startFromId,
        ]);
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [30, 60, 120];
    }
}
