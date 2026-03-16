# Persistence Testing

Testing EventMachine's database persistence and event sourcing capabilities.

## Stateless Testing

Use stateless tests when you only need to verify state machine logic — not what gets written to the database. Prefer this approach for unit-level tests where database setup would add overhead without adding value.

<!-- doctest-attr: ignore -->
```php
// No DB, no migrations needed
OrderMachine::test(['amount' => 100])
    ->withoutPersistence()
    ->send('SUBMIT')
    ->assertState('awaiting_payment');

// Inline definitions are always stateless
TestMachine::define(config: [
    'initial' => 'idle',
    'states' => [
        'idle' => ['on' => ['GO' => ['target' => 'done']]],
        'done' => [],
    ],
])
    ->send('GO')
    ->assertState('done');
```

## Test Setup

Persistence tests require a working database. The two setup steps below ensure every test starts with a clean schema.

### RefreshDatabase Trait

Add `RefreshDatabase` to any test class that writes to the database. It wraps each test in a transaction and rolls it back afterwards, ensuring tests do not bleed state into one another.

<!-- doctest-attr: ignore -->
```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderMachineTest extends TestCase
{
    use RefreshDatabase;

    // Tests with fresh database
}
```

### In-Memory SQLite

Configuring the test suite to use an in-memory SQLite database removes disk I/O and avoids leaving behind test data, making the full test suite significantly faster than running against a real PostgreSQL or MySQL instance.

```xml
<!-- phpunit.xml -->
<php>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
</php>
```

## Database Assertions

Laravel's built-in database assertion helpers let you verify the exact rows written to the `machine_events` table without loading Eloquent models. They are the fastest way to confirm that persistence worked correctly.

### `assertDatabaseHas()`

The `machine_events` table stores one row per event. The `type` column holds the event name, `source` distinguishes externally sent events (`external`) from internally raised ones, and `machine_id` identifies which machine definition the event belongs to.

<!-- doctest-attr: ignore -->
```php
it('persists events to database', function () {
    $machine = OrderMachine::create();
    $machine->send(['type' => 'SUBMIT']);

    $this->assertDatabaseHas('machine_events', [
        'type' => 'SUBMIT',
        'source' => 'external',
        'machine_id' => 'order',
    ]);
});
```

### `assertDatabaseCount()`

Every top-level `send()` call creates a group of related events. All events in the group share the same `root_event_id`, which is the ID of the first external event that triggered the transition. Counting by `root_event_id` lets you assert how many events (including internal ones) a single send produced.

<!-- doctest-attr: ignore -->
```php
it('creates expected number of events', function () {
    $machine = OrderMachine::create();
    $machine->send(['type' => 'SUBMIT']);

    // Count all events for this machine
    $count = MachineEvent::where(
        'root_event_id',
        $machine->state->history->first()->root_event_id
    )->count();

    expect($count)->toBeGreaterThan(1); // Includes internal events
});
```

### `assertDatabaseMissing()`

