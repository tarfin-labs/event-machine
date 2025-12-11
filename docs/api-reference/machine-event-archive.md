# MachineEventArchive

The `MachineEventArchive` model represents archived machine events that have been compressed and stored separately from the main `machine_events` table.

**Namespace:** `Tarfinlabs\EventMachine\Models`

**Extends:** `Illuminate\Database\Eloquent\Model`

## Database Schema

| Column | Type | Description |
|--------|------|-------------|
| `root_event_id` | `string` | Primary key (ULID) |
| `machine_id` | `string` | Machine identifier (indexed) |
| `events_data` | `binary` | Compressed event data (LONGTEXT) |
| `event_count` | `integer` | Number of archived events |
| `original_size` | `bigInteger` | Size before compression (bytes) |
| `compressed_size` | `bigInteger` | Size after compression (bytes) |
| `compression_level` | `tinyInteger` | Compression level used (0-9) |
| `archived_at` | `datetime` | When events were archived (indexed) |
| `first_event_at` | `datetime` | Timestamp of first event |
| `last_event_at` | `datetime` | Timestamp of last event |
| `restore_count` | `integer` | Times this archive was restored |
| `last_restored_at` | `datetime` | Last restoration timestamp (indexed) |

## Properties

```php
/** @var string The unique identifier of the root event (primary key) */
public string $root_event_id;

/** @var string The unique identifier of the machine */
public string $machine_id;

/** @var string The compressed binary data containing all events */
public string $events_data;

/** @var int The number of events archived in this record */
public int $event_count;

/** @var int The size before compression (bytes) */
public int $original_size;

/** @var int The size after compression (bytes) */
public int $compressed_size;

/** @var int The compression level used (0-9) */
public int $compression_level;

/** @var Carbon The timestamp when the events were archived */
public Carbon $archived_at;

/** @var Carbon The timestamp of the first event */
public Carbon $first_event_at;

/** @var Carbon The timestamp of the last event */
public Carbon $last_event_at;

/** @var int The number of times this archive was restored */
public int $restore_count;

/** @var Carbon|null The timestamp when last restored */
public ?Carbon $last_restored_at;
```

## Static Methods

### archiveEvents

```php
public static function archiveEvents(
    EventCollection $events,
    ?int $compressionLevel = null
): self
```

Creates an archive from a collection of machine events.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$events` | `EventCollection` | required | Collection of events to archive |
| `$compressionLevel` | `?int` | `null` | Compression level (0-9), uses config default |

**Returns:** New `MachineEventArchive` instance.

**Throws:** `InvalidArgumentException` if events collection is empty, `RuntimeException` if compression fails.

```php
use Tarfinlabs\EventMachine\Models\MachineEventArchive;
use Tarfinlabs\EventMachine\EventCollection;

$events = MachineEvent::where('root_event_id', $rootEventId)->get();
$collection = new EventCollection($events->all());

$archive = MachineEventArchive::archiveEvents($collection, compressionLevel: 9);

echo "Archived {$archive->event_count} events";
echo "Compression: {$archive->savings_percent}%";
```

## Instance Methods

### restoreEvents

```php
public function restoreEvents(): EventCollection
```

Decompresses and restores events from the archive.

**Returns:** `EventCollection` containing all archived events as `MachineEvent` instances.

**Throws:** `RuntimeException` if decompression fails.

```php
$archive = MachineEventArchive::find($rootEventId);
$events = $archive->restoreEvents();

foreach ($events as $event) {
    echo "{$event->type}: {$event->created_at}";
}
```

## Computed Attributes

### compression_ratio

```php
public function getCompressionRatioAttribute(): float
```

Returns the compression ratio (0.0 - 1.0). Lower values indicate better compression.

```php
$archive = MachineEventArchive::find($rootEventId);

echo $archive->compression_ratio; // 0.15 = compressed to 15% of original
```

### savings_percent

```php
public function getSavingsPercentAttribute(): float
```

Returns the space savings as a percentage (0-100%).

```php
$archive = MachineEventArchive::find($rootEventId);

echo $archive->savings_percent; // 85.0 = saved 85% of space
```

## Query Scopes

### forMachine

```php
public function scopeForMachine($query, string $machineId)
```

Filters archives by machine ID.

```php
$archives = MachineEventArchive::forMachine('order-machine')
    ->get();
```

### archivedBetween

```php
public function scopeArchivedBetween($query, Carbon $from, Carbon $to)
```

Filters archives by date range.

```php
use Carbon\Carbon;

$archives = MachineEventArchive::archivedBetween(
    Carbon::now()->subMonth(),
    Carbon::now()
)->get();
```

## Usage Examples

### Query Archive Statistics

```php
// Get overall statistics
$stats = MachineEventArchive::query()
    ->selectRaw('
        COUNT(*) as total,
        SUM(event_count) as events,
        SUM(original_size) as original_bytes,
        SUM(compressed_size) as compressed_bytes
    ')
    ->first();

$savingsPercent = (($stats->original_bytes - $stats->compressed_bytes)
    / $stats->original_bytes) * 100;

echo "Total archives: {$stats->total}";
echo "Total events: {$stats->events}";
echo "Space saved: {$savingsPercent}%";
```

### Find Frequently Restored Archives

```php
// Archives restored more than 5 times might need different handling
$frequentlyRestored = MachineEventArchive::query()
    ->where('restore_count', '>', 5)
    ->orderByDesc('restore_count')
    ->get();
```

### Get Recent Archival Activity

```php
$recentArchives = MachineEventArchive::query()
    ->where('archived_at', '>', now()->subDay())
    ->orderByDesc('archived_at')
    ->limit(10)
    ->get();
```

### Calculate Storage Impact

```php
$machineId = 'order-processing';

$impact = MachineEventArchive::forMachine($machineId)
    ->selectRaw('
        SUM(original_size) as would_be,
        SUM(compressed_size) as actual
    ')
    ->first();

$savedMB = ($impact->would_be - $impact->actual) / 1024 / 1024;
echo "Storage saved for {$machineId}: {$savedMB} MB";
```

## Model Configuration

```php
// Primary key is root_event_id (ULID string)
protected $primaryKey = 'root_event_id';
protected $keyType = 'string';
public $incrementing = false;

// No timestamps (uses archived_at instead)
public $timestamps = false;
```

## See Also

- [ArchiveService](/api-reference/archive-service) - Service for managing archives
- [CompressionManager](/api-reference/compression-manager) - Compression utilities
- [Archival & Compression Guide](/laravel-integration/archival-compression) - Configuration and usage
