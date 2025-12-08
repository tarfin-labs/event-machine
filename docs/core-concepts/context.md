# Context

Context is the data that persists across state transitions. It holds all the information your machine needs to make decisions and perform actions.

## Basic Context

### Array Context

The simplest way to define context:

```php
MachineDefinition::define(
    config: [
        'initial' => 'idle',
        'context' => [
            'count' => 0,
            'items' => [],
            'user' => null,
        ],
        'states' => [...],
    ],
);
```

### Accessing Context

In behaviors, context is automatically injected:

```php
'actions' => [
    'incrementCount' => function (ContextManager $context) {
        $context->count++;
        // or
        $context->set('count', $context->get('count') + 1);
    },
],
```

### Context in Machine Instance

```php
$machine = OrderMachine::create();

// Read context
$count = $machine->state->context->count;
$count = $machine->state->context->get('count');

// Context is immutable from outside - only behaviors can modify it
```

## ContextManager API

### `get(key)`

Retrieve a value. Supports dot notation:

```php
$context->get('user');           // Get 'user'
$context->get('user.name');      // Get nested 'user.name'
$context->get('items.0.price');  // Get first item's price
```

### `set(key, value)`

Set a value:

```php
$context->set('count', 10);
$context->set('user.name', 'John');
```

### `has(key, type?)`

Check if a key exists, optionally with type:

```php
$context->has('user');              // true/false
$context->has('count', 'integer');  // true if exists and is integer
```

### `remove(key)`

Remove a key:

```php
$context->remove('temporaryData');
```

### Magic Properties

Context supports magic property access:

```php
// These are equivalent
$context->count;
$context->get('count');

// Setting
$context->count = 10;
$context->set('count', 10);

// Checking
isset($context->count);
$context->has('count');
```

## Custom Context Classes

For type safety and validation, create custom context classes:

```php
use Tarfinlabs\EventMachine\ContextManager;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Optional;

class OrderContext extends ContextManager
{
    public function __construct(
        #[Required]
        public string $orderId,

        #[Min(0)]
        public int|Optional $itemCount = 0,

        public array $items = [],

        public float|Optional $total = 0.0,

        public ?string $discountCode = null,
    ) {
        parent::__construct();
    }

    // Custom computed methods
    public function hasItems(): bool
    {
        return count($this->items) > 0;
    }

    public function calculateTotal(): float
    {
        return collect($this->items)->sum('price');
    }
}
```

### Using Custom Context

```php
MachineDefinition::define(
    config: [
        'initial' => 'pending',
        'context' => OrderContext::class,
        'states' => [...],
    ],
);

// In behaviors, you get the typed context
'guards' => [
    'hasItems' => fn(OrderContext $context) => $context->hasItems(),
],
'actions' => [
    'calculateTotal' => fn(OrderContext $context) =>
        $context->total = $context->calculateTotal(),
],
```

## Context Validation

### With Spatie Laravel Data

Custom context classes leverage Spatie Laravel Data for validation:

```php
use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;

class UserContext extends ContextManager
{
    public function __construct(
        #[Email]
        public string $email,

        #[Between(18, 120)]
        public int $age,

        #[Max(255)]
        public string $name,
    ) {
        parent::__construct();
    }
}
```

### Validation on Creation

```php
// This will validate
$context = UserContext::validateAndCreate([
    'email' => 'invalid-email',  // Will throw validation exception
    'age' => 15,                 // Will throw validation exception
    'name' => 'John',
]);
```

### Self-Validation

```php
$context = new UserContext(
    email: 'test@example.com',
    age: 25,
    name: 'John'
);

$context->selfValidate(); // Throws if invalid
```

## Context with Models

### Model Transformers

Use transformers to handle Eloquent models:

```php
use Spatie\LaravelData\Attributes\WithTransformer;

class OrderContext extends ContextManager
{
    public function __construct(
        #[WithTransformer(ModelTransformer::class)]
        public User|int|Optional $user,

        #[WithTransformer(ModelTransformer::class)]
        public Order|int|Optional $order,
    ) {
        parent::__construct();
    }
}
```

### Accessing Models

```php
// Model is automatically loaded when accessed
$userName = $context->user->name;

// Or access the ID
$userId = $context->user; // If stored as ID
```

## Required Context Validation

Behaviors can declare required context keys:

```php
class ProcessOrderAction extends ActionBehavior
{
    public static array $requiredContext = [
        'orderId' => 'string',
        'items' => 'array',
        'total' => 'numeric',
    ];

    public function __invoke(ContextManager $context): void
    {
        // Context is guaranteed to have these keys
        $this->processOrder($context->orderId, $context->items);
    }
}
```

If required context is missing, `MissingMachineContextException` is thrown.

## Context Persistence

Context is stored incrementally in the database:

```php
// First event: Full context stored
{
    "orderId": "order-123",
    "items": [],
    "total": 0
}

// Subsequent events: Only changes stored
{
    "items": [{"id": 1, "price": 100}],
    "total": 100
}
```

### Restoring Context

When restoring from `root_event_id`, context is reconstructed:

```php
$machine = OrderMachine::create(state: $rootEventId);

// Context is fully reconstructed from event history
echo $machine->state->context->total; // 100
```

## Context Best Practices

### 1. Use Custom Context Classes

For anything beyond simple counters:

```php
// Good
class OrderContext extends ContextManager
{
    public function __construct(
        public string $orderId,
        public array $items = [],
        public float $total = 0.0,
    ) {
        parent::__construct();
    }
}

// Avoid for complex data
'context' => [
    'orderId' => '',
    'items' => [],
    'total' => 0,
],
```

### 2. Keep Context Serializable

Avoid storing closures or resources:

```php
// Bad
$context->handler = fn() => 'value';

// Good
$context->handlerClass = MyHandler::class;
```

### 3. Use Computed Methods

Add methods for complex calculations:

```php
class CartContext extends ContextManager
{
    public array $items = [];

    public function subtotal(): float
    {
        return collect($this->items)->sum('price');
    }

    public function tax(): float
    {
        return $this->subtotal() * 0.1;
    }

    public function total(): float
    {
        return $this->subtotal() + $this->tax();
    }
}
```

### 4. Validate Early

Use validation guards for user input:

```php
class ValidateOrderGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = null;

    public function __invoke(OrderContext $context): bool
    {
        if (empty($context->items)) {
            $this->errorMessage = 'Order must have at least one item';
            return false;
        }
        return true;
    }
}
```
