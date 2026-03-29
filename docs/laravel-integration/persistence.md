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
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
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

```php
use Tarfinlabs\EventMachine\Behavior\EventBehavior; // [!code hide]
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
use Tarfinlabs\EventMachine\Behavior\EventBehavior; // [!code hide]
class FastEvent extends EventBehavior
{
    public bool $isTransactional = false;
}
```

::: warning
Non-transactional events won't roll back on failure. Use with caution.
:::

## Distributed Locking

EventMachine uses a database-backed mutex (`MachineLockManager` + `machine_locks` table) to prevent concurrent state mutations. A unique constraint on `root_event_id` guarantees that only one process can hold the lock for a given machine instance at any time.

### When Locking is Active

Locking is enabled when either condition is true:

- **Async queue driver** (`config('queue.default') !== 'sync'`) -- concurrent workers can mutate the same machine
- **Parallel dispatch enabled** (`config('machine.parallel_dispatch.enabled')` is `true`) -- even with sync queue, tests can verify lock behavior

When using the sync queue driver without parallel dispatch, there is no concurrency risk, so locking is skipped entirely to avoid overhead.

### Lock Acquisition

`Machine::send()` acquires a lock before processing the event:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Locks\MachineLockManager;

// Behind the scenes in Machine::send()
$lockHandle = MachineLockManager::acquire(
    rootEventId: $rootEventId,
    timeout: 0,   // Non-blocking — fails immediately if lock is held
    ttl: 60,      // Lock expires after 60 seconds (stale lock protection)
    context: 'send',
);
```

- **`timeout: 0`** -- non-blocking mode. If the lock is already held, a `MachineLockTimeoutException` is thrown immediately (wrapped as `MachineAlreadyRunningException`).
- **`ttl: 60`** -- the lock auto-expires after 60 seconds. This protects against stale locks from crashed processes. Configurable via `config('machine.parallel_dispatch.lock_ttl')`.

The lock is always released in a `finally` block after `persist()` and validation guard handling, ensuring cleanup even when exceptions occur.

### Concurrent Access Example

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Exceptions\MachineAlreadyRunningException;

// Two queue workers restore the same machine instance
$machineA = OrderMachine::create(state: $rootId);
$machineB = OrderMachine::create(state: $rootId);

// Worker A acquires the lock and processes the event
$machineA->send(['type' => 'APPROVE']);

// Worker B attempts to send concurrently — lock is held, fails immediately
try {
    $machineB->send(['type' => 'REJECT']);
} catch (MachineAlreadyRunningException $e) {
    // Machine is currently being mutated by another process.
    // The job will be retried by the queue worker.
}
```

### Re-entrant Lock Support

Sync dispatch chains can cause the same process to call `send()` on a machine that is already locked by itself -- for example: `send()` -> `ChildMachineJob` -> `ChildMachineCompletionJob` -> `send()` on the same parent.

`Machine::$heldLockIds` tracks which `root_event_id` values are locked by the current process. When a re-entrant call is detected, lock acquisition is skipped to prevent deadlock.

### Stale Lock Cleanup

Expired locks (`expires_at < now()`) are cleaned up automatically during lock acquisition. This cleanup is rate-limited to once every 5 seconds to avoid thundering herd effects when many workers compete simultaneously.

### HTTP Endpoints

HTTP endpoints handle `MachineAlreadyRunningException` gracefully, returning an appropriate error response. See [Endpoints](/laravel-integration/endpoints) for details.

When `block()` times out, `MachineLockTimeoutException` is thrown internally. `Machine` catches it and re-throws as `MachineAlreadyRunningException` — which is the exception callers see. In parallel dispatch mode, `MachineLockTimeoutException` is also caught by `ListenerJob`, which releases the job back to the queue for retry.

Lock configuration for parallel dispatch is in `config/machine.php`:

<!-- doctest-attr: ignore -->
```php
'parallel_dispatch' => [
    'lock_timeout' => 60,  // seconds before lock expires
    'lock_ttl'     => 5,   // seconds to wait for lock acquisition
],
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
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
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

```php
use Illuminate\Database\Eloquent\Model; // [!code hide]
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
use Tarfinlabs\EventMachine\Behavior\EventBehavior; // [!code hide]
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

## Testing Persistence

<!-- doctest-attr: ignore -->
```php
// Disable persistence for fast unit tests
OrderMachine::test()
    ->withoutPersistence()
    ->send('SUBMIT')
    ->assertState('submitted');

// Test persist/restore cycle
$machine = OrderMachine::create();
$machine->send(['type' => 'SUBMIT']);
$machine->persist();

$rootEventId = $machine->state->history->first()->root_event_id;
$restored = OrderMachine::create(state: $rootEventId);
expect($restored->state->matches('submitted'))->toBeTrue();
```

::: tip Full Testing Guide
See [Persistence Testing](/testing/persistence-testing) for more examples.
:::

::: tip Detailed Guide
For comprehensive design guidelines with Do/Don't examples, see [Testing Strategy](/best-practices/testing-strategy).
:::
