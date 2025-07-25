<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Commands;

use Illuminate\Console\Command;

class CompressionBenchmarkCommand extends Command
{
    protected $signature = 'machine:compression-benchmark
                           {--size=1 : Size in MB to test}
                           {--iterations=3 : Number of iterations per level}';
    protected $description = 'Benchmark compression and decompression performance for different levels';

    public function handle(): int
    {
        $sizeMB     = (int) $this->option('size');
        $iterations = (int) $this->option('iterations');

        $this->info('EventMachine Compression Benchmark');
        $this->info('==================================');
        $this->info("Testing with {$sizeMB}MB of data, {$iterations} iterations per level");
        $this->newLine();

        // Generate test data
        $this->info('Generating test data...');
        $testData     = $this->generateTestData($sizeMB);
        $originalSize = strlen($testData);
        $this->info('Original size: '.$this->formatBytes($originalSize));
        $this->newLine();

        // Test each compression level
        $results = [];

        for ($level = 1; $level <= 9; $level++) {
            $this->info("Testing compression level {$level}...");

            $compressionTimes   = [];
            $decompressionTimes = [];
            $compressedSizes    = [];

            for ($i = 0; $i < $iterations; $i++) {
                // Test compression
                $startTime       = microtime(true);
                $compressed      = gzcompress($testData, $level);
                $compressionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

                $compressionTimes[] = $compressionTime;
                $compressedSizes[]  = strlen($compressed);

                // Test decompression
                $startTime         = microtime(true);
                $decompressed      = gzuncompress($compressed);
                $decompressionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

                $decompressionTimes[] = $decompressionTime;

                // Verify data integrity
                if ($decompressed !== $testData) {
                    $this->error('Data corruption detected at level '.$level);

                    return self::FAILURE;
                }
            }

            // Calculate averages
            $avgCompressionTime   = array_sum($compressionTimes) / count($compressionTimes);
            $avgDecompressionTime = array_sum($decompressionTimes) / count($decompressionTimes);
            $avgCompressedSize    = array_sum($compressedSizes) / count($compressedSizes);
            $compressionRatio     = ($avgCompressedSize / $originalSize) * 100;

            $results[] = [
                'Level'                   => $level,
                'Compression Time (ms)'   => round($avgCompressionTime, 2),
                'Decompression Time (ms)' => round($avgDecompressionTime, 2),
                'Compressed Size'         => $this->formatBytes((int) $avgCompressedSize),
                'Compression Ratio'       => round($compressionRatio, 1).'%',
                'Space Saved'             => round(100 - $compressionRatio, 1).'%',
            ];
        }

        $this->newLine();
        $this->info('Benchmark Results:');
        $this->table(
            ['Level', 'Compression Time (ms)', 'Decompression Time (ms)', 'Compressed Size', 'Compression Ratio', 'Space Saved'],
            $results
        );

        // Find optimal levels
        $this->newLine();
        $this->info('Analysis:');

        // Fastest compression
        $fastestCompression = collect($results)->sortBy('Compression Time (ms)')->first();
        $this->line('⚡ Fastest compression: Level '.$fastestCompression['Level'].' ('.$fastestCompression['Compression Time (ms)'].'ms)');

        // Fastest decompression
        $fastestDecompression = collect($results)->sortBy('Decompression Time (ms)')->first();
        $this->line('⚡ Fastest decompression: Level '.$fastestDecompression['Level'].' ('.$fastestDecompression['Decompression Time (ms)'].'ms)');

        // Best compression ratio
        $bestCompression = collect($results)->sortBy('Compression Ratio')->first();
        $this->line('📦 Best compression: Level '.$bestCompression['Level'].' ('.$bestCompression['Space Saved'].' saved)');

        // Recommended level (balance)
        $this->newLine();
        $this->info('💡 Recommendations:');
        $this->line('- Level 1: Best for real-time applications (fastest)');
        $this->line('- Level 6: Best balance of speed and compression (default)');
        $this->line('- Level 9: Best for archival storage (smallest size)');

        // Performance comparison
        $this->newLine();
        $level1 = $results[0];
        $level6 = $results[5];
        $level9 = $results[8];

        $this->info('Performance comparison:');
        $this->line(sprintf(
            'Level 6 vs Level 1: %.1fx slower compression, %.1f%% better compression',
            $level6['Compression Time (ms)'] / $level1['Compression Time (ms)'],
            $level6['Space Saved'] - $level1['Space Saved']
        ));
        $this->line(sprintf(
            'Level 9 vs Level 6: %.1fx slower compression, %.1f%% better compression',
            $level9['Compression Time (ms)'] / $level6['Compression Time (ms)'],
            $level9['Space Saved'] - $level6['Space Saved']
        ));

        return self::SUCCESS;
    }

    protected function generateTestData(int $sizeMB): string
    {
        // Generate realistic JSON-like data that compresses well
        $targetBytes = $sizeMB * 1024 * 1024;
        $data        = [];
        $currentSize = 0;

        // Sample data patterns (similar to machine events)
        $statuses = ['pending', 'processing', 'completed', 'failed'];
        $types    = ['user.created', 'order.placed', 'payment.processed', 'email.sent'];
        $names    = ['John Doe', 'Jane Smith', 'Bob Johnson', 'Alice Williams', 'Charlie Brown'];

        while ($currentSize < $targetBytes) {
            $event = [
                'id'        => uniqid('evt_'),
                'type'      => $types[array_rand($types)],
                'status'    => $statuses[array_rand($statuses)],
                'timestamp' => time() - rand(0, 86400),
                'payload'   => [
                    'user_id'   => rand(1000, 9999),
                    'user_name' => $names[array_rand($names)],
                    'amount'    => rand(100, 10000) / 100,
                    'currency'  => 'USD',
                    'items'     => array_map(function ($i) {
                        return [
                            'id'       => 'item_'.$i,
                            'name'     => 'Product '.$i,
                            'quantity' => rand(1, 5),
                            'price'    => rand(1000, 50000) / 100,
                        ];
                    }, range(1, rand(1, 5))),
                ],
                'metadata' => [
                    'ip_address' => rand(1, 255).'.'.rand(1, 255).'.'.rand(1, 255).'.'.rand(1, 255),
                    'user_agent' => 'Mozilla/5.0 (compatible; Test/1.0)',
                    'session_id' => md5(uniqid()),
                    'request_id' => uniqid('req_'),
                ],
            ];

            $jsonEvent = json_encode($event);
            $data[]    = $jsonEvent;
            $currentSize += strlen($jsonEvent);
        }

        return implode("\n", $data);
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = floor(log($bytes, 1024));

        return number_format($bytes / pow(1024, $power), 2).' '.$units[$power];
    }
}
