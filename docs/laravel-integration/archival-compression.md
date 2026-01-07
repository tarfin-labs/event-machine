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

        'advanced' => [
            // Max workflows (unique root_event_ids) to dispatch per scheduler run
            'dispatch_limit' => env('MACHINE_EVENTS_ARCHIVAL_DISPATCH_LIMIT', 50),

            // Queue name (null = default queue)
            'queue' => env('MACHINE_EVENTS_ARCHIVAL_QUEUE'),
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
| `dispatch_limit` | `50` | Max workflows per scheduler run |
| `queue` | `null` | Queue name (null = default) |

## Archival Commands

### Archive Events

The archival command uses a **fan-out pattern**: it finds eligible machines and dispatches individual `ArchiveSingleMachineJob` for each. This enables parallel processing across queue workers.

```bash
# Dispatch archival jobs to queue (default)
php artisan machine:archive-events

# Preview what would be dispatched
php artisan machine:archive-events --dry-run

# Run synchronously (testing only)
php artisan machine:archive-events --sync

# Custom dispatch limit per run
php artisan machine:archive-events --dispatch-limit=100
```

### Check Archive Status

```bash
# Show summary
php artisan machine:archive-status

# Restore archived events
php artisan machine:archive-status --restore=01HXYZ...
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
$archive->compression_ratio;  // e.g., 0.15 (15% of original size)

// Calculate savings percentage
$savingsPercent = (1 - $archive->compression_ratio) * 100;  // e.g., 85% savings
```

## Transparent Restoration

When you access an archived machine, events are restored in-memory without modifying the database:

```php
// Events were archived 60 days ago
$machine = Machine::withDefinition(OrderMachine::definition());
$state = $machine->restoreStateFromRootEventId($archivedRootId);

// Behind the scenes:
// 1. Check if events exist in machine_events
// 2. If not, check machine_event_archives
// 3. Decompress and restore events IN-MEMORY (no DB write)
// 4. Track restoration (restore_count, last_restored_at)
// 5. Return state - archive remains intact

$state->matches('completed'); // Works normally
```

::: tip Transparent = Read-Only
Transparent restoration reads from the archive but doesn't write events back to `machine_events`. The archive stays intact, allowing repeated access without data duplication.
:::

## Auto-Restore (v3)

When new events are created for an archived machine, EventMachine automatically restores all archived events and deletes the archive. This eliminates "split state" where some events are archived and some are active.

### How It Works

```php
// Machine was archived 60 days ago with 100 events
// Archive exists, machine_events is empty for this root_event_id

// Now a new event arrives...
$machine->send(['type' => 'PAYMENT_RECEIVED', 'amount' => 500]);

// Behind the scenes (automatic):
// 1. MachineEvent::creating() hook detects archive exists
// 2. ArchiveService::restoreAndDelete() is called
// 3. All 100 archived events are restored to machine_events
// 4. Archive is deleted
// 5. New event is saved (now event #101)
// 6. All events are together in machine_events
```

### Lifecycle Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│  NORMAL FLOW                                                     │
│  machine_events ──(30 days inactive)──► archive                  │
│                                          │                       │
│                        ┌─────────────────┴─────────────────┐     │
│                        │                                   │     │
│                        ▼                                   ▼     │
│                 Transparent Read               New Event Arrives │
│                 (keepArchive=true)             (auto-restore)    │
│                        │                                   │     │
│                        ▼                                   ▼     │
│                 In-memory state              Restore + Delete    │
│                 Archive intact              Events back to DB    │
│                                                    │             │
│                                                    ▼             │
│                                             machine_events       │
│                                             (all events united)  │
│                                                    │             │
│                                                    ▼             │
│                                             (30 days inactive)   │
│                                                    │             │
│                                                    ▼             │
│                                                archive           │
└─────────────────────────────────────────────────────────────────┘
```

### Benefits

| Scenario | Without Auto-Restore | With Auto-Restore |
|----------|---------------------|-------------------|
| New event for archived machine | Split state problem | Events unified automatically |
| Query all events | Must check both tables | Single table query |
| Event ordering | Complex merge logic | Natural sequence order |
| Archive cleanup | Manual intervention | Automatic |

### Configuration

Auto-restore is always enabled when archival is enabled. No additional configuration needed.

```php
// config/machine.php
'archival' => [
    'enabled' => true,  // Auto-restore is part of archival
],
```

### Cooldown Period

After auto-restore, the machine won't be eligible for re-archival until the cooldown period passes and the inactivity threshold is met again.

```php
'archival' => [
    'days_inactive' => 30,              // 30 days before archival
    'restore_cooldown_hours' => 24,     // 24 hours after restore
],
```

**Example Timeline:**
1. Machine archived on Jan 1
2. New event arrives on Jan 15 → Auto-restore triggered
3. Cooldown until Jan 16
4. If no activity until Feb 15 → Eligible for re-archival

## Programmatic Archival

### Archive Service

```php
use Tarfinlabs\EventMachine\Services\ArchiveService;

$service = new ArchiveService();

// Archive a specific machine
$archive = $service->archiveMachine($rootEventId);

// Transparent restore (in-memory, keeps archive)
$events = $service->restoreMachine($rootEventId, keepArchive: true);

// Full restore (writes to DB, deletes archive)
$events = $service->restoreMachine($rootEventId, keepArchive: false);

// Restore and delete (used by auto-restore)
$success = $service->restoreAndDelete($rootEventId);

// Check if archived
$isArchived = MachineEventArchive::where('root_event_id', $rootId)->exists();
```

### Archive Job

```php
use Tarfinlabs\EventMachine\Jobs\ArchiveSingleMachineJob;

// Dispatch archival job for a specific machine
ArchiveSingleMachineJob::dispatch($rootEventId);

// With custom queue
ArchiveSingleMachineJob::dispatch($rootEventId)
    ->onQueue('archival');
```

## Compression Levels

| Level | Compression | Speed | Use Case |
|-------|-------------|-------|----------|
| 0 | None | Fastest | Testing |
| 1-3 | Low | Fast | Real-time archival |
| 4-6 | Medium | Balanced | Most use cases |
| 7-9 | High | Slow | Storage-constrained |

Level 6 (default) provides a good balance of compression and speed.

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
    // Fan-out pattern: dispatches individual jobs for each eligible machine
    $schedule->command('machine:archive-events')
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground();
}
```

### Tuning for Your Workload

The archival throughput depends on:
- **dispatch_limit**: How many workflows to dispatch per run (default: 50)
- **Scheduler frequency**: How often to run the command
- **Queue workers**: How many workers processing the archival queue

```bash
# Example: 50 workflows × 12 runs/hour × 4 workers = 2400 workflows/hour

# For 100GB databases, recommended settings:
MACHINE_EVENTS_ARCHIVAL_DISPATCH_LIMIT=100
MACHINE_EVENTS_ARCHIVAL_QUEUE=archival

# Then run dedicated workers:
php artisan queue:work --queue=archival --tries=3
```

## Best Practices

### 1. Start with Conservative Settings

```php
'days_inactive' => 30,
'compression_level' => 6,
```

### 2. Monitor Before Adjusting

Track archive size and restore frequency before changing settings.

### 3. Use Dedicated Queue for Large Datasets

```env
MACHINE_EVENTS_ARCHIVAL_QUEUE=archival
```

```bash
php artisan queue:work --queue=archival
```

## Real-World Scenarios

### High-Volume Order Processing

For e-commerce systems with thousands of orders daily:

```php
// config/machine.php
'days_inactive' => 14,       // Archive completed orders after 2 weeks
'level' => 6,                // Good compression balance
```

**Expected results:**
- 10,000 orders/day × 50 events/order = 500,000 events/day
- After 14 days archival: ~7M events in active table
- Compression savings: ~85% (from 2GB to 300MB archived)

### Multi-Tenant SaaS Application

Different archival strategies per tenant tier:

```php
// Dynamic configuration based on tenant
$tenantConfig = match ($tenant->plan) {
    'enterprise' => ['days_inactive' => 90],
    'business'   => ['days_inactive' => 30],
    'starter'    => ['days_inactive' => 7],
};

$service = new ArchiveService($tenantConfig);
```

### Microservices with Shared Database

When multiple services share the event store:

```php
// Schedule different services at different times
$schedule->command('machine:archive-events')
    ->everyFiveMinutes()
    ->at('02:00')  // Service A
    ->environments(['production'])
    ->onOneServer();

// Or use queue with service-specific priorities
ArchiveSingleMachineJob::dispatch($rootEventId)
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

### Dispatch Limit Optimization

The `dispatch_limit` controls how many workflows (unique root_event_ids) are found and dispatched per scheduler run:

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
   $eligible = $service->getEligibleInstances();
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

2. Reduce dispatch limit:
   ```env
   MACHINE_EVENTS_ARCHIVAL_DISPATCH_LIMIT=25
   ```

3. Run during off-peak hours via scheduler

### Archive Thrashing (Frequent Restore/Archive Cycles)

If machines are being archived and restored repeatedly:

```php
// Increase cooldown period
'restore_cooldown_hours' => 72, // 3 days

// Or increase days_inactive globally
'days_inactive' => 90, // Longer period before archival
```

### Disk Space Not Decreasing After Archival

Original events are deleted after archival, but:
1. MySQL may not immediately reclaim space - run `OPTIMIZE TABLE machine_events`
2. Check if transactions are holding locks
3. Verify archival completed successfully in logs

