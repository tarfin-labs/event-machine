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

### getEligibleInstances

```php
public function getEligibleInstances(int $limit = 100): Collection
```

Finds machines eligible for archival based on inactivity threshold and cooldown settings. Uses optimized NOT EXISTS queries for large tables.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$limit` | `int` | `100` | Maximum number of instances to return |

**Returns:** Collection of eligible machines with `root_event_id` and `machine_id`.

```php
$eligible = $service->getEligibleInstances(50);

foreach ($eligible as $machine) {
    echo "Machine: {$machine->machine_id}";
    echo "Root Event ID: {$machine->root_event_id}";
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

### restoreAndDelete

```php
public function restoreAndDelete(string $rootEventId): bool
```

Restores events from archive to `machine_events` table and deletes the archive. This method is used internally by the auto-restore feature when new events arrive for an archived machine.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$rootEventId` | `string` | The root event ID to restore |

**Returns:** `true` on success, `false` if archive not found.

::: warning Transaction Safety
This method runs within a database transaction with row-level locking to prevent race conditions when multiple events arrive simultaneously.
:::

```php
// Typically called automatically by MachineEvent::creating() hook
// Manual usage:
$success = $service->restoreAndDelete('01HQ3K5V7X8Y9Z');

if ($success) {
    // All archived events are now in machine_events
    // Archive has been deleted
}
```

**Internal Implementation:**
1. Acquires lock on archive row (`lockForUpdate()`)
2. Decompresses and restores events to `machine_events`
3. Deletes the archive
4. Commits transaction

## Configuration

The service uses configuration from `config/machine.php`:

```php
'archival' => [
    'enabled' => env('MACHINE_EVENTS_ARCHIVAL_ENABLED', true),
    'level' => env('MACHINE_EVENTS_COMPRESSION_LEVEL', 6),
    'threshold' => env('MACHINE_EVENTS_ARCHIVAL_THRESHOLD', 1000),
    'days_inactive' => env('MACHINE_EVENTS_ARCHIVAL_DAYS', 30),
    'restore_cooldown_hours' => env('MACHINE_EVENTS_RESTORE_COOLDOWN_HOURS', 24),
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

    public function archive(string $rootEventId)
    {
        return $this->archiveService->archiveMachine($rootEventId);
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