When `should_persist` is set to `false` in a machine's config, no events are written to the database at all. Use `assertDatabaseMissing()` to confirm that a machine or specific event type leaves no trace in `machine_events`.

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
it('does not persist when disabled', function () {
    $machine = MachineDefinition::define(
        config: [
            'should_persist' => false,
            'initial' => 'idle',
            'states' => [
                'idle' => ['on' => ['GO' => 'done']],
                'done' => [],
            ],
        ],
    );

    $machine->transition(['type' => 'GO']);

    $this->assertDatabaseMissing('machine_events', [
        'type' => 'GO',
    ]);
});
```

## Event History Testing

Event history is the ordered log of all events — external and internal — that have been applied to a machine instance. It is the source of truth for auditing, debugging, and state reconstruction.

### Check Event Order

Event order matters because replaying events out of sequence would produce a different final state. Tests that assert order verify that `sequence_number` is assigned correctly and that `orderBy('sequence_number')` retrieves events in the same sequence they were applied.

<!-- doctest-attr: ignore -->
```php
it('records events in correct order', function () {
    $machine = OrderMachine::create();

    $machine->send(['type' => 'SUBMIT']);
    $machine->send(['type' => 'APPROVE']);

    $events = MachineEvent::where('root_event_id',
        $machine->state->history->first()->root_event_id
    )->orderBy('sequence_number')->get();

    $externalEvents = $events->where('source', 'external');

    expect($externalEvents->first()->type)->toBe('SUBMIT');
    expect($externalEvents->last()->type)->toBe('APPROVE');
});
```

### Check Event Payload

The `payload` column stores the data that was passed alongside an event. It is populated only when the event carries extra data (e.g., item details, user input). Test payload storage whenever your machine reads from `event.payload` inside an action or guard.

<!-- doctest-attr: ignore -->
```php
it('stores event payload correctly', function () {
    $machine = OrderMachine::create();

    $machine->send([
        'type' => 'ADD_ITEM',
        'payload' => [
            'productId' => 123,
            'quantity' => 2,
        ],
    ]);

    $event = MachineEvent::where('type', 'ADD_ITEM')->first();

    expect($event->payload)->toBe([
        'productId' => 123,
        'quantity' => 2,
    ]);
});
```

### Check Context Storage

EventMachine uses incremental context storage: the first event in a group records the full context snapshot, while subsequent events in the same session record only the keys that changed. This minimises database storage while still allowing full reconstruction. Test this behaviour to confirm that only deltas are written after the initial event.

<!-- doctest-attr: ignore -->
```php
it('stores context incrementally', function () {
    $machine = OrderMachine::create();

    // First event - full context
    $machine->send(['type' => 'SUBMIT']);

    // Second event - only changes
    $machine->send(['type' => 'SET_NOTE', 'payload' => ['note' => 'Test']]);

    $events = MachineEvent::where('root_event_id',
        $machine->state->history->first()->root_event_id
    )->where('source', 'external')->orderBy('sequence_number')->get();

    // First event has full context
    expect($events[0]->context)->toHaveKey('orderId');
    expect($events[0]->context)->toHaveKey('items');

    // Second event has only the change
    expect($events[1]->context)->toHaveKey('note');
});
```

## State Restoration Testing

State restoration is the ability to recreate an exact machine snapshot from its persisted event log. You pass a `root_event_id` to `Machine::create()` and EventMachine replays all stored events in order, merging the incremental context deltas, to arrive at the same state and context that existed when the events were first applied.

### Basic Restoration

<!-- doctest-attr: ignore -->
```php
it('restores state from root event id', function () {
    $machine = OrderMachine::create();

    $machine->send(['type' => 'SUBMIT']);
    $machine->send(['type' => 'APPROVE']);

    $rootId = $machine->state->history->first()->root_event_id;
    $originalContext = $machine->state->context->toArray();
    $originalState = $machine->state->value;

    // Restore from root event ID
    $restored = OrderMachine::create(state: $rootId);

    expect($restored->state->value)->toEqual($originalState);
    expect($restored->state->context->toArray())->toEqual($originalContext);
});
```

### Context Reconstruction

<!-- doctest-attr: ignore -->
```php
it('reconstructs context from incremental changes', function () {
    $machine = OrderMachine::create();

    // Make multiple changes
    $machine->send(['type' => 'SET_CUSTOMER', 'payload' => ['id' => 'cust-1']]);
    $machine->send(['type' => 'ADD_ITEM', 'payload' => ['item' => ['id' => 1]]]);
    $machine->send(['type' => 'SET_DISCOUNT', 'payload' => ['amount' => 10]]);

    $rootId = $machine->state->history->first()->root_event_id;

    // Restore
    $restored = OrderMachine::create(state: $rootId);

    // All context values should be present
    expect($restored->state->context->customerId)->toBe('cust-1');
    expect($restored->state->context->items)->toHaveCount(1);
    expect($restored->state->context->discount)->toBe(10);
});
```

### Continue After Restoration

<!-- doctest-attr: ignore -->
```php
it('can continue from restored state', function () {
    $machine = OrderMachine::create();
    $machine->send(['type' => 'SUBMIT']);

    $rootId = $machine->state->history->first()->root_event_id;

    // Restore and continue
    $restored = OrderMachine::create(state: $rootId);
    $restored->send(['type' => 'APPROVE']);

    expect($restored->state->matches('approved'))->toBeTrue();

    // New event should be persisted
    $this->assertDatabaseHas('machine_events', [
        'root_event_id' => $rootId,
        'type' => 'APPROVE',
    ]);
});
```

## Transactional Testing

By default, EventMachine wraps each event dispatch in a database transaction so that if an action throws an exception, no partial data is committed. Test transactional behaviour to confirm that failures leave the database in the state it was before the event was sent.

### Rollback on Failure

<!-- doctest-attr: ignore -->
```php
it('rolls back on exception with transactional event', function () {
    $machine = OrderMachine::create();

    // Action that will fail
    FailingAction::fake();
    FailingAction::shouldRun()->andThrow(new Exception('Test error'));

    try {
        $machine->send(['type' => 'FAILING_EVENT']);
    } catch (Exception $e) {
        // Expected
    }

    // No events should be persisted
    $this->assertDatabaseMissing('machine_events', [
        'type' => 'FAILING_EVENT',
    ]);
});
```

### Non-Transactional Events

Use non-transactional events when you need an event to be persisted immediately — before the rest of the action pipeline completes. This is useful for audit logging or webhooks where you want a record written even if a later step fails.

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
it('persists non-transactional events on failure', function () {
    // Define non-transactional event
    $machine = MachineDefinition::define(
        config: [...],
        behavior: [
            'events' => [
                'FAST_UPDATE' => NonTransactionalEvent::class,
            ],
        ],
    );

    // Even if later actions fail, event is persisted
});
```

## Eloquent Integration Testing

The `HasMachines` trait wires state machines directly to Eloquent models, automatically initialising machines on model creation and storing `root_event_id` values as model attributes. These tests verify that the binding between model lifecycle events and machine initialisation works correctly.

