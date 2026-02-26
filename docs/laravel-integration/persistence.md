# Persistence

EventMachine provides full event sourcing with automatic persistence to the database.

## `MachineEvent` Model

All events are stored in the `machine_events` table:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Models\MachineEvent;

// Query events
$events = MachineEvent::where('machine_id', 'order')
    ->where('root_event_id', $rootId)
    ->orderBy('sequence_number')
    ->get();
```

### Event Properties

| Column | Type | Description |
|--------|------|-------------|
| `id` | ULID | Unique event identifier |
| `sequence_number` | int | Order in event chain |
| `created_at` | datetime | Event timestamp |
| `machine_id` | string | Machine identifier |
| `machine_value` | array | State after event |
| `root_event_id` | ULID | Root of event chain |
| `source` | enum | `internal` or `external` |
| `type` | string | Event type name |
| `payload` | array | Event data |
| `version` | int | Event version |
| `context` | array | Context changes (incremental) |
| `meta` | array | State metadata |

## Automatic Persistence

Events are automatically persisted when using the `Machine->send()` method:

<!-- doctest-attr: ignore -->
```php
$machine = OrderMachine::create();

$machine->send(['type' => 'SUBMIT']);
// Event automatically saved to database

$machine->send(['type' => 'APPROVE']);
// Another event saved
```

## Disabling Persistence

For ephemeral machines or testing:

<!-- doctest-attr: ignore -->
```php
MachineDefinition::define(
    config: [
        'should_persist' => false,
        'initial' => 'idle',
        'states' => [...],
    ],
);
```

## Event History

Access history through the state:

<!-- doctest-attr: ignore -->
```php
$history = $machine->state->history;

// EventCollection methods
$history->count();
$history->first();
$history->last();
$history->pluck('type');
$history->where('source', 'external');
```

## Root Event ID

The `root_event_id` links all events in a machine instance:

<!-- doctest-attr: ignore -->
```php
$machine = OrderMachine::create();

// First event becomes the root
$machine->send(['type' => 'SUBMIT']);
$rootId = $machine->state->history->first()->root_event_id;

// All subsequent events share the same root_event_id
$machine->send(['type' => 'APPROVE']);
$machine->send(['type' => 'COMPLETE']);

// All events in this machine have the same root_event_id
$events = MachineEvent::where('root_event_id', $rootId)->get();
$events->count(); // 3 (plus internal events)
```

## State Restoration

Restore a machine from its root event ID:

<!-- doctest-attr: ignore -->
```php
// Save root ID somewhere (e.g., model attribute)
$rootId = $machine->state->history->first()->root_event_id;

// Later: Restore the machine
$restored = OrderMachine::create(state: $rootId);

// State is fully reconstructed
$restored->state->matches('approved'); // true
$restored->state->context->orderId; // Original value
```

## Incremental Context Storage

Context is stored incrementally to minimize database size:

<!-- doctest-attr: ignore -->
```php
// First event: Full context
{
    "context": {
        "orderId": "order-123",
        "items": [],
        "total": 0,
        "status": "pending"
    }
}

// Second event: Only changes
{
    "context": {
        "items": [{"id": 1, "price": 100}],
        "total": 100
    }
}

// Third event: Only changes
{
    "context": {
        "status": "submitted"
    }
}
```

During restoration, context is reconstructed by merging all changes.

### Context Merge Strategy

EventMachine uses **deep merge** for context reconstruction:

1. Start with initial context (first event)
2. For each subsequent event, merge context changes recursively
3. **Arrays are replaced, not merged** - use nested objects for partial updates

<!-- doctest-attr: ignore -->
```php
// Event 1: Initial context
{ "items": [{"id": 1}], "meta": {"created": "2024-01-01"} }

// Event 2: Add item, update meta
{ "items": [{"id": 1}, {"id": 2}], "meta": {"updated": "2024-01-02"} }

// Final merged context:
{
    "items": [{"id": 1}, {"id": 2}],   // Array replaced entirely
    "meta": {
        "created": "2024-01-01",        // Old key preserved
        "updated": "2024-01-02"         // New key added
    }
}
```

::: warning Array Replacement
Arrays are **replaced**, not merged. If you need to append items, always include the full array in the context change, or store items in a nested object structure.
:::

## Transactional Events

Events can be wrapped in database transactions:

<!-- doctest-attr: ignore -->
```php
class CriticalEvent extends EventBehavior
{
    public bool $isTransactional = true; // Default

