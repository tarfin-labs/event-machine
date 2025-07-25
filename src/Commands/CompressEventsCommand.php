<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Support\CompressionManager;

class CompressEventsCommand extends Command
{
    protected $signature = 'machine:compress-events
                           {--chunk-size=1000 : Number of records to process in each batch}
                           {--dry-run : Preview compression statistics without making changes}
                           {--force : Skip confirmation prompt}';
    protected $description = 'Compress existing machine event data for storage optimization';

    public function handle(): int
    {
        if (!CompressionManager::isEnabled()) {
            $this->error('Compression is disabled in configuration. Enable it first.');

            return self::FAILURE;
        }

        $this->info('Machine Events Compression Tool');
        $this->info('====================================');

        if ($this->option('dry-run')) {
            return $this->dryRun();
        }

        if (!$this->option('force') && !$this->confirm('This will modify existing machine event data. Continue?')) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        return $this->compressEvents();
    }

    protected function dryRun(): int
    {
        $this->info('Running compression analysis (dry run)...');

        $chunkSize           = (int) $this->option('chunk-size');
        $totalRecords        = 0;
        $totalOriginalSize   = 0;
        $totalCompressedSize = 0;
        $compressibleRecords = 0;

        MachineEvent::select(['id', 'payload', 'context', 'meta'])
            ->whereNotNull('payload')
            ->orWhereNotNull('context')
            ->orWhereNotNull('meta')
            ->chunk($chunkSize, function ($events) use (&$totalRecords, &$totalOriginalSize, &$totalCompressedSize, &$compressibleRecords): void {
                foreach ($events as $event) {
                    $totalRecords++;

                    foreach (['payload', 'context', 'meta'] as $field) {
                        if ($event->$field !== null) {
                            // Check if already compressed
                            $rawValue = $event->getAttributes()[$field] ?? $event->$field;

                            if (CompressionManager::isCompressed($rawValue)) {
                                continue; // Already compressed
                            }

                            $stats = CompressionManager::getCompressionStats($event->$field);
                            $totalOriginalSize += $stats['original_size'];
                            $totalCompressedSize += $stats['compressed_size'];

                            if ($stats['compressed']) {
                                $compressibleRecords++;
                            }
                        }
                    }
                }

                $this->info("Analyzed {$totalRecords} records...");
            });

        $this->table(['Metric', 'Value'], [
            ['Total Records', number_format($totalRecords)],
            ['Compressible Records', number_format($compressibleRecords)],
            ['Original Size', $this->formatBytes($totalOriginalSize)],
            ['Compressed Size', $this->formatBytes($totalCompressedSize)],
            ['Space Savings', $this->formatBytes($totalOriginalSize - $totalCompressedSize)],
            ['Compression Ratio', round(($totalOriginalSize > 0 ? $totalCompressedSize / $totalOriginalSize : 1) * 100, 2).'%'],
        ]);

        return self::SUCCESS;
    }

    protected function compressEvents(): int
    {
        $chunkSize       = (int) $this->option('chunk-size');
        $totalProcessed  = 0;
        $totalCompressed = 0;
        $totalSkipped    = 0;

        $this->info("Starting compression with chunk size: {$chunkSize}");

        $progressBar = $this->output->createProgressBar(
            MachineEvent::whereNotNull('payload')
                ->orWhereNotNull('context')
                ->orWhereNotNull('meta')
                ->count()
        );

        MachineEvent::select(['id', 'payload', 'context', 'meta'])
            ->whereNotNull('payload')
            ->orWhereNotNull('context')
            ->orWhereNotNull('meta')
            ->chunk($chunkSize, function ($events) use (&$totalProcessed, &$totalCompressed, &$totalSkipped, $progressBar): void {
                $updates = [];

                foreach ($events as $event) {
                    $update     = ['id' => $event->id];
                    $hasChanges = false;

                    foreach (['payload', 'context', 'meta'] as $field) {
                        if ($event->$field !== null) {
                            // Get raw value from database
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
                    $progressBar->advance();
                }

                // Batch update records
                if (!empty($updates)) {
                    foreach ($updates as $update) {
                        DB::table('machine_events')
                            ->where('id', $update['id'])
                            ->update(array_filter($update, fn ($key) => $key !== 'id', ARRAY_FILTER_USE_KEY));
                    }
                }
            });

        $progressBar->finish();
        $this->newLine(2);

        $this->table(['Metric', 'Value'], [
            ['Total Processed', number_format($totalProcessed)],
            ['Records Compressed', number_format($totalCompressed)],
            ['Records Skipped', number_format($totalSkipped)],
        ]);

        $this->info('Compression completed successfully!');

        return self::SUCCESS;
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;

        return number_format($bytes / pow(1024, $power), 2).' '.$units[$power];
    }
}
