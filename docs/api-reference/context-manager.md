# ContextManager API

Manages context data for state machines.

## Class Definition

```php
namespace Tarfinlabs\EventMachine;

class ContextManager extends \Spatie\LaravelData\Data
```

## Properties

| Property | Type | Description |
|----------|------|-------------|
| `$data` | `array\|Optional` | Data storage for base ContextManager |

## Constructor

```php
public function __construct(
    #[ArrayType]
    array|Optional $data = []
)
```

**Parameters:**
- `$data` - Initial key-value data

## Methods

### get()

Get a value by key.

```php
public function get(string $key): mixed
```

**Parameters:**
- `$key` - Key to retrieve (supports dot notation)

**Returns:** Value or null

**Example:**
```php
$context->get('orderId');
$context->get('user.email');
```

### set()

Set a value by key.

```php
public function set(string $key, mixed $value): mixed
```

**Parameters:**
- `$key` - Key to set
- `$value` - Value to store

**Returns:** The set value

**Example:**
```php
$context->set('orderId', 'ord-123');
$context->set('total', 100.50);
```

### has()

Check if a key exists, optionally with type check.

```php
public function has(string $key, ?string $type = null): bool
```

**Parameters:**
- `$key` - Key to check
- `$type` - Optional type to validate (e.g., `'integer'`, `'string'`, class name)

**Returns:** `bool`

**Example:**
```php
$context->has('orderId');              // Existence check
$context->has('total', 'double');      // Type check
$context->has('user', User::class);    // Class check
```

### remove()

Remove a key-value pair.

```php
public function remove(string $key): void
```

**Parameters:**
- `$key` - Key to remove

### selfValidate()

Validate the context against its rules.

```php
public function selfValidate(): void
```

**Throws:** `MachineContextValidationException`

### validateAndCreate()

Validate payload and create instance.

```php
public static function validateAndCreate(
    array|Arrayable $payload
): static
```

**Parameters:**
- `$payload` - Data to validate

**Returns:** New instance

**Throws:** `MachineContextValidationException`

### toArray()

Convert context to array.

```php
public function toArray(): array
```

**Returns:** Array representation

## Magic Methods

### __get()

Get property dynamically.

```php
$value = $context->orderId;
```

### __set()

Set property dynamically.

```php
$context->orderId = 'ord-123';
```

### __isset()

Check if property exists.

```php
if (isset($context->orderId)) {
    // ...
}
```

## Usage Examples

### Basic Usage

```php
// Via machine
$machine = OrderMachine::create();

// Get values
$orderId = $machine->state->context->orderId;
$total = $machine->state->context->get('total');

// Set values
$machine->state->context->set('note', 'Test note');
$machine->state->context->note = 'Test note';

// Check existence
if ($machine->state->context->has('discount')) {
    // Apply discount
}

// Remove value
$machine->state->context->remove('temporaryData');
```

### In Actions

```php
class UpdateTotalAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // Read
        $subtotal = $context->get('subtotal');
        $tax = $context->get('tax');

        // Write
        $context->set('total', $subtotal + $tax);

        // Or using magic methods
        $context->total = $context->subtotal + $context->tax;
    }
}
```

### Array Conversion

```php
$contextData = $machine->state->context->toArray();
// ['orderId' => 'ord-123', 'total' => 100, ...]

// Useful for logging or debugging
logger()->info('Context', $machine->state->context->toArray());
```

## Custom Context Classes

Extend ContextManager for type-safe context:

```php
<?php

namespace App\Machines\Order;

use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\ContextManager;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;

class OrderContext extends ContextManager
{
    public function __construct(
        #[Required]
        public string|Optional $orderId,

        #[Min(0)]
        public int|Optional $itemCount = 0,

        #[Min(0)]
        public float|Optional $total = 0.0,

        public array|Optional $items = [],
    ) {
        parent::__construct();

        // Set defaults
        if ($this->orderId instanceof Optional) {
            $this->orderId = uniqid('order-');
        }
    }

    /**
     * Custom helper method.
     */
    public function isEmpty(): bool
    {
        return $this->itemCount === 0;
    }

    /**
     * Calculate derived value.
     */
    public function calculateSubtotal(): float
    {
        return collect($this->items)->sum('price');
    }
}
```

### Using Custom Context

```php
$definition = MachineDefinition::define(
    config: [
        'initial' => 'pending',
        'context' => OrderContext::class,  // Reference class
        'states' => [...],
    ],
);

// Or with initial values
$definition = MachineDefinition::define(
    config: [
        'initial' => 'pending',
        'context' => OrderContext::class,
        'states' => [...],
    ],
);
```

### Type-Safe Access

```php
$machine = OrderMachine::create();

// Context is typed
$context = $machine->state->context;
assert($context instanceof OrderContext);

// IDE autocomplete works
$orderId = $context->orderId;
$total = $context->total;

// Helper methods available
if ($context->isEmpty()) {
    throw new Exception('Cart is empty');
}

$subtotal = $context->calculateSubtotal();
```

## Validation

Context can include validation rules:

```php
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Email;

class CustomerContext extends ContextManager
{
    public function __construct(
        #[Email]
        public string $email,

        #[Min(1)]
        #[Max(100)]
        public int $age,

        public string $name,
    ) {
        parent::__construct();
    }
}
```

Validation runs:
- When context is created
- After each action (via `selfValidate()`)

## Transformers

Use transformers for complex types:

```php
use Spatie\LaravelData\Attributes\WithTransformer;
use Tarfinlabs\EventMachine\Transformers\ModelTransformer;

class OrderContext extends ContextManager
{
    public function __construct(
        #[WithTransformer(ModelTransformer::class)]
        public User|int $customer,

        #[WithTransformer(ModelTransformer::class)]
        public ?Product $product = null,
    ) {
        parent::__construct();
    }
}
```

## Related

- [Custom Context](/advanced/custom-context) - Detailed guide
- [State](/api-reference/state) - State representation
- [Actions](/behaviors/actions) - Using context in actions
