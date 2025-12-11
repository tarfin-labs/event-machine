# ArchiveService

The `ArchiveService` class manages the archival and restoration of machine events. It provides methods for compressing, storing, and retrieving events from the archive table.

**Namespace:** `Tarfinlabs\EventMachine\Services`

## Constructor

```php
public function __construct(array $config = [])
```

Creates a new ArchiveService instance with optional configuration overrides.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$config` | `array` | `[]` | Configuration overrides merged with `config('machine.archival')` |

## Methods

### archiveMachine

```php
public function archiveMachine(
    string $rootEventId,
    ?int $compressionLevel = null
): ?MachineEventArchive
```

Archives all events for a specific machine. Events are moved to the archive table and removed from the active `machine_events` table after successful archival.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$rootEventId` | `string` | required | The root event ID identifying the machine |
| `$compressionLevel` | `?int` | `null` | Compression level (0-9), uses config default if null |

**Returns:** `MachineEventArchive` on success, `null` if archival is disabled, already archived, or no events found.

```php
$service = new ArchiveService();
$archive = $service->archiveMachine('01HQ3K5V7X8Y9Z');

if ($archive) {
    echo "Archived {$archive->event_count} events";
    echo "Compression ratio: {$archive->compression_ratio}";
}
```

### restoreMachine

```php
public function restoreMachine(
    string $rootEventId,
    bool $keepArchive = true
): ?EventCollection
```

Restores events from the archive. The Machine class calls this automatically when events aren't found in the active table (transparent restoration).

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$rootEventId` | `string` | required | The root event ID identifying the machine |
| `$keepArchive` | `bool` | `true` | Whether to keep the archive after restoration |

**Returns:** `EventCollection` on success, `null` if archive not found or restoration fails.

```php
$events = $service->restoreMachine('01HQ3K5V7X8Y9Z');

// Or remove archive after restoration
$events = $service->restoreMachine('01HQ3K5V7X8Y9Z', keepArchive: false);
```

### getEligibleMachines

```php
public function getEligibleMachines(int $limit = 100): Collection
```

Finds machines eligible for archival based on inactivity threshold and cooldown settings.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$limit` | `int` | `100` | Maximum number of machines to return |

**Returns:** Collection of eligible machines with `root_event_id`, `machine_id`, `last_activity`, and `event_count`.

```php
$eligible = $service->getEligibleMachines(50);

foreach ($eligible as $machine) {
    echo "{$machine->machine_id}: {$machine->event_count} events";
    echo "Last activity: {$machine->last_activity}";
}
```

### batchArchive

```php
public function batchArchive(
    array $rootEventIds,
    ?int $compressionLevel = null
): array
```

Archives multiple machines in a single operation with cooldown tracking.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$rootEventIds` | `array` | required | Array of root event IDs to archive |
| `$compressionLevel` | `?int` | `null` | Compression level (0-9) |

**Returns:** Array with `archived`, `failed`, and `skipped` keys.

```php
$results = $service->batchArchive([
    '01HQ3K5V7X8Y9Z',
    '01HQ3K6W8Y9Z0A',
    '01HQ3K7X9Z0A1B',
]);

echo "Archived: " . count($results['archived']);
echo "Failed: " . count($results['failed']);
echo "Skipped (cooldown): " . count($results['skipped']);
```

### getArchiveStats

```php
public function getArchiveStats(): array
```

Returns comprehensive statistics about the archive system.

**Returns:** Array containing:

| Key | Type | Description |
|-----|------|-------------|
| `enabled` | `bool` | Whether archival is enabled |
| `total_archives` | `int` | Number of archived machines |
| `total_events_archived` | `int` | Total events in archives |
| `total_space_saved` | `int` | Bytes saved through compression |
| `total_space_saved_mb` | `float` | MB saved through compression |
| `average_compression_ratio` | `float` | Average ratio (0.0-1.0, lower is better) |
| `space_savings_percent` | `float` | Percentage of space saved |

```php
$stats = $service->getArchiveStats();

echo "Total archives: {$stats['total_archives']}";
echo "Space saved: {$stats['total_space_saved_mb']} MB";
echo "Average compression: " . round($stats['average_compression_ratio'] * 100) . "%";
```

### canReArchive

```php
public function canReArchive(string $rootEventId): bool
```

Checks if a machine can be re-archived based on cooldown settings. Machines that were recently restored have a cooldown period before they can be archived again.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$rootEventId` | `string` | The root event ID to check |

**Returns:** `true` if the machine can be archived, `false` if in cooldown period.

```php
if ($service->canReArchive('01HQ3K5V7X8Y9Z')) {
    $service->archiveMachine('01HQ3K5V7X8Y9Z');
}
```

### cleanupOldArchives

```php
public function cleanupOldArchives(): int
```

Removes old archives based on the `archive_retention_days` configuration. Archives older than the retention period are permanently deleted.

**Returns:** Number of archives deleted.

```php
// In a scheduled command
$deleted = $service->cleanupOldArchives();
logger()->info("Cleaned up {$deleted} old archives");
```

## Configuration

The service uses configuration from `config/machine.php`:

```php
'archival' => [
    'enabled' => env('MACHINE_EVENTS_ARCHIVAL_ENABLED', true),
    'level' => env('MACHINE_EVENTS_COMPRESSION_LEVEL', 6),
    'threshold' => env('MACHINE_EVENTS_ARCHIVAL_THRESHOLD', 1000),
    'days_inactive' => env('MACHINE_EVENTS_ARCHIVAL_DAYS', 30),
    'restore_cooldown_hours' => env('MACHINE_EVENTS_RESTORE_COOLDOWN_HOURS', 24),
    'archive_retention_days' => env('MACHINE_EVENTS_ARCHIVE_RETENTION_DAYS', null),
],
```

## Usage with Dependency Injection

```php
use Tarfinlabs\EventMachine\Services\ArchiveService;

class ArchiveController extends Controller
{
    public function __construct(
        protected ArchiveService $archiveService
    ) {}

    public function stats()
    {
        return $this->archiveService->getArchiveStats();
    }
}
```

## Error Handling

The service logs errors but doesn't throw exceptions for most operations. Check return values:

```php
$archive = $service->archiveMachine($rootEventId);

if ($archive === null) {
    // Check logs for details - could be:
    // - Archival disabled
    // - Already archived
    // - No events found
    // - Compression failed
}
```

## See Also

- [MachineEventArchive](/api-reference/machine-event-archive) - Archive model
- [CompressionManager](/api-reference/compression-manager) - Compression utilities
- [Archival & Compression Guide](/laravel-integration/archival-compression) - Configuration and usage
