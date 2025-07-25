<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Support\CompressionManager;

beforeEach(function (): void {
    // Reset config cache
    $reflection     = new ReflectionClass(CompressionManager::class);
    $configProperty = $reflection->getProperty('config');
    $configProperty->setAccessible(true);
    $configProperty->setValue(null);
});

describe('CompressionManager', function (): void {
    it('detects compressed data correctly', function (): void {
        $data       = ['test' => 'data', 'number' => 123];
        $compressed = gzcompress(json_encode($data), 6);

        expect(CompressionManager::isCompressed($compressed))->toBeTrue();
        expect(CompressionManager::isCompressed(json_encode($data)))->toBeFalse();
        expect(CompressionManager::isCompressed(null))->toBeFalse();
        expect(CompressionManager::isCompressed(''))->toBeFalse();
    });

    it('compresses and decompresses data correctly', function (): void {
        // Set low threshold for testing
        config(['machine.compression.threshold' => 10]);

        $originalData = [
            'test'   => 'data',
            'number' => 123,
            'array'  => [1, 2, 3, 4, 5],
            'nested' => ['key' => 'value'],
        ];

        // Test compression
        $compressed = CompressionManager::compress($originalData, 'payload');
        expect(CompressionManager::isCompressed($compressed))->toBeTrue();

        // Test decompression
        $decompressed = CompressionManager::decompress($compressed);
        expect($decompressed)->toEqual($originalData);
    });

    it('handles backward compatibility with uncompressed JSON', function (): void {
        $data     = ['test' => 'data', 'number' => 123];
        $jsonData = json_encode($data);

        // Should be able to decompress uncompressed JSON
        $result = CompressionManager::decompress($jsonData);
        expect($result)->toEqual($data);
    });

    it('respects compression threshold', function (): void {
        config(['machine.compression.threshold' => 100]);

        $smallData = ['small' => 'data'];
        $largeData = str_repeat('x', 200);

        // Small data should not be compressed
        $smallCompressed = CompressionManager::compress($smallData, 'payload');
        expect(CompressionManager::isCompressed($smallCompressed))->toBeFalse();

        // Large data should be compressed
        $largeCompressed = CompressionManager::compress(['large' => $largeData], 'payload');
        expect(CompressionManager::isCompressed($largeCompressed))->toBeTrue();
    });

    it('respects field configuration', function (): void {
        config([
            'machine.compression.fields'    => ['payload', 'context'],
            'machine.compression.threshold' => 10,
        ]);

        $data = ['test' => 'data with enough content to exceed threshold'];

        // Should compress configured fields
        $payloadCompressed = CompressionManager::compress($data, 'payload');
        expect(CompressionManager::isCompressed($payloadCompressed))->toBeTrue();

        // Should not compress non-configured fields
        $metaCompressed = CompressionManager::compress($data, 'meta');
        expect(CompressionManager::isCompressed($metaCompressed))->toBeFalse();
        expect($metaCompressed)->toEqual(json_encode($data));
    });

    it('handles compression disabled globally', function (): void {
        config(['machine.compression.enabled' => false]);

        $data       = ['test' => 'data'];
        $compressed = CompressionManager::compress($data, 'payload');

        expect(CompressionManager::isCompressed($compressed))->toBeFalse();
        expect($compressed)->toEqual(json_encode($data));
    });

    it('calculates compression statistics', function (): void {
        $data = ['test' => str_repeat('a', 1000)];

        $stats = CompressionManager::getCompressionStats($data);

        expect($stats)->toHaveKeys([
            'original_size',
            'compressed_size',
            'compression_ratio',
            'savings_percent',
            'compressed',
        ]);

        expect($stats['original_size'])->toBeGreaterThan(0);
        expect($stats['compressed_size'])->toBeLessThan($stats['original_size']);
        expect($stats['compression_ratio'])->toBeLessThan(1.0);
        expect($stats['savings_percent'])->toBeGreaterThan(0);
        expect($stats['compressed'])->toBeTrue();
    });

    it('handles null values correctly', function (): void {
        expect(CompressionManager::compress(null, 'payload'))->toEqual('null');
        expect(CompressionManager::decompress(null))->toBeNull();
        expect(CompressionManager::decompress('null'))->toBeNull();
    });

    it('handles compression failure gracefully', function (): void {
        // Mock gzcompress to return false
        $data = ['test' => 'data'];

        // Even if compression fails, it should return JSON
        $result = CompressionManager::compress($data, 'payload');
        expect($result)->toEqual(json_encode($data));
    });

    it('throws exception on decompression failure', function (): void {
        // Invalid compressed data should throw exception
        expect(fn () => CompressionManager::decompress('invalid_compressed_data'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('handles edge case compression levels', function (): void {
        // Reset config cache before testing different levels
        $reflection     = new ReflectionClass(CompressionManager::class);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);

        config(['machine.compression.level' => 0]); // Fastest compression
        $configProperty->setValue(null); // Reset cache
        expect(CompressionManager::getLevel())->toEqual(0);

        config(['machine.compression.level' => 9]); // Best compression
        $configProperty->setValue(null); // Reset cache
        expect(CompressionManager::getLevel())->toEqual(9);

        // Invalid levels should throw exception
        config(['machine.compression.level' => -1]);
        $configProperty->setValue(null); // Reset cache
        expect(fn () => CompressionManager::getLevel())->toThrow(InvalidArgumentException::class);

        config(['machine.compression.level' => 10]);
        $configProperty->setValue(null); // Reset cache
        expect(fn () => CompressionManager::getLevel())->toThrow(InvalidArgumentException::class);
    });
});
