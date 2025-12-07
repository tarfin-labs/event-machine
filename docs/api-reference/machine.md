# Machine API

Runtime class for executing state machines with persistence.

## Class Definition

```php
namespace Tarfinlabs\EventMachine\Actor;

class Machine implements Castable, JsonSerializable, Stringable
```

## Properties

| Property | Type | Description |
|----------|------|-------------|
| `$definition` | `?MachineDefinition` | Machine definition blueprint |
| `$state` | `?State` | Current machine state |

## Static Methods

### definition()

Define the machine blueprint. Override in subclasses.

```php
public static function definition(): ?MachineDefinition
```

**Returns:** `MachineDefinition|null`

**Throws:** `MachineDefinitionNotFoundException` if not overridden

**Example:**
```php
class OrderMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'pending',
                'states' => [
                    'pending' => ['on' => ['SUBMIT' => 'processing']],
                    'processing' => [],
                ],
            ],
        );
    }
}
```

### create()

Create and initialize a new machine instance.

```php
public static function create(
    MachineDefinition|array|null $definition = null,
    State|string|null $state = null
): self
```

**Parameters:**
- `$definition` - Definition or config array (uses static `definition()` if null)
- `$state` - Initial state or root event ID to restore from

**Returns:** `Machine`

**Example:**
```php
// Create with class definition
$machine = OrderMachine::create();

// Create with inline definition
$machine = Machine::create([
    'config' => [
        'initial' => 'idle',
        'states' => ['idle' => []],
    ],
]);

// Restore from root event ID
$machine = OrderMachine::create(state: '01HXYZ...');
```

### withDefinition()

Create machine with explicit definition.

```php
public static function withDefinition(
    MachineDefinition $definition
): self
```

**Parameters:**
- `$definition` - Machine definition

**Returns:** `Machine`

### castUsing()

Get the caster class for Eloquent casting.

```php
public static function castUsing(array $arguments): string
```

**Returns:** `MachineCast::class`

## Instance Methods

### start()

Start the machine with a state.

```php
public function start(
    State|string|null $state = null
): self
```

**Parameters:**
- `$state` - State, root event ID, or null for initial

**Returns:** `self`

### send()

Send an event to the machine.

```php
public function send(
    EventBehavior|array|string $event
): State
```

**Parameters:**
- `$event` - Event to send (type string, array, or EventBehavior)

**Returns:** `State` - Updated state

**Throws:**
- `MachineAlreadyRunningException` - If machine is locked
- `MachineValidationException` - If validation guards fail

**Example:**
```php
// String event type
$machine->send('SUBMIT');

// Array with payload
$machine->send([
    'type' => 'ADD_ITEM',
    'payload' => ['item' => ['id' => 1, 'price' => 100]],
]);

// EventBehavior class
$machine->send(SubmitEvent::class);
```

### persist()

Persist the machine's state to database.

```php
public function persist(): ?State
```

**Returns:** `State|null` - Current state after persistence

### restoreStateFromRootEventId()

Restore machine state from persisted events.

```php
public function restoreStateFromRootEventId(
    string $key
): State
```

**Parameters:**
- `$key` - Root event ID (ULID)

**Returns:** `State`

**Throws:** `RestoringStateException` if not found

### result()

Get the result from a final state.

```php
public function result(): mixed
```

**Returns:** Result from ResultBehavior or null

**Example:**
```php
$machine->send(['type' => 'COMPLETE']);

if ($machine->state->currentStateDefinition->type === StateDefinitionType::FINAL) {
    $result = $machine->result();
    // ['orderId' => '...', 'total' => 100]
}
```

### jsonSerialize()

JSON representation (root event ID).

```php
public function jsonSerialize(): string
```

**Returns:** Root event ID string

### __toString()

String representation (root event ID).

```php
public function __toString(): string
```

**Returns:** Root event ID or empty string

## Interfaces

### Castable

Enables Eloquent attribute casting:

```php
class Order extends Model
{
    protected $casts = [
        'status' => OrderMachine::class,
    ];
}
```

### JsonSerializable

Returns root event ID when JSON encoded:

```php
$json = json_encode($machine); // "01HXYZ..."
```

### Stringable

Returns root event ID when cast to string:

```php
$id = (string) $machine; // "01HXYZ..."
```

## Usage Examples

### Basic Usage

```php
$machine = OrderMachine::create();

// Check initial state
expect($machine->state->matches('pending'))->toBeTrue();

// Send event
$machine->send(['type' => 'SUBMIT']);

// Check new state
expect($machine->state->matches('processing'))->toBeTrue();
```

### With Payload

```php
$machine = CartMachine::create();

$machine->send([
    'type' => 'ADD_ITEM',
    'payload' => [
        'item' => [
            'id' => 'sku-123',
            'name' => 'Widget',
            'price' => 29.99,
            'quantity' => 2,
        ],
    ],
]);

expect($machine->state->context->items)->toHaveCount(1);
```

### State Restoration

```php
// First request - create and persist
$machine = OrderMachine::create();
$machine->send(['type' => 'SUBMIT']);
$rootId = (string) $machine;

// Later request - restore and continue
$machine = OrderMachine::create(state: $rootId);
$machine->send(['type' => 'APPROVE']);
```

### Concurrent Access

```php
try {
    $machine = OrderMachine::create(state: $rootId);
    $machine->send(['type' => 'UPDATE']);
} catch (MachineAlreadyRunningException $e) {
    // Another process is using this machine
    return response('Machine is busy', 423);
}
```

## Related

- [MachineDefinition](/api-reference/machine-definition) - Definition blueprint
- [State](/api-reference/state) - State representation
- [Eloquent Integration](/laravel-integration/eloquent-integration) - Model integration
