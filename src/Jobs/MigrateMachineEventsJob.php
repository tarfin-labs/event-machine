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

class MigrateMachineEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes
    public int $tries   = 3;

    public function __construct(
        public readonly int $chunkSize = 5000,
        public readonly ?string $startFromId = null
    ) {}

    public function handle(): void
    {
        // Check if migration is still needed
        if (!$this->migrationNeeded()) {
            Log::info('Machine event migration job skipped - migration already completed');

            return;
        }

        $processed = $this->migrateEventsBatch();

        Log::info('Machine event migration batch completed', [
            'migrated'      => $processed['total'],
            'chunk_size'    => $this->chunkSize,
            'start_from_id' => $this->startFromId,
            'last_id'       => $processed['last_id'],
        ]);

        // Dispatch next batch if there are more records to process
        if ($processed['has_more']) {
            self::dispatch($this->chunkSize, $processed['last_id']);
        } else {
            Log::info('Machine event migration completed - all records processed');
        }
    }

    protected function migrationNeeded(): bool
    {
        $columns     = DB::select('SHOW COLUMNS FROM machine_events');
        $columnNames = array_column($columns, 'Field');

        // Need migration if we have both original and compressed columns
        $originalColumns   = ['payload', 'context', 'meta'];
        $compressedColumns = ['payload_compressed', 'context_compressed', 'meta_compressed'];

        $hasOriginalColumns   = count(array_intersect($originalColumns, $columnNames)) === 3;
        $hasCompressedColumns = count(array_intersect($compressedColumns, $columnNames)) > 0;

        return $hasOriginalColumns && $hasCompressedColumns;
    }

    protected function migrateEventsBatch(): array
    {
        $totalMigrated   = 0;
        $lastProcessedId = null;
        $hasMore         = false;

        $query = DB::table('machine_events')
            ->select(['id', 'payload', 'context', 'meta'])
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
            $update = ['id' => $event->id];

            // Copy payload as-is (JSON string to binary column)
            if ($event->payload !== null) {
                $update['payload_compressed'] = $event->payload;
            }

            // Copy context as-is (JSON string to binary column)
            if ($event->context !== null) {
                $update['context_compressed'] = $event->context;
            }

            // Copy meta as-is (JSON string to binary column)
            if ($event->meta !== null) {
                $update['meta_compressed'] = $event->meta;
            }

            $updates[]       = $update;
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

            $totalMigrated = count($updates);
        }

        return [
            'total'    => $totalMigrated,
            'last_id'  => $lastProcessedId,
            'has_more' => $hasMore,
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Machine event migration job failed', [
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