### Model Machine Testing

<!-- doctest-attr: ignore -->
```php
it('initializes machine on model creation', function () {
    $order = Order::create(['name' => 'Test Order']);

    expect($order->status)->not->toBeNull();

    // Access machine
    expect($order->status->state->matches('pending'))->toBeTrue();
});
```

### Model State Persistence

<!-- doctest-attr: ignore -->
```php
it('persists machine state with model', function () {
    $order = Order::create(['name' => 'Test Order']);

    $order->status->send(['type' => 'SUBMIT']);

    // Reload from database
    $order = Order::find($order->id);

    expect($order->status->state->matches('submitted'))->toBeTrue();
});
```

### Multiple Machine Models

<!-- doctest-attr: ignore -->
```php
it('handles multiple machines on model', function () {
    $order = Order::create(['name' => 'Test Order']);

    $order->order_status->send(['type' => 'CONFIRM']);
    $order->payment_status->send(['type' => 'CHARGE']);

    $order = Order::find($order->id);

    expect($order->order_status->state->matches('confirmed'))->toBeTrue();
    expect($order->payment_status->state->matches('charged'))->toBeTrue();
});
```

### Conditional Initialization

Test `shouldInitializeMachine()` overrides that control when a machine starts:

<!-- doctest-attr: ignore -->
```php
it('does not initialize machine when condition is false', function () {
    // Model overrides shouldInitializeMachine() → returns false for drafts
    $order = Order::create(['name' => 'Draft', 'is_draft' => true]);

    expect($order->status)->toBeNull();
});

it('initializes machine when condition is true', function () {
    $order = Order::create(['name' => 'Real Order', 'is_draft' => false]);

    expect($order->status)->not->toBeNull();
    expect($order->status->state->matches('pending'))->toBeTrue();
});
```

## Concurrent Access Testing

When two processes attempt to advance the same machine at the same time, one of them must be rejected to prevent conflicting state writes. EventMachine raises `MachineAlreadyRunningException` for the second caller. These tests confirm that locking is enforced and at least one caller always succeeds.

<!-- doctest-attr: ignore -->
```php
it('handles concurrent access with locking', function () {
    $machine = OrderMachine::create();
    $rootId = $machine->state->history->first()->root_event_id;

    // Simulate concurrent access
    $results = collect([1, 2, 3])->map(function ($i) use ($rootId) {
        try {
            $m = OrderMachine::create(state: $rootId);
            $m->send(['type' => 'INCREMENT']);
            return 'success';
        } catch (MachineAlreadyRunningException $e) {
            return 'locked';
        }
    });

    // At least one should succeed, others may be locked
    expect($results->contains('success'))->toBeTrue();
});
```

## Archive Testing

The archive system moves historical event logs out of the hot `machine_events` table while keeping them accessible for restoration. These tests verify that archiving records the correct event count and that a machine can still be restored from a `root_event_id` after its events have been archived.

<!-- doctest-attr: ignore -->
```php
it('archives and restores events', function () {
    $machine = OrderMachine::create();
    $machine->send(['type' => 'SUBMIT']);

    $rootId = $machine->state->history->first()->root_event_id;

    // Archive
    $service = new ArchiveService();
    $archive = $service->archiveMachine($rootId);

    expect($archive)->not->toBeNull();
    expect($archive->event_count)->toBeGreaterThan(0);

    // Restore
    $restored = OrderMachine::create(state: $rootId);
    expect($restored->state->matches('submitted'))->toBeTrue();
});
```

## Best Practices

### 1. Use RefreshDatabase

Always include the `RefreshDatabase` trait in persistence test classes so each test receives a clean schema and leftover rows from previous tests cannot cause false positives or failures.

<!-- doctest-attr: ignore -->
```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class MyTest extends TestCase
{
    use RefreshDatabase;
}
```

### 2. Test State Restoration

Verify that a machine created from a saved `root_event_id` reaches the same state value and context as the original — confirming that incremental context deltas are merged correctly during replay.

<!-- doctest-attr: ignore -->
```php
it('restores correctly', function () {
    // Create, modify, save root ID
    // Restore and verify
});
```

### 3. Test Incremental Context

After making several distinct context changes, restore the machine and assert that every changed key is present with its final value — confirming that no delta was lost or overwritten during incremental storage.

<!-- doctest-attr: ignore -->
```php
it('handles context changes', function () {
    // Make multiple changes
    // Verify all changes persist and restore
});
```

### 4. Test Edge Cases

Cover boundary conditions to guard against subtle persistence bugs: an empty context on first transition, a payload large enough to exceed typical column limits, and special characters (Unicode, escaped quotes) that could corrupt serialised JSON.

<!-- doctest-attr: ignore -->
```php
it('handles empty context', function () { ... });
it('handles large payloads', function () { ... });
it('handles special characters', function () { ... });
```

::: tip Detailed Guide
For comprehensive design guidelines with Do/Don't examples, see [Testing Strategy](/best-practices/testing-strategy).
:::
