# State API

Represents the current state of a machine.

## Class Definition

```php
namespace Tarfinlabs\EventMachine\Actor;

class State
```

## Properties

| Property | Type | Description |
|----------|------|-------------|
| `$value` | `array<string>` | Current state path(s) |
| `$context` | `ContextManager` | State context data |
| `$currentStateDefinition` | `?StateDefinition` | Current state definition |
| `$currentEventBehavior` | `?EventBehavior` | Last processed event |
| `$history` | `?EventCollection` | Event history collection |

## Constructor

```php
public function __construct(
    ContextManager $context,
    ?StateDefinition $currentStateDefinition,
    ?EventBehavior $currentEventBehavior = null,
    ?EventCollection $history = null
)
```

**Parameters:**
- `$context` - Context manager instance
- `$currentStateDefinition` - Current state definition
- `$currentEventBehavior` - Last event (optional)
- `$history` - Event history collection (optional)

## Methods

### matches()

Check if machine is in a specific state.

```php
public function matches(string $value): bool
```

**Parameters:**
- `$value` - State to check (e.g., `'pending'`, `'checkout.payment'`)

**Returns:** `bool`

**Example:**
```php
if ($machine->state->matches('pending')) {
    // Handle pending state
}

// Nested state matching
if ($machine->state->matches('checkout.payment')) {
    // In checkout.payment state
}

// Full path also works
if ($machine->state->matches('order.checkout.payment')) {
    // Same as above if machine id is 'order'
}
```

### setCurrentStateDefinition()

Set the current state definition.

```php
public function setCurrentStateDefinition(
    StateDefinition $stateDefinition
): self
```

**Parameters:**
- `$stateDefinition` - New state definition

**Returns:** `self`

### setCurrentEventBehavior()

Set the current event behavior and record to history.

```php
public function setCurrentEventBehavior(
    EventBehavior $currentEventBehavior,
    bool $shouldLog = false
): self
```

**Parameters:**
- `$currentEventBehavior` - Event behavior
- `$shouldLog` - Whether to log the event

**Returns:** `self`

### setInternalEventBehavior()

Record an internal event to history.

```php
public function setInternalEventBehavior(
    InternalEvent $type,
    ?string $placeholder = null,
    ?array $payload = null,
    bool $shouldLog = false
): self
```

**Parameters:**
- `$type` - Internal event type enum
- `$placeholder` - Placeholder for event name
- `$payload` - Event payload
- `$shouldLog` - Whether to log

**Returns:** `self`

## Property Details

### $value

Array containing the full state path:

```php
$machine->state->value;
// ['order.checkout.payment']

// For simple state
// ['order.pending']
```

### $context

Access context data:

```php
// Direct property access
$machine->state->context->orderId;
$machine->state->context->items;

// Using get()
$machine->state->context->get('orderId');

// Using has()
if ($machine->state->context->has('orderId')) {
    // ...
}

// Set values
$machine->state->context->set('note', 'Test');
$machine->state->context->note = 'Test';
```

### $currentStateDefinition

Access state definition properties:

```php
$def = $machine->state->currentStateDefinition;

$def->id;          // 'order.pending'
$def->key;         // 'pending'
$def->type;        // StateDefinitionType::ATOMIC
$def->description; // 'Order is pending'
$def->meta;        // ['priority' => 'high']
```

### $currentEventBehavior

Access the last processed event:

```php
$event = $machine->state->currentEventBehavior;

$event->type;     // 'SUBMIT'
$event->payload;  // ['express' => true]
$event->source;   // SourceType::EXTERNAL
```

### $history

Access event history:

```php
// Get all events
$machine->state->history->count();

// Get first event (contains root_event_id)
$firstEvent = $machine->state->history->first();
$rootId = $firstEvent->root_event_id;

// Filter external events
$external = $machine->state->history->where('source', 'external');

// Get event types
$types = $machine->state->history->pluck('type');

// Filter by type pattern
$transitions = $machine->state->history
    ->filter(fn($e) => str_contains($e->type, '.transition.'));
```

## Usage Examples

### State Checking

```php
$machine = OrderMachine::create();

// Check current state
if ($machine->state->matches('pending')) {
    $machine->send(['type' => 'SUBMIT']);
}

// Check nested state
if ($machine->state->matches('checkout.payment')) {
    // Show payment form
}

// Check state type
if ($machine->state->currentStateDefinition->type === StateDefinitionType::FINAL) {
    // Machine completed
}
```

### Context Access

```php
$machine = OrderMachine::create();

// Read context
$orderId = $machine->state->context->orderId;
$items = $machine->state->context->items;
$total = $machine->state->context->get('total');

// Check context
if ($machine->state->context->has('discount')) {
    $discount = $machine->state->context->discount;
}

// Array conversion
$contextData = $machine->state->context->toArray();
```

### History Analysis

```php
$machine = OrderMachine::create();
$machine->send(['type' => 'SUBMIT']);
$machine->send(['type' => 'APPROVE']);

// Get root event ID
$rootId = $machine->state->history->first()->root_event_id;

// Count events
$totalEvents = $machine->state->history->count();

// Get external events only
$userEvents = $machine->state->history
    ->where('source', SourceType::EXTERNAL);

// Find specific event
$submitEvent = $machine->state->history
    ->firstWhere('type', 'SUBMIT');

// Check event sequence
$eventTypes = $machine->state->history->pluck('type')->toArray();
```

### State Restoration

```php
// Save root event ID
$rootId = $machine->state->history->first()->root_event_id;

// Restore later
$restored = OrderMachine::create(state: $rootId);

// State is fully restored
expect($restored->state->value)->toEqual($original->state->value);
expect($restored->state->context->toArray())
    ->toEqual($original->state->context->toArray());
```

## Related

- [Machine](/api-reference/machine) - Runtime machine
- [StateDefinition](/api-reference/state-definition) - State configuration
- [ContextManager](/api-reference/context-manager) - Context data
