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
});
