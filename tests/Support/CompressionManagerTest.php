<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Support\CompressionManager;

beforeEach(function (): void {
    // Reset config cache before each test
    CompressionManager::clearCache();
});

describe('CompressionManager (Archival)', function (): void {
    beforeEach(function (): void {
        config([
            'machine.archival.enabled'   => true,
            'machine.archival.level'     => 6,
            'machine.archival.threshold' => 100,
        ]);
    });

    it('detects compressed data correctly', function (): void {
        $data       = ['test' => 'data', 'number' => 123];
        $compressed = gzcompress(json_encode($data), 6);

        expect(CompressionManager::isCompressed($compressed))->toBeTrue();
        expect(CompressionManager::isCompressed(json_encode($data)))->toBeFalse();
        expect(CompressionManager::isCompressed(null))->toBeFalse();
        expect(CompressionManager::isCompressed(''))->toBeFalse();
    });

    it('compresses and decompresses data correctly', function (): void {
        $originalData = [
            'test'   => str_repeat('test_data_', 20), // Make it larger than threshold
            'number' => 123,
            'array'  => [1, 2, 3, 4, 5],
            'nested' => ['key' => 'value'],
        ];

        // Test compression
        $compressed = CompressionManager::compress($originalData);
        expect(CompressionManager::isCompressed($compressed))->toBeTrue();

        // Test decompression
        $decompressed = CompressionManager::decompress($compressed);
        expect($decompressed)->toEqual($originalData);
    });

    it('compresses JSON strings correctly', function (): void {
        $data     = ['test' => str_repeat('data_', 30)]; // Large enough to exceed threshold
        $jsonData = json_encode($data);

        $compressed = CompressionManager::compressJson($jsonData);
        expect(CompressionManager::isCompressed($compressed))->toBeTrue();

        $decompressed = CompressionManager::decompress($compressed);
        expect($decompressed)->toEqual($data);
    });

    it('does not compress data below threshold', function (): void {
        config(['machine.archival.threshold' => 1000]); // High threshold

        $smallData = ['test' => 'small'];
        $jsonData  = json_encode($smallData);

        $result = CompressionManager::compressJson($jsonData);
        expect(CompressionManager::isCompressed($result))->toBeFalse();
        expect($result)->toBe($jsonData); // Should return original JSON
    });

    it('respects compression level setting', function (): void {
        $data = ['test' => str_repeat('test_data_', 50)];

        // Test different compression levels
        config(['machine.archival.level' => 1]);
        CompressionManager::clearCache(); // Clear cache after config change
        expect(CompressionManager::getLevel())->toBe(1);

        config(['machine.archival.level' => 9]);
        CompressionManager::clearCache(); // Clear cache after config change
        expect(CompressionManager::getLevel())->toBe(9);
    });

    it('validates compression level bounds', function (): void {
        config(['machine.archival.level' => -1]);
        expect(fn () => CompressionManager::getLevel())
            ->toThrow(InvalidArgumentException::class, 'Compression level must be between 0 and 9');

        config(['machine.archival.level' => 10]);
        expect(fn () => CompressionManager::getLevel())
            ->toThrow(InvalidArgumentException::class, 'Compression level must be between 0 and 9');
    });

    it('handles compression failures gracefully', function (): void {
        $data = ['test' => str_repeat('data_', 50)];

        // This should not throw an exception even if compression fails internally
        $result = CompressionManager::compress($data);
        expect($result)->toBeString();
    });

    it('checks if data should be compressed based on threshold', function (): void {
        config(['machine.archival.threshold' => 100]);

        $largeData = str_repeat('x', 200);
        $smallData = str_repeat('x', 50);

        expect(CompressionManager::shouldCompress($largeData))->toBeTrue();
        expect(CompressionManager::shouldCompress($smallData))->toBeFalse();
    });

    it('respects global enabled/disabled setting', function (): void {
        config(['machine.archival.enabled' => false]);
        CompressionManager::clearCache(); // Clear cache after config change

        $data = str_repeat('x', 200); // Large data
        expect(CompressionManager::shouldCompress($data))->toBeFalse();
        expect(CompressionManager::isEnabled())->toBeFalse();

        config(['machine.archival.enabled' => true]);
        CompressionManager::clearCache(); // Clear cache after config change
        expect(CompressionManager::shouldCompress($data))->toBeTrue();
        expect(CompressionManager::isEnabled())->toBeTrue();
    });

    it('handles backward compatibility with uncompressed JSON', function (): void {
        $data     = ['test' => 'data', 'number' => 123];
        $jsonData = json_encode($data);

        // Should be able to decompress uncompressed JSON
        $result = CompressionManager::decompress($jsonData);
        expect($result)->toEqual($data);
    });
});
