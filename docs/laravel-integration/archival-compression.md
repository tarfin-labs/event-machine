# Archival & Compression

EventMachine includes a sophisticated event archival system to manage database growth and optimize performance.

## Overview

The archival system:
- Compresses old events using gzip
- Stores archived events in a separate table
- Transparently restores archived events when accessed
- Reduces database size significantly

## Configuration

```php
// config/machine.php
return [
    'archival' => [
        // Enable/disable archival globally
        'enabled' => env('MACHINE_EVENTS_ARCHIVAL_ENABLED', true),

        // Compression level (0-9): Higher = better compression, more CPU
        'level' => env('MACHINE_EVENTS_COMPRESSION_LEVEL', 6),

        // Minimum data size (bytes) before compression
        'threshold' => env('MACHINE_EVENTS_ARCHIVAL_THRESHOLD', 1000),

        // Archive events after this many days of inactivity
        'days_inactive' => env('MACHINE_EVENTS_ARCHIVAL_DAYS', 30),

        // Cooldown hours before allowing restore
        'restore_cooldown_hours' => env('MACHINE_EVENTS_RESTORE_COOLDOWN_HOURS', 24),

        // Archive retention (days). null = keep forever
        'archive_retention_days' => env('MACHINE_EVENTS_ARCHIVE_RETENTION_DAYS', null),

        'advanced' => [
            'batch_size' => env('MACHINE_EVENTS_ARCHIVAL_BATCH_SIZE', 100),
        ],

        // Per-machine overrides
        'machine_overrides' => [
            'high_volume_machine' => [
                'days_inactive' => 7,
                'compression_level' => 9,
            ],
        ],
    ],
];
```

## Configuration Options

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `true` | Enable/disable archival |
| `level` | `6` | Compression level (0-9) |
| `threshold` | `1000` | Minimum bytes before compression |
| `days_inactive` | `30` | Days before archival |
| `restore_cooldown_hours` | `24` | Hours between restores |
| `archive_retention_days` | `null` | Days to keep archives |
| `batch_size` | `100` | Machines per batch |

## Archival Commands

### Archive Events

```bash
# Run archival synchronously
php artisan machine:archive-events

# Preview without changes
php artisan machine:archive-events --dry-run

# Dispatch to queue
php artisan machine:archive-events --queue

# Custom batch size
php artisan machine:archive-events --batch-size=200

# Skip confirmation
php artisan machine:archive-events --force
```

### Check Archive Status

```bash
# Overall statistics
php artisan machine:archive-status

# Specific machine details
php artisan machine:archive-status --machine-id=order

# Restore archived events
php artisan machine:archive-status --restore=01HXYZ...

# Clean up archive
php artisan machine:archive-status --cleanup-archive=01HXYZ...
```

## Archive Table

Archived events are stored in `machine_events_archive`:

```php
use Tarfinlabs\EventMachine\Models\MachineEventArchive;

$archive = MachineEventArchive::where('root_event_id', $rootId)->first();

$archive->machine_id;        // 'order'
$archive->events_data;       // Compressed binary data
$archive->event_count;       // Number of events
$archive->original_size;     // Size before compression
$archive->compressed_size;   // Size after compression
$archive->compression_level; // 0-9
$archive->archived_at;       // When archived
$archive->restore_count;     // Times restored
$archive->last_restored_at;  // Last restore time
```

### Compression Statistics

```php
$archive->compressionRatio;   // e.g., 0.15 (15% of original)
$archive->savingsPercent;     // e.g., 85% savings
```

## Transparent Restoration

When you access an archived machine, it's automatically restored:

```php
// Events were archived 60 days ago
$machine = OrderMachine::create(state: $archivedRootId);

// Behind the scenes:
// 1. Check if events exist in machine_events
// 2. If not, check machine_events_archive
// 3. Decompress and restore to machine_events
// 4. Continue as normal

$machine->state->matches('completed'); // Works normally
```

## Programmatic Archival

### Archive Service

```php
use Tarfinlabs\EventMachine\Services\ArchiveService;

$service = new ArchiveService();

// Archive a specific machine
$archive = $service->archiveMachine($rootEventId);

// Restore from archive
$events = $service->restoreFromArchive($rootEventId);

// Check if archived
$isArchived = MachineEventArchive::where('root_event_id', $rootId)->exists();
```

### Archive Job

```php
use Tarfinlabs\EventMachine\Jobs\ArchiveMachineEventsJob;

// Dispatch archival job
ArchiveMachineEventsJob::dispatch($rootEventId);

// With custom compression level
ArchiveMachineEventsJob::dispatch($rootEventId, compressionLevel: 9);
```

