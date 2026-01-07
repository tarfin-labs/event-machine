# CompressionManager

The `CompressionManager` class provides static utility methods for compressing and decompressing data using PHP's zlib compression.

**Namespace:** `Tarfinlabs\EventMachine\Support`

## Overview

CompressionManager handles:
- Checking if compression is enabled and should be applied
- Compressing data using gzip (zlib format)
- Detecting compressed data via header inspection
- Transparent decompression with JSON fallback
- Compression statistics calculation

## Static Methods

### isEnabled

```php
public static function isEnabled(): bool
```

Checks if compression is globally enabled in configuration.

**Returns:** `true` if archival compression is enabled.

```php
if (CompressionManager::isEnabled()) {
    // Proceed with compression
}
```

### getLevel

```php
public static function getLevel(): int
```

Gets the compression level from configuration.

**Returns:** Integer between 0-9 (default: 6).

**Throws:** `InvalidArgumentException` if level is out of range.

```php
$level = CompressionManager::getLevel(); // 6
```

| Level | Speed | Compression |
|-------|-------|-------------|
| 0 | Fastest | No compression |
| 1 | Very fast | Minimal |
| 6 | Balanced | Good (default) |
| 9 | Slowest | Maximum |

### getThreshold

```php
public static function getThreshold(): int
```

Gets the minimum size threshold before compression is applied.

**Returns:** Size in bytes (default: 1000).

```php
$threshold = CompressionManager::getThreshold(); // 1000 bytes
```

### shouldCompress

```php
public static function shouldCompress(string $data): bool
```

Determines if data should be compressed based on size threshold and enabled status.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$data` | `string` | Data to check |

**Returns:** `true` if data meets threshold and compression is enabled.

```php
$json = json_encode($largeArray);

if (CompressionManager::shouldCompress($json)) {
    $compressed = CompressionManager::compressJson($json);
}
```

### isCompressed

```php
public static function isCompressed(?string $data): bool
```

Detects if data is compressed by checking for zlib header format.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$data` | `?string` | Data to check |

**Returns:** `true` if data appears to be zlib-compressed.

```php
$isCompressed = CompressionManager::isCompressed($data);

if ($isCompressed) {
    $decoded = CompressionManager::decompress($data);
}
```

### compressJson

```php
public static function compressJson(string $jsonData): string
```

Compresses a JSON string if it meets the threshold. Returns original data if compression is disabled or data is below threshold.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$jsonData` | `string` | JSON string to compress |

**Returns:** Compressed data or original JSON string.

```php
$json = json_encode($events);
$compressed = CompressionManager::compressJson($json);

// Store $compressed - may or may not be compressed
// depending on size and configuration
```

### compress

```php
public static function compress(mixed $data): string
```

Converts data to JSON and compresses if threshold is met.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$data` | `mixed` | Any JSON-serializable data |

**Returns:** Compressed string or JSON string.

**Throws:** `JsonException` if JSON encoding fails.

```php
$events = ['type' => 'ORDER_CREATED', 'payload' => [...]];
$compressed = CompressionManager::compress($events);
```

### decompress

```php
public static function decompress(?string $data): mixed
```

Decompresses data if compressed, otherwise parses as JSON. Provides transparent backward compatibility.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$data` | `?string` | Compressed or JSON string |

**Returns:** Decoded data (array or other JSON-decoded type).

**Throws:** `InvalidArgumentException` if decompression fails or data is invalid, `JsonException` if JSON decoding fails.

```php
// Works with both compressed and uncompressed data
$events = CompressionManager::decompress($storedData);
```

### clearCache

```php
public static function clearCache(): void
```

Clears the cached configuration. Useful for testing.

```php
// In tests
CompressionManager::clearCache();
config(['machine.archival.level' => 9]);
```

## Configuration

The manager reads from `config/machine.php`:

```php
'archival' => [
    'enabled' => env('MACHINE_EVENTS_ARCHIVAL_ENABLED', true),
    'level' => env('MACHINE_EVENTS_COMPRESSION_LEVEL', 6),
    'threshold' => env('MACHINE_EVENTS_ARCHIVAL_THRESHOLD', 1000),
],
```

## Compression Format

CompressionManager uses PHP's `gzcompress()` which produces zlib-format data:
- Header: 2 bytes (CMF + FLG)
- Compressed data: DEFLATE algorithm
- Checksum: 4 bytes (Adler-32)

The `isCompressed()` method detects this format by validating the zlib header.

## Usage Examples

### Estimate Storage Savings

```php
// Before archiving, estimate compression ratio
$events = MachineEvent::where('root_event_id', $id)->get()->toArray();
$jsonData = json_encode($events);
$originalSize = strlen($jsonData);

if (CompressionManager::shouldCompress($jsonData)) {
    $compressed = CompressionManager::compressJson($jsonData);
    $compressedSize = strlen($compressed);
    $savingsPercent = (1 - ($compressedSize / $originalSize)) * 100;

    echo "Good candidate for archival - {$savingsPercent}% savings";
}
```

### Manual Compression/Decompression

```php
// Compress
$data = ['large' => str_repeat('x', 10000)];
$compressed = CompressionManager::compress($data);

// Store $compressed in database...

// Later, decompress
$restored = CompressionManager::decompress($compressed);
```

### Check Data Status

```php
function analyzeStoredData(string $data): void
{
    if (CompressionManager::isCompressed($data)) {
        echo "Data is compressed";
        $decoded = CompressionManager::decompress($data);
    } else {
        echo "Data is plain JSON";
        $decoded = json_decode($data, true);
    }
}
```

## Performance Notes

- Level 6 (default) offers best balance of speed and compression
- Level 9 may be 2-3x slower with only 5-10% better compression
- Level 1 is fast but may only achieve 50-60% of level 6 compression
- Data under threshold (1000 bytes) is not compressed - overhead not worth it
- Typical JSON compression ratio: 0.10-0.20 (80-90% space savings)

## See Also

- [ArchiveService](/api-reference/archive-service) - Uses CompressionManager for archival
- [MachineEventArchive](/api-reference/machine-event-archive) - Stores compressed events
- [Archival & Compression Guide](/laravel-integration/archival-compression) - Configuration and usage
