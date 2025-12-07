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

## Troubleshooting

### Events Not Archiving

1. Check if archival is enabled
2. Verify `days_inactive` setting
3. Check for recent activity on the machine
4. Look for errors in logs

### Slow Restoration

1. Consider reducing compression level
2. Check database indexes
3. Monitor disk I/O

### High CPU During Archival

1. Lower compression level
2. Reduce batch size
3. Run during off-peak hours