## Per-Machine Configuration

Override settings for specific machines:

```php
// config/machine.php
'machine_overrides' => [
    // High-volume machine - archive sooner, compress more
    'logging_machine' => [
        'days_inactive' => 7,
        'compression_level' => 9,
    ],

    // Critical machine - archive later
    'financial_transaction' => [
        'days_inactive' => 365,
        'compression_level' => 6,
    ],

    // Never archive
    'audit_trail' => [
        'enabled' => false,
    ],
],
```

## Compression Levels

| Level | Compression | Speed | Use Case |
|-------|-------------|-------|----------|
| 0 | None | Fastest | Testing |
| 1-3 | Low | Fast | Real-time archival |
| 4-6 | Medium | Balanced | Most use cases |
| 7-9 | High | Slow | Storage-constrained |

Level 6 (default) provides a good balance of compression and speed.

## Retention Policy

Clean up old archives:

```php
// config/machine.php
'archive_retention_days' => 365, // Delete archives after 1 year
```

Or clean up manually:

```bash
php artisan machine:archive-status --cleanup-archive=01HXYZ...
```

## Monitoring

### Archive Statistics

```php
use Tarfinlabs\EventMachine\Models\MachineEventArchive;

// Total archived machines
$count = MachineEventArchive::count();

// Total storage savings
$stats = MachineEventArchive::selectRaw('
    SUM(original_size) as total_original,
    SUM(compressed_size) as total_compressed
')->first();

$savings = 1 - ($stats->total_compressed / $stats->total_original);
echo "Storage savings: " . round($savings * 100) . "%";

// Machines archived in last 24 hours
$recent = MachineEventArchive::where('archived_at', '>=', now()->subDay())
    ->count();
```

### Event Distribution

```php
// Events per machine type
$distribution = MachineEvent::selectRaw('machine_id, COUNT(*) as count')
    ->groupBy('machine_id')
    ->get();

// Archive candidates
$candidates = MachineEvent::selectRaw('root_event_id, MAX(created_at) as last_activity')
    ->groupBy('root_event_id')
    ->having('last_activity', '<', now()->subDays(30))
    ->count();
```

## Scheduling Archival

Add to your scheduler:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // Run archival daily at midnight
    $schedule->command('machine:archive-events --force')
        ->daily()
        ->at('00:00')
        ->withoutOverlapping();

    // Or dispatch to queue
    $schedule->command('machine:archive-events --force --queue')
        ->daily();
}
```

## Best Practices

### 1. Start with Conservative Settings

```php
'days_inactive' => 30,
'compression_level' => 6,
```

### 2. Monitor Before Adjusting

Track archive size and restore frequency before changing settings.

### 3. Use Queue for Large Archives

```bash
php artisan machine:archive-events --queue
```

### 4. Plan Retention Policy

```php
'archive_retention_days' => 365, // Don't keep forever
```

### 5. Override for Specific Machines

```php
'machine_overrides' => [
    'high_volume' => ['days_inactive' => 7],
    'critical' => ['days_inactive' => 90],
],
```

## Real-World Scenarios

### High-Volume Order Processing

For e-commerce systems with thousands of orders daily:

```php
// config/machine.php
'machine_overrides' => [
    'order_processing' => [
        'days_inactive' => 14,      // Archive completed orders faster
        'compression_level' => 6,    // Good balance
    ],
],
```

**Expected results:**
- 10,000 orders/day × 50 events/order = 500,000 events/day
- After 14 days archival: ~7M events in active table
- Compression savings: ~85% (from 2GB to 300MB archived)

### Compliance & Audit Systems

For financial services requiring long-term retention:

```php
'machine_overrides' => [
    'financial_transaction' => [
        'days_inactive' => 365,           // Keep active for 1 year
        'compression_level' => 9,          // Maximum compression
        'archive_retention_days' => 2555,  // 7 years retention
    ],
    'audit_log' => [
        'enabled' => false,  // Never archive audit trails
    ],
],
```

### Multi-Tenant SaaS Application

Different archival strategies per tenant tier:

```php
// Dynamic configuration based on tenant
$tenantConfig = match ($tenant->plan) {
    'enterprise' => ['days_inactive' => 90, 'archive_retention_days' => 365],
    'business'   => ['days_inactive' => 30, 'archive_retention_days' => 180],
    'starter'    => ['days_inactive' => 7, 'archive_retention_days' => 30],
};

$service = new ArchiveService($tenantConfig);
```

### Microservices with Shared Database

When multiple services share the event store:

```php
// Schedule different services at different times
$schedule->command('machine:archive-events --force')
    ->daily()
    ->at('02:00')  // Service A
    ->environments(['production']);

// Or use queue with service-specific priorities
ArchiveMachineEventsJob::dispatch()
    ->onQueue('archival-low-priority');
```

## Performance Tuning

### Compression Level Selection

| Scenario | Recommended Level | Rationale |
|----------|------------------|-----------|
| Real-time archival | 1-3 | Speed matters more than size |
| Nightly batch jobs | 6 | Good balance (default) |
| Storage-constrained | 9 | Maximum compression |
| SSD storage | 4-6 | Fast I/O compensates |
| HDD storage | 7-9 | Minimize disk usage |

### Batch Size Optimization

```php
// Small batches - less memory, more queries
'batch_size' => 50,   // Memory-constrained environments

// Large batches - faster, more memory
'batch_size' => 500,  // Dedicated archival workers
```

**Memory estimation:**
- ~100KB per machine in batch (average)
- 100 batch size ≈ 10MB memory
- 500 batch size ≈ 50MB memory

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

```php
// Instead of loading all eligible machines at once
$service = new ArchiveService();

// Process in chunks
$eligible = $service->getEligibleMachines(limit: 100);

while ($eligible->isNotEmpty()) {
    $rootIds = $eligible->pluck('root_event_id')->toArray();
    $service->batchArchive($rootIds);

    $eligible = $service->getEligibleMachines(limit: 100);
}
```

## Storage Estimation

### Calculate Current Storage

```php
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Support\CompressionManager;

// Sample 100 machines for estimation
$samples = MachineEvent::selectRaw('root_event_id, COUNT(*) as count')
    ->groupBy('root_event_id')
    ->limit(100)
    ->get();

$totalEstimate = 0;
$compressedEstimate = 0;

foreach ($samples as $sample) {
    $events = MachineEvent::where('root_event_id', $sample->root_event_id)
        ->get()
        ->toArray();

    $stats = CompressionManager::getCompressionStats($events);
    $totalEstimate += $stats['original_size'];
    $compressedEstimate += $stats['compressed_size'];
}

$ratio = $compressedEstimate / $totalEstimate;
echo "Estimated compression ratio: " . round($ratio * 100) . "%";
echo "Potential savings: " . round((1 - $ratio) * 100) . "%";
```

### Project Future Storage

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

## Troubleshooting

### Events Not Archiving

1. Check if archival is enabled:
   ```php
   dd(config('machine.archival.enabled'));
   ```

2. Verify `days_inactive` setting:
   ```php
   $service = new ArchiveService();
   $eligible = $service->getEligibleMachines();
   dd($eligible->count()); // Should be > 0
   ```

3. Check for recent activity on the machine:
   ```sql
   SELECT root_event_id, MAX(created_at) as last_activity
   FROM machine_events
   GROUP BY root_event_id
   HAVING last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY);
   ```

4. Look for errors in logs:
   ```bash
   grep -i "archive" storage/logs/laravel.log
   ```

### Slow Restoration

1. Consider reducing compression level for frequently accessed machines
2. Check database indexes on `machine_event_archives`
3. Monitor disk I/O during restoration
4. Check if restore cooldown is causing repeated restores

### High CPU During Archival

1. Lower compression level:
   ```env
   MACHINE_EVENTS_COMPRESSION_LEVEL=3
   ```

2. Reduce batch size:
   ```env
   MACHINE_EVENTS_ARCHIVAL_BATCH_SIZE=25
   ```

3. Run during off-peak hours via scheduler

### Archive Thrashing (Frequent Restore/Archive Cycles)

If machines are being archived and restored repeatedly:

```php
// Increase cooldown period
'restore_cooldown_hours' => 72, // 3 days

// Or increase days_inactive for specific machines
'machine_overrides' => [
    'frequently_accessed' => [
        'days_inactive' => 90,
    ],
],
```

### Disk Space Not Decreasing After Archival

Original events are deleted after archival, but:
1. MySQL may not immediately reclaim space - run `OPTIMIZE TABLE machine_events`
2. Check if transactions are holding locks
3. Verify archival completed successfully in logs

## API Reference

For detailed API documentation, see:

- [ArchiveService](/api-reference/archive-service) - Core archival operations
- [MachineEventArchive](/api-reference/machine-event-archive) - Archive model
- [CompressionManager](/api-reference/compression-manager) - Compression utilities
