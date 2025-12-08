# Persistence Testing

Testing EventMachine's database persistence and event sourcing capabilities.

## Test Setup

### RefreshDatabase Trait

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderMachineTest extends TestCase
{
    use RefreshDatabase;

    // Tests with fresh database
}
```

### In-Memory SQLite

```xml
<!-- phpunit.xml -->
<php>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
</php>
```

## Database Assertions

### assertDatabaseHas

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

### assertDatabaseCount

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

### assertDatabaseMissing

```php
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

### Check Event Order

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

### Basic Restoration

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

### Rollback on Failure

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

```php
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

### Model Machine Testing

```php
it('initializes machine on model creation', function () {
    $order = Order::create(['name' => 'Test Order']);

    expect($order->status)->not->toBeNull();

    // Access machine
    expect($order->status->state->matches('pending'))->toBeTrue();
});
```

### Model State Persistence

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

## Concurrent Access Testing

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

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class MyTest extends TestCase
{
    use RefreshDatabase;
}
```

### 2. Test State Restoration

```php
it('restores correctly', function () {
    // Create, modify, save root ID
    // Restore and verify
});
```

### 3. Test Incremental Context

```php
it('handles context changes', function () {
    // Make multiple changes
    // Verify all changes persist and restore
});
```

### 4. Test Edge Cases

```php
it('handles empty context', function () { ... });
it('handles large payloads', function () { ... });
it('handles special characters', function () { ... });
```
