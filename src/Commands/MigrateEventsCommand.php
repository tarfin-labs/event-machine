<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Jobs\MigrateMachineEventsJob;

class MigrateEventsCommand extends Command
{
    protected $signature = 'machine:migrate-events
                           {--chunk-size=5000 : Number of records to process in each batch}
                           {--dry-run : Preview migration statistics without making changes}
                           {--force : Skip confirmation prompt}
                           {--queue : Process migration in background using jobs}';
    protected $description = 'Migrate existing machine event data from JSON columns to new LONGBLOB columns';

    public function handle(): int
    {
        $this->info('Machine Events Migration Tool (v2 to v3)');
        $this->info('========================================');

        // Check if migration is needed
        if (!$this->migrationNeeded()) {
            $this->info('No migration needed. The old JSON columns do not exist.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun();
        }

        if (!$this->option('force') && !$this->confirm('This will migrate existing machine event data to new columns. Continue?')) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            return $this->queueMigration();
        }

        return $this->migrateEvents();
    }

    protected function migrationNeeded(): bool
    {
        $columns     = DB::select('SHOW COLUMNS FROM machine_events');
        $columnNames = array_column($columns, 'Field');

        // Check if old columns (payload_json, context_json, meta_json) exist
        $oldColumns    = ['payload_json', 'context_json', 'meta_json'];
        $hasOldColumns = count(array_intersect($oldColumns, $columnNames)) > 0;

        // Also check if we still have the original columns that need migration
        $originalColumns    = ['payload', 'context', 'meta'];
        $hasOriginalColumns = count(array_intersect($originalColumns, $columnNames)) === 3;

        // Need migration if we have original columns but also have compressed columns
        $compressedColumns    = ['payload_compressed', 'context_compressed', 'meta_compressed'];
        $hasCompressedColumns = count(array_intersect($compressedColumns, $columnNames)) > 0;

        return $hasOriginalColumns && $hasCompressedColumns;
    }

    protected function dryRun(): int
    {
        $this->info('Running migration analysis (dry run)...');

        $totalRecords     = DB::table('machine_events')->count();
        $recordsToMigrate = DB::table('machine_events')
            ->whereNotNull('payload')
            ->orWhereNotNull('context')
            ->orWhereNotNull('meta')
            ->count();

        // Calculate approximate sizes
        $sampleSize    = min(1000, $totalRecords);
        $sampleRecords = DB::table('machine_events')
            ->select(['payload', 'context', 'meta'])
            ->whereNotNull('payload')
            ->orWhereNotNull('context')
            ->orWhereNotNull('meta')
            ->limit($sampleSize)
            ->get();

        $totalSize = 0;
        foreach ($sampleRecords as $record) {
            $totalSize += strlen($record->payload ?? '');
            $totalSize += strlen($record->context ?? '');
            $totalSize += strlen($record->meta ?? '');
        }

        $avgSizePerRecord   = $sampleSize > 0 ? $totalSize / $sampleSize : 0;
        $estimatedTotalSize = $avgSizePerRecord * $recordsToMigrate;

        $this->table(['Metric', 'Value'], [
            ['Total Records', number_format($totalRecords)],
            ['Records to Migrate', number_format($recordsToMigrate)],
            ['Sample Size', number_format($sampleSize)],
            ['Avg Size per Record', $this->formatBytes((int) $avgSizePerRecord)],
            ['Estimated Total Size', $this->formatBytes((int) $estimatedTotalSize)],
            ['Chunk Size', number_format((int) $this->option('chunk-size'))],
            ['Estimated Chunks', number_format(ceil($recordsToMigrate / (int) $this->option('chunk-size')))],
        ]);

        return self::SUCCESS;
    }

    protected function queueMigration(): int
    {
        $this->info('Dispatching migration job to queue...');

        MigrateMachineEventsJob::dispatch(
            chunkSize: (int) $this->option('chunk-size')
        );

        $this->info('Migration job dispatched successfully!');
        $this->info('Monitor progress in your queue worker logs.');

        return self::SUCCESS;
    }

    protected function migrateEvents(): int
    {
        $chunkSize     = (int) $this->option('chunk-size');
        $totalMigrated = 0;

        $this->info("Starting migration with chunk size: {$chunkSize}");

        $progressBar = $this->output->createProgressBar(
            DB::table('machine_events')
                ->whereNotNull('payload')
                ->orWhereNotNull('context')
                ->orWhereNotNull('meta')
                ->count()
        );

        DB::table('machine_events')
            ->select(['id', 'payload', 'context', 'meta'])
            ->whereNotNull('payload')
            ->orWhereNotNull('context')
            ->orWhereNotNull('meta')
            ->orderBy('id')
            ->chunk($chunkSize, function ($events) use (&$totalMigrated, $progressBar): void {
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

                    $updates[] = $update;
                }

                // Batch update records
                DB::transaction(function () use ($updates): void {
                    foreach ($updates as $update) {
                        DB::table('machine_events')
                            ->where('id', $update['id'])
                            ->update(array_filter($update, fn ($key) => $key !== 'id', ARRAY_FILTER_USE_KEY));
                    }
                });

                $totalMigrated += count($updates);
                $progressBar->advance(count($updates));
            });

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Migration completed. Total records migrated: {$totalMigrated}");

        $this->newLine();
        $this->info('Next steps:');
        $this->info('1. Run the second part of the migration to drop old columns');
        $this->info('2. Run: php artisan machine:compress-events to compress the data');

        return self::SUCCESS;
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;

        return number_format($bytes / pow(1024, $power), 2).' '.$units[$power];
    }
}
