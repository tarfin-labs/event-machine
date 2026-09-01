# Compression

Compression settings and performance tuning for event archival.

**Related:** [Archival](/laravel-integration/archival) - Setup, commands, and troubleshooting

## Compression Levels

| Level | Compression | Speed | Use Case |
|-------|-------------|-------|----------|
| 0 | None | Fastest | Testing |
| 1-3 | Low | Fast | Real-time archival |
| 4-6 | Medium | Balanced | Most use cases |
| 7-9 | High | Slow | Storage-constrained |

Level 6 (default) provides a good balance of compression and speed.

## Configuration

<!-- doctest-attr: ignore -->
```php
// config/machine.php
'archival' => [
    'level' => env('MACHINE_EVENTS_COMPRESSION_LEVEL', 6),
    'threshold' => env('MACHINE_EVENTS_ARCHIVAL_THRESHOLD', 1000),
],
```

| Option | Default | Description |
|--------|---------|-------------|
| `level` | `6` | gzip compression level (0-9) |
| `threshold` | `1000` | Minimum bytes before applying compression |

## Level Selection Guide

| Scenario | Recommended Level | Rationale |
|----------|------------------|-----------|
| Real-time archival | 1-3 | Speed matters more than size |
| Nightly batch jobs | 6 | Good balance (default) |
| Storage-constrained | 9 | Maximum compression |
| SSD storage | 4-6 | Fast I/O compensates |
| HDD storage | 7-9 | Minimize disk usage |

## Performance Tuning

### Dispatch Limit Optimization

The `dispatch_limit` controls how many workflows (unique root_event_ids) are found and dispatched per scheduler run:

<!-- doctest-attr: ignore -->
```php
// Conservative - fewer jobs per run, more runs
'dispatch_limit' => 25,   // Memory-constrained environments

// Aggressive - more jobs per run, faster archival
'dispatch_limit' => 200,  // High-capacity environments
```

**Throughput estimation:**
- dispatch_limit × runs_per_hour × workers = workflows/hour
- Example: 50 × 12 × 4 = 2400 workflows/hour

### Index Strategy

Ensure these indexes exist for optimal performance:

```sql
-- For finding archival candidates
CREATE INDEX idx_machine_events_last_activity
ON machine_events (root_event_id, created_at);

-- For archive lookups
CREATE INDEX idx_archives_machine_archived
ON machine_event_archives (machine_id, archived_at);
```

### Query Optimization for Large Tables

<!-- doctest-attr: ignore -->
```php
// Instead of loading all eligible machines at once
$service = new ArchiveService();

// Process in chunks
$eligible = $service->getEligibleInstances(limit: 100);

while ($eligible->isNotEmpty()) {
    $rootIds = $eligible->pluck('root_event_id')->toArray();
    $service->batchArchive($rootIds);

    $eligible = $service->getEligibleInstances(limit: 100);
}
```

## Storage Estimation

### Calculate Current Storage

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Support\CompressionManager;

// Sample 100 machines for estimation
$samples = MachineEvent::selectRaw('root_event_id, COUNT(*) as count')
    ->groupBy('root_event_id')
    ->limit(100)
    ->get();

$totalOriginal = 0;
$totalCompressed = 0;

foreach ($samples as $sample) {
    $events = MachineEvent::where('root_event_id', $sample->root_event_id)
        ->get()
        ->toArray();

    $jsonData = json_encode($events);
    $originalSize = strlen($jsonData);
    $totalOriginal += $originalSize;

    if (CompressionManager::shouldCompress($jsonData)) {
        $compressed = CompressionManager::compressJson($jsonData);
        $totalCompressed += strlen($compressed);
    } else {
        $totalCompressed += $originalSize;
    }
}

$ratio = $totalCompressed / $totalOriginal;
echo "Estimated compression ratio: " . round($ratio * 100) . "%";
echo "Potential savings: " . round((1 - $ratio) * 100) . "%";
```

### Project Future Storage

<!-- doctest-attr: ignore -->
```php
// Current stats
$currentEvents = MachineEvent::count();
$avgEventSize = 500; // bytes, estimate from sampling
$growthRate = 10000; // events per day

// Project 30 days
$futureEvents = $currentEvents + ($growthRate * 30);
$uncompressedSize = $futureEvents * $avgEventSize;
$compressedSize = $uncompressedSize * 0.15; // 85% compression

echo "30-day projection without archival: " .
    round($uncompressedSize / 1024 / 1024 / 1024, 2) . " GB";
echo "30-day projection with archival: " .
    round($compressedSize / 1024 / 1024 / 1024, 2) . " GB";
```

## Compression Statistics

Query archive statistics to monitor compression effectiveness:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Models\MachineEventArchive;

// Average compression ratio by machine type
$stats = MachineEventArchive::selectRaw('
    machine_id,
    AVG(compressed_size::float / original_size) as avg_ratio,
    SUM(original_size) as total_original,
    SUM(compressed_size) as total_compressed
')
    ->groupBy('machine_id')
    ->get();

foreach ($stats as $stat) {
    echo "{$stat->machine_id}: " . round((1 - $stat->avg_ratio) * 100) . "% savings\n";
}
```

## Adjusting Compression for Specific Machines

If certain machine types compress poorly or are frequently accessed:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Services\ArchiveService;

// Custom configuration for specific machine
$service = new ArchiveService([
    'level' => 3,  // Lower compression for frequently restored machines
]);

$service->archiveMachine($rootEventId);
```

## CPU vs Storage Trade-offs

| Scenario | Recommendation |
|----------|----------------|
| Many small events | Level 1-3 (overhead not worth it) |
| Few large events | Level 7-9 (compression pays off) |
| High restore frequency | Level 1-3 (decompress faster) |
| Archive rarely accessed | Level 7-9 (storage savings) |
| Limited CPU | Level 1-3 |
| Limited storage | Level 7-9 |
