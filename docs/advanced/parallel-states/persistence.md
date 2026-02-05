# Parallel State Persistence

How parallel states are stored in the database and restored.

**Related pages:**
- [Parallel States Overview](./index) - Basic concepts and syntax
- [Event Handling](./event-handling) - Events, entry/exit actions, `onDone`
- [Persistence](/laravel-integration/persistence) - General persistence documentation

## Database Storage

Parallel state values are automatically persisted to the database. The `machine_value` column stores the array of active state IDs as JSON:

```php
// State is persisted with all active regions
$machine = OrderWorkflowMachine::create();
$machine->send(['type' => 'START']);

// Get the root event ID for later restoration
$rootEventId = $machine->state->history->first()->root_event_id;

// Later, restore from database using the root event ID
$machine = OrderWorkflowMachine::create(state: $rootEventId);
$state = $machine->state;

// All parallel regions are restored
$state->matches('processing.payment.pending');   // true
$state->matches('processing.shipping.preparing'); // true
```

## JSON Structure

The persisted state value is stored as a JSON array of fully-qualified state IDs:

```json
{
  "machine_value": [
    "orderFulfillment.processing.payment.validating",
    "orderFulfillment.processing.shipping.picking",
    "orderFulfillment.processing.documents.generating"
  ]
}
```

When restored, EventMachine reconstructs the parallel state by:
1. Parsing the JSON array of state IDs
2. Validating each state path exists in the machine definition
3. Rebuilding the state tree with all active leaf states

## Context Persistence

Context changes within parallel states are persisted incrementally. Each event stores only the context delta (what changed), not the full context:

```php
// Event 1: Payment succeeds
$machine->send([
    'type' => 'PAYMENT_SUCCESS',
    'payload' => ['payment_id' => 'pay_123'],
]);
// Persists: {"payment_id": "pay_123"}

// Event 2: Shipping progresses
$machine->send(['type' => 'PICKED']);
// Persists: {} (no context change)

// Event 3: Shipping complete with tracking
$machine->send([
    'type' => 'SHIPPED',
    'payload' => ['tracking_number' => '1Z999...'],
]);
// Persists: {"tracking_number": "1Z999..."}
```

## Restoration Patterns

### Full Machine Restoration

Restore a machine to its exact state from any point:

```php
use Tarfinlabs\EventMachine\Actor\Machine;

// Get the root event ID when creating the machine
$machine = OrderFulfillmentMachine::create();
$rootEventId = $machine->state->history->first()->root_event_id;

// Store root_event_id in your domain model
$order->update(['machine_root_event_id' => $rootEventId]);

// Later, restore the machine
$machine = OrderFulfillmentMachine::create(state: $order->machine_root_event_id);

// All regions are restored to their exact states
$machine->state->matches('processing.payment.charged');
$machine->state->matches('processing.shipping.packing');
$machine->state->context->payment_id;  // 'pay_123'
```

### Using `MachineCast` with Eloquent

For automatic persistence with Eloquent models:

```php
use Tarfinlabs\EventMachine\Casts\MachineCast;

class Order extends Model
{
    protected $casts = [
        'fulfillment_state' => MachineCast::class . ':' . OrderFulfillmentMachine::class,
    ];
}

// The cast handles root_event_id storage automatically
$order = Order::create(['customer_id' => 123]);
$order->fulfillment_state->send(['type' => 'PAYMENT_SUCCESS', 'payload' => ['payment_id' => 'pay_123']]);
$order->save();

// Later retrieval restores the full parallel state
$order = Order::find(1);
$order->fulfillment_state->state->matches('processing.payment.charged');  // true
```

## Querying Machines by State

Find machines in specific parallel state combinations:

```php
use Tarfinlabs\EventMachine\Models\MachineEvent;

// Find all orders where payment is charged but shipping is still picking
$events = MachineEvent::query()
    ->where('machine_id', 'orderFulfillment')
    ->whereJsonContains('machine_value', 'orderFulfillment.processing.payment.charged')
    ->whereJsonContains('machine_value', 'orderFulfillment.processing.shipping.picking')
    ->latest()
    ->get()
    ->unique('root_event_id');
```

## Cross-Region State Queries

Query for specific combinations across regions:

```php
// Orders ready to ship (payment charged, docs ready, shipping packed)
$readyToShip = MachineEvent::query()
    ->where('machine_id', 'orderFulfillment')
    ->whereJsonContains('machine_value', 'orderFulfillment.processing.payment.charged')
    ->whereJsonContains('machine_value', 'orderFulfillment.processing.documents.ready')
    ->whereJsonContains('machine_value', 'orderFulfillment.processing.shipping.readyToShip')
    ->latest()
    ->get()
    ->unique('root_event_id');
```

## Handling Large Parallel State Trees

For machines with many parallel regions, consider:

### Index Optimization

```sql
-- Index for state value searches
CREATE INDEX idx_machine_events_value
ON machine_events ((machine_value::jsonb));

-- Partial index for specific machine types
CREATE INDEX idx_order_fulfillment_states
ON machine_events ((machine_value::jsonb))
WHERE machine_id = 'orderFulfillment';
```

### State Summarization

For complex parallel structures, store summary flags in context:

```php
'actions' => [
    'markPaymentComplete' => function (ContextManager $ctx): void {
        $ctx->set('payment_complete', true);
    },
    'markShippingComplete' => function (ContextManager $ctx): void {
        $ctx->set('shipping_complete', true);
    },
],
```

Then query by context instead of state value:

```php
MachineEvent::query()
    ->where('machine_id', 'orderFulfillment')
    ->whereJsonContains('context', ['payment_complete' => true])
    ->whereJsonContains('context', ['shipping_complete' => false])
    ->get();
```

## Archival Considerations

When archiving parallel state machines, all regions are compressed together. See [Archival](/laravel-integration/archival) for details on:

- Compression levels for parallel state data
- Restoration of archived parallel states
- Auto-restore behavior when new events arrive
