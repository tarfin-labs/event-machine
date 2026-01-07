<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Support;

use InvalidArgumentException;

class CompressionManager
{
    protected static ?array $config = null;

    protected static function getConfig(): array
    {
        if (self::$config === null) {
            self::$config = config('machine.archival', [
                'enabled'   => true,
                'level'     => 6,
                'threshold' => 1000, // Minimum bytes before archival compression
            ]);
        }

        return self::$config;
    }

    /**
     * Clear the cached configuration. Useful for testing.
     */
    public static function clearCache(): void
    {
        self::$config = null;
    }

    /**
     * Check if compression is enabled globally.
     */
    public static function isEnabled(): bool
    {
        return (bool) self::getConfig()['enabled'];
    }

    /**
     * Get the compression level (0-9).
     */
    public static function getLevel(): int
    {
        $level = self::getConfig()['level'];

        if ($level < 0 || $level > 9) {
            throw new InvalidArgumentException('Compression level must be between 0 and 9');
        }

        return $level;
    }

    /**
     * Get the minimum size threshold for compression.
     */
    public static function getThreshold(): int
    {
        return (int) self::getConfig()['threshold'];
    }

    /**
     * Check if data should be compressed based on size threshold.
     */
    public static function shouldCompress(string $data): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        return strlen($data) >= self::getThreshold();
    }

    /**
     * Detect if data is compressed by checking zlib header.
     * gzcompress uses zlib format with specific header structure.
     */
    public static function isCompressed(?string $data): bool
    {
        if ($data === null || strlen($data) < 2) {
            return false;
        }

        // Check for zlib header format
        $header = unpack('n', substr($data, 0, 2))[1];

        // zlib header validation:
        // - Header must be divisible by 31
        // - First byte low 4 bits should be 8 (deflate method)
        // - First byte high bit should be 0
        $firstByte = ord($data[0]);

        return ($header % 31 === 0) &&
               (($firstByte & 0x0F) === 0x08) &&
               (($firstByte & 0x80) === 0x00);
    }

    /**
     * Compress JSON string if it meets the threshold and compression is enabled.
     *
     * @throws \JsonException
     */
    public static function compressJson(string $jsonData): string
    {
        if (!self::shouldCompress($jsonData)) {
            return $jsonData;
        }

        $compressed = gzcompress($jsonData, self::getLevel());

        if ($compressed === false) {
            // Fallback to uncompressed JSON if compression fails
            return $jsonData;
        }

        return $compressed;
    }

    /**
     * Compress mixed data by first converting to JSON.
     *
     * @throws \JsonException
     */
    public static function compress(mixed $data): string
    {
        $jsonData = json_encode($data, JSON_THROW_ON_ERROR);

        return self::compressJson($jsonData);
    }

    /**
     * Decompress data if it's compressed, otherwise return as-is.
     *
     * @throws \JsonException
     */
    public static function decompress(?string $data): mixed
    {
        if ($data === null) {
            return null;
        }

        if (self::isCompressed($data)) {
            $decompressed = gzuncompress($data);

            if ($decompressed === false) {
                throw new InvalidArgumentException('Failed to decompress data');
            }

            return json_decode($decompressed, true, 512, JSON_THROW_ON_ERROR);
        }

        // Try to decode as JSON (backward compatibility)
        $decoded = json_decode($data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Data is neither compressed nor valid JSON');
        }

        return $decoded;
    }
}