    public static function getType(): string
    {
        return 'CRITICAL_OPERATION';
    }
}
```

If any action fails, all database changes roll back:

<!-- doctest-attr: ignore -->
```php
try {
    $machine->send(new CriticalEvent());
} catch (Exception $e) {
    // All changes rolled back, including machine events
}
```

### Non-Transactional Events

For performance-critical operations:

<!-- doctest-attr: ignore -->
```php
class FastEvent extends EventBehavior
{
    public bool $isTransactional = false;
}
```

::: warning
Non-transactional events won't roll back on failure. Use with caution.
:::

## Distributed Locking

EventMachine uses distributed locking to prevent concurrent modifications:

<!-- doctest-attr: ignore -->
```php
// Machine A
$machineA = OrderMachine::create(state: $rootId);

// Machine B (same root_event_id)
$machineB = OrderMachine::create(state: $rootId);

// Concurrent access
$machineA->send(['type' => 'APPROVE']); // Acquires lock

try {
    $machineB->send(['type' => 'REJECT']); // Waits for lock or throws
} catch (MachineAlreadyRunningException $e) {
    // Handle concurrent access
}
```

### Lock Configuration

Lock timeout is 60 seconds with a 5-second wait:

<!-- doctest-attr: ignore -->
```php
// Behind the scenes
Cache::lock("machine:{$rootEventId}", 60)->block(5, function () {
    // Process event
});
```

## Internal Events

EventMachine generates internal events for tracking:

<!-- doctest-attr: ignore -->
```php
$history = $machine->state->history;

// Filter internal events
$internal = $history->where('source', 'internal');

// Event types include:
// - 'order.machine.start'
// - 'order.state.pending.enter'
// - 'order.transition.start'
// - 'order.action.processOrder.finish'
// - 'order.guard.isValid.pass'
```

## Querying Events

### By Machine Instance

<!-- doctest-attr: ignore -->
```php
$events = MachineEvent::where('root_event_id', $rootId)
    ->orderBy('sequence_number')
    ->get();
```

### By Machine Type

<!-- doctest-attr: ignore -->
```php
$events = MachineEvent::where('machine_id', 'order')
    ->latest()
    ->take(100)
    ->get();
```

### By Event Type

<!-- doctest-attr: ignore -->
```php
$submits = MachineEvent::where('type', 'SUBMIT')
    ->where('source', 'external')
    ->get();
```

### By Date Range

<!-- doctest-attr: ignore -->
```php
$events = MachineEvent::whereBetween('created_at', [$start, $end])
    ->get();
```

### External Events Only

<!-- doctest-attr: ignore -->
```php
$external = MachineEvent::where('root_event_id', $rootId)
    ->where('source', 'external')
    ->get();
```

## Manual Persistence

For MachineDefinition (without Machine class):

<!-- doctest-attr: ignore -->
```php
$definition = MachineDefinition::define([...]);
$state = $definition->getInitialState();

// Transition without persistence
$newState = $definition->transition(['type' => 'SUBMIT'], $state);

// Use Machine class for automatic persistence
```

## Database Optimization

### Indexing

The migration creates indexes on:
- `id` (primary)
- `root_event_id`
- `machine_id`
- `created_at`

### Archival

For high-volume machines, enable archival:

<!-- doctest-attr: ignore -->
```php
// config/machine.php
'archival' => [
    'enabled' => true,
    'days_inactive' => 30,
    'level' => 6,
],
```

See [Archival](/laravel-integration/archival) for details.

## Best Practices

### 1. Store Root Event ID

<!-- doctest-attr: ignore -->
```php
class Order extends Model
{
    protected function machines(): array
    {
        return [
            'status' => OrderMachine::class . ':order',
        ];
    }
}

// Column 'status' stores the root_event_id
```

### 2. Use Transactions for Critical Operations

<!-- doctest-attr: ignore -->
```php
class PaymentEvent extends EventBehavior
{
    public bool $isTransactional = true;
}
```

### 3. Query Efficiently

<!-- doctest-attr: ignore -->
```php
// Good - indexed query
MachineEvent::where('root_event_id', $id)->get();

// Avoid - full table scan
MachineEvent::where('payload->orderId', 123)->get();
```

### 4. Archive Old Events

```bash
php artisan machine:archive-events --days=30
```
