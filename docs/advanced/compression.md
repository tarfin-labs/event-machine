# Data Compression

EventMachine v3.0 introduces an advanced compression system to optimize storage and performance when persisting machine state and events. The compression system is designed to reduce database storage requirements while maintaining backward compatibility.

## Overview

The `CompressionManager` provides intelligent compression of machine data based on configurable thresholds and compression levels. It automatically detects compressed data and handles both compressed and uncompressed formats seamlessly.

## Configuration

Configure compression in your `config/machine.php` file:

```php
return [
    'compression' => [
        'enabled'   => true,           // Enable/disable compression globally
        'level'     => 6,              // Compression level (0-9, where 9 is maximum)
        'fields'    => ['payload', 'context', 'meta'], // Fields to compress
        'threshold' => 100,            // Minimum size in bytes to trigger compression
    ],
];
```

### Configuration Options

- **enabled**: Controls whether compression is active globally
- **level**: zlib compression level (0 = no compression, 9 = maximum compression)
- **fields**: Array of field names that should be compressed
- **threshold**: Minimum data size in bytes before compression is applied

## Compression Manager API

### Basic Usage

```php
use Tarfinlabs\EventMachine\Support\CompressionManager;

// Check if compression is enabled
if (CompressionManager::isEnabled()) {
    // Compress data for a specific field
    $compressed = CompressionManager::compress($data, 'payload');
    
    // Decompress data
    $original = CompressionManager::decompress($compressed);
}
```

### Field-Specific Compression

```php
// Check if a specific field should be compressed
if (CompressionManager::shouldCompressField('context')) {
    $compressed = CompressionManager::compress($contextData, 'context');
}

// Fields not in configuration won't be compressed
$result = CompressionManager::compress($data, 'some_other_field'); // Returns JSON
```

### Compression Detection

```php
$data = '...'; // Some data that might be compressed

if (CompressionManager::isCompressed($data)) {
    $decompressed = CompressionManager::decompress($data);
} else {
    $parsed = json_decode($data, true);
}
```

## Compression Statistics

Get detailed information about compression effectiveness:

```php
$stats = CompressionManager::getCompressionStats($largeData);

/*
Returns:
[
    'original_size'     => 1500,     // Original size in bytes
    'compressed_size'   => 450,      // Compressed size in bytes
    'compression_ratio' => 0.3,      // Ratio (compressed/original)
    'savings_percent'   => 70.0,     // Percentage saved
    'compressed'        => true,     // Whether compression was beneficial
]
*/
```

## How It Works

### Compression Process

1. **Threshold Check**: Data is only compressed if it exceeds the configured threshold
2. **Field Validation**: Only fields listed in the configuration are compressed
3. **Size Optimization**: If compression doesn't reduce size, original JSON is stored
4. **Header Detection**: Uses zlib headers for automatic compression detection

### Storage Format

```php
// Original data
$data = ['complex' => 'data', 'with' => 'many', 'fields' => [...]];

// Small data (under threshold) - stored as JSON
'{"complex":"data","with":"many","fields":[...]}'

// Large data (over threshold) - stored compressed with zlib headers
"\x78\x9c..." // Binary compressed data
```

### Decompression Process

1. **Header Analysis**: Checks for zlib compression headers
2. **Automatic Detection**: Determines if data is compressed or JSON
3. **Fallback Handling**: Gracefully handles malformed or uncompressed data
4. **Error Recovery**: Throws descriptive exceptions for debugging

## Integration with EventMachine

### Automatic Compression

EventMachine automatically uses compression for configured fields when persisting machine events:

```php
// Machine definition with large context
$machine = TrafficLightMachine::create([
    'context' => [
        'sensors' => $largeSensorData,     // Will be compressed if > threshold
        'history' => $detailedHistory,     // Will be compressed if > threshold
        'metadata' => $smallMetadata,      // Won't be compressed if < threshold
    ]
]);

// Context is automatically compressed when saving to database
$machine->send('TIMER_EXPIRED');
```

### Event Payload Compression

```php
// Large event payloads are automatically compressed
$machine->send('DATA_RECEIVED', [
    'payload' => $largeDataSet,    // Compressed if over threshold
    'metadata' => $smallMetadata,  // Stored as JSON if under threshold
]);
```

## Performance Considerations

### Compression Levels

- **Level 1-3**: Fast compression, moderate reduction
- **Level 4-6**: Balanced speed and compression (recommended)
- **Level 7-9**: Maximum compression, slower processing

### Memory Usage

```php
// Compression uses temporary memory during processing
$stats = CompressionManager::getCompressionStats($data);

// Monitor compression effectiveness
if ($stats['savings_percent'] < 10.0) {
    // Consider increasing threshold or disabling for this data type
}
```

### Benchmark Results

Based on typical EventMachine usage patterns:

| Data Type | Original Size | Compressed Size | Savings |
|-----------|---------------|-----------------|---------|
| JSON Context | 2.5KB | 0.8KB | 68% |
| Event Payloads | 1.2KB | 0.4KB | 67% |
| Machine State | 800B | 300B | 62% |

## Migration and Compatibility

### Upgrading to v3.0

Existing machines continue to work without compression. Enable compression gradually:

```php
// 1. Enable compression in config
'compression' => ['enabled' => true],

// 2. Monitor performance
$stats = CompressionManager::getCompressionStats($data);

// 3. Adjust threshold based on your data patterns
'threshold' => 200, // Increase if needed
```

### Backward Compatibility

```php
// Old uncompressed data is automatically detected and handled
$oldData = '{"legacy":"json","data":true}';
$parsed = CompressionManager::decompress($oldData); // Works seamlessly

// New compressed data is handled transparently
$newData = CompressionManager::compress($data, 'payload');
$parsed = CompressionManager::decompress($newData); // Also works
```

## Error Handling

### Compression Failures

```php
try {
    $compressed = CompressionManager::compress($data, 'payload');
} catch (JsonException $e) {
    // Handle JSON encoding errors
    Log::error('JSON encoding failed', ['error' => $e->getMessage()]);
}
```

### Decompression Failures

```php
try {
    $data = CompressionManager::decompress($compressed);
} catch (InvalidArgumentException $e) {
    // Handle decompression or JSON parsing errors
    Log::error('Decompression failed', ['error' => $e->getMessage()]);
}
```

## Best Practices

### Configuration Tuning

```php
// Start conservative and monitor
'compression' => [
    'enabled'   => true,
    'level'     => 4,        // Balanced performance
    'threshold' => 500,      // Avoid compressing small data
    'fields'    => ['payload'], // Start with largest fields
],

// Gradually expand based on results
'fields' => ['payload', 'context', 'meta'],
```

### Performance Monitoring

```php
// Log compression statistics in development
if (app()->environment('local')) {
    $stats = CompressionManager::getCompressionStats($data);
    Log::info('Compression stats', $stats);
}
```

### Field Selection Strategy

```php
// Compress fields with repetitive or structured data
'fields' => [
    'payload',    // Event data - often large and structured
    'context',    // Machine context - accumulates over time
    'meta',       // Metadata - often contains repeated keys
],

// Don't compress fields with:
// - Random or encrypted data (poor compression ratio)
// - Already compressed data (images, files)
// - Small, simple values (IDs, flags)
```

The compression system provides significant storage savings while maintaining full compatibility with existing EventMachine installations.