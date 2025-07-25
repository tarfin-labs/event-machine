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
});
