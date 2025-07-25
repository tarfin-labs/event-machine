<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckUpgradeCommand extends Command
{
    protected $signature                  = 'machine:check-upgrade';
    protected $description                = 'Check your database and recommend the best upgrade path to EventMachine v3.0';
    protected int $smallDatasetThreshold  = 100000;
    protected int $mediumDatasetThreshold = 1000000;

    public function handle(): int
    {
        $this->info('EventMachine v3.0 Upgrade Checker');
        $this->info('=================================');
        $this->newLine();

        // Check if upgrade is needed
        if (!$this->upgradeNeeded()) {
            $this->info('✅ No upgrade needed. Your database schema is already up to date.');

            return self::SUCCESS;
        }

        // Analyze database
        $analysis = $this->analyzeDatabase();

        // Display analysis
        $this->displayAnalysis($analysis);

        // Recommend upgrade path
        $this->recommendUpgradePath($analysis);

        return self::SUCCESS;
    }

    protected function upgradeNeeded(): bool
    {
        $columns     = DB::select('SHOW COLUMNS FROM machine_events');
        $columnNames = array_column($columns, 'Field');

        // Check for JSON type columns (v2.x signature)
        foreach ($columns as $column) {
            if (in_array($column->Field, ['payload', 'context', 'meta'])) {
                // In v2.x these are JSON type, in v3.x they are LONGTEXT
                if (strtolower($column->Type) === 'json') {
                    return true;
                }
            }
        }

        return false;
    }

    protected function analyzeDatabase(): array
    {
        $totalRecords = DB::table('machine_events')->count();

        // Get table size
        $tableInfo = DB::select("
            SELECT 
                table_name,
                ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND table_name = 'machine_events'
        ");

        $tableSizeMB = $tableInfo[0]->size_mb ?? 0;

        // Sample data to estimate compression ratio
        $sampleSize     = min(1000, $totalRecords);
        $avgPayloadSize = 0;
        $avgContextSize = 0;
        $avgMetaSize    = 0;

        if ($totalRecords > 0) {
            $samples = DB::table('machine_events')
                ->select(DB::raw('
                    AVG(LENGTH(payload)) as avg_payload,
                    AVG(LENGTH(context)) as avg_context,
                    AVG(LENGTH(meta)) as avg_meta
                '))
                ->first();

            $avgPayloadSize = (int) ($samples->avg_payload ?? 0);
            $avgContextSize = (int) ($samples->avg_context ?? 0);
            $avgMetaSize    = (int) ($samples->avg_meta ?? 0);
        }

        // Estimate migration time (rough estimate)
        $recordsPerSecond = 5000; // Conservative estimate
        $estimatedSeconds = $totalRecords / $recordsPerSecond;

        return [
            'total_records'            => $totalRecords,
            'table_size_mb'            => $tableSizeMB,
            'avg_payload_size'         => $avgPayloadSize,
            'avg_context_size'         => $avgContextSize,
            'avg_meta_size'            => $avgMetaSize,
            'avg_record_size'          => $avgPayloadSize + $avgContextSize + $avgMetaSize,
            'estimated_migration_time' => $estimatedSeconds,
        ];
    }

    protected function displayAnalysis(array $analysis): void
    {
        $this->info('Database Analysis:');
        $this->table(['Metric', 'Value'], [
            ['Total Records', number_format($analysis['total_records'])],
            ['Table Size', $this->formatSize($analysis['table_size_mb'] * 1024 * 1024)],
            ['Avg Payload Size', $this->formatBytes($analysis['avg_payload_size'])],
            ['Avg Context Size', $this->formatBytes($analysis['avg_context_size'])],
            ['Avg Meta Size', $this->formatBytes($analysis['avg_meta_size'])],
            ['Avg Record Size', $this->formatBytes($analysis['avg_record_size'])],
            ['Est. Migration Time', $this->formatDuration($analysis['estimated_migration_time'])],
        ]);
        $this->newLine();
    }

    protected function recommendUpgradePath(array $analysis): void
    {
        $totalRecords = $analysis['total_records'];

        $this->info('📋 Recommended Upgrade Path:');
        $this->newLine();

        if ($totalRecords <= $this->smallDatasetThreshold) {
            $this->recommendSmallDatasetPath($analysis);
        } elseif ($totalRecords <= $this->mediumDatasetThreshold) {
            $this->recommendMediumDatasetPath($analysis);
        } else {
            $this->recommendLargeDatasetPath($analysis);
        }

        $this->newLine();
        $this->info('💡 Tips:');
        $this->line('- Always backup your database before upgrading');
        $this->line('- Run migrations during low-traffic periods');
        $this->line('- Monitor disk space during migration (needs ~2x current size temporarily)');
        $this->line('- Test the upgrade process in a staging environment first');
    }

    protected function recommendSmallDatasetPath(array $analysis): void
    {
        $this->line('✅ <fg=green>Small Dataset Detected</> ('.number_format($analysis['total_records']).' records)');
        $this->line('');
        $this->line('You can use the <fg=cyan>all-in-one migration</> for a simple upgrade:');
        $this->line('');
        $this->line('1. Run the all-in-one migration:');
        $this->line('   <fg=yellow>php artisan migrate --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000003_upgrade_machine_events_all_in_one_v3.php.stub</>');
        $this->line('');
        $this->line('2. Compress the data (optional but recommended):');
        $this->line('   <fg=yellow>php artisan machine:compress-events</>');
        $this->line('');
        $this->line('Estimated total time: '.$this->formatDuration($analysis['estimated_migration_time'] + 60));
    }

    protected function recommendMediumDatasetPath(array $analysis): void
    {
        $this->line('⚠️  <fg=yellow>Medium Dataset Detected</> ('.number_format($analysis['total_records']).' records)');
        $this->line('');
        $this->line('Recommended: Use the <fg=cyan>two-step migration</> with direct processing:');
        $this->line('');
        $this->line('1. Run the first migration (adds new columns):');
        $this->line('   <fg=yellow>php artisan migrate --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000001_upgrade_machine_events_for_compression_v3.php.stub</>');
        $this->line('');
        $this->line('2. Migrate data:');
        $this->line('   <fg=yellow>php artisan machine:migrate-events</>');
        $this->line('');
        $this->line('3. Complete schema changes:');
        $this->line('   <fg=yellow>php artisan migrate --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000002_complete_machine_events_compression_upgrade_v3.php.stub</>');
        $this->line('');
        $this->line('4. Compress data (optional):');
        $this->line('   <fg=yellow>php artisan machine:compress-events</>');
        $this->line('');
        $this->line('Estimated total time: '.$this->formatDuration($analysis['estimated_migration_time'] * 1.5));
    }

    protected function recommendLargeDatasetPath(array $analysis): void
    {
        $this->line('🚨 <fg=red>Large Dataset Detected</> ('.number_format($analysis['total_records']).' records)');
        $this->line('');
        $this->line('Required: Use the <fg=cyan>two-step migration</> with <fg=cyan>queue processing</>:');
        $this->line('');
        $this->line('1. Run the first migration (adds new columns):');
        $this->line('   <fg=yellow>php artisan migrate --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000001_upgrade_machine_events_for_compression_v3.php.stub</>');
        $this->line('');
        $this->line('2. Start queue workers (in separate terminals):');
        $this->line('   <fg=yellow>php artisan queue:work --queue=default --sleep=3 --tries=3</>');
        $this->line('');
        $this->line('3. Dispatch migration job:');
        $this->line('   <fg=yellow>php artisan machine:migrate-events --queue</>');
        $this->line('');
        $this->line('4. Monitor progress in queue worker logs');
        $this->line('');
        $this->line('5. After migration completes, run second migration:');
        $this->line('   <fg=yellow>php artisan migrate --path=vendor/tarfinlabs/event-machine/database/migrations/2025_01_01_000002_complete_machine_events_compression_upgrade_v3.php.stub</>');
        $this->line('');
        $this->line('6. Compress data using queue (optional):');
        $this->line('   <fg=yellow>php artisan tinker</>');
        $this->line('   <fg=yellow>>>> \\Tarfinlabs\\EventMachine\\Jobs\\CompressMachineEventsJob::dispatch(5000);</>');
        $this->line('');
        $this->line('Estimated time: '.$this->formatDuration($analysis['estimated_migration_time'] * 2).' (varies based on queue workers)');
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = floor(log($bytes, 1024));

        return number_format($bytes / pow(1024, $power), 2).' '.$units[$power];
    }

    protected function formatSize(float $bytes): string
    {
        return $this->formatBytes((int) $bytes);
    }

    protected function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds).' seconds';
        } elseif ($seconds < 3600) {
            return round($seconds / 60).' minutes';
        } else {
            return round($seconds / 3600, 1).' hours';
        }
    }
}
