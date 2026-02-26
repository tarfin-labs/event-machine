# Working with Context

Context is the data that accompanies your state machine throughout its lifecycle. It persists across transitions and can be read or modified by behaviors.

## Defining Initial Context

Set initial context values in your machine configuration:

```php ignore
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

## Reading Context

### In Actions

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

class IncrementCountAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $currentCount = $context->get('count');
        $context->set('count', $currentCount + 1);
    }
}
```

### In Guards

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Behavior\GuardBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

class HasItemsGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        $items = $context->get('items');
        return count($items) > 0;
    }
}
```

### From State Object

```php no_run
$machine = OrderMachine::create();
$state = $machine->send(['type' => 'ADD_ITEM', 'item' => $item]);

$count = $state->context->get('count');
$items = $state->context->get('items');
```

## Writing Context

### Using `set()`

```php no_run
$context->set('count', 5);
$context->set('user', $userData);
$context->set('items', [...$items, $newItem]);
```

### Using Magic Properties

The ContextManager supports magic property access:

```php no_run
// Reading
$count = $context->count;

// Writing
$context->count = 5;
```

## Context Methods

| Method | Description |
|--------|-------------|
| `get(string $key)` | Get a value by key |
| `set(string $key, mixed $value)` | Set a value |
| `has(string $key, ?string $type = null)` | Check if key exists (optionally with type) |
| `remove(string $key)` | Remove a key |

### Checking Existence

```php no_run
if ($context->has('user')) {
    // Key exists
}

// Check existence with type
if ($context->has('user', User::class)) {
    // Key exists and value is instance of User
}
```

## Custom Context Classes

For type safety and validation, create a custom ContextManager:

```php no_run
use Tarfinlabs\EventMachine\ContextManager;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Min;

class OrderContext extends ContextManager
{
    public function __construct(
        #[Required]
        public int $total = 0,

        #[Required, Min(0)]
        public int $itemCount = 0,

        public ?string $customerId = null,

        public array $items = [],

        public ?string $couponCode = null,
    ) {}
}
```

### Using Custom Context

Reference it in your configuration:

```php ignore
MachineDefinition::define(
    config: [
        'initial' => 'cart',
        'context' => OrderContext::class,  // Reference the class
        'states' => [...],
    ],
);
```

### Benefits of Custom Context

1. **Type Safety**: Properties have explicit types
2. **Validation**: Uses Laravel Data validation attributes
3. **IDE Support**: Full autocomplete and type hints
4. **Documentation**: Self-documenting structure

### Accessing Custom Context

With custom context classes, access properties directly:

```php no_run
class AddItemAction extends ActionBehavior
{
    public function __invoke(OrderContext $context): void
    {
        // Direct property access with types
        $context->itemCount++;
        $context->total += $this->getItemPrice();
    }
}
```

## Context Validation

### Automatic Validation

Context is validated:
1. When the machine initializes
2. After every action executes

If validation fails, a `MachineContextValidationException` is thrown.

### Manual Validation

```php no_run
$context->selfValidate();  // Throws on failure
```

### Validation Rules

Using Laravel Data attributes:

```php no_run
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Email;

class UserContext extends ContextManager
{
    public function __construct(
        #[Required, Min(1)]
        public int $balance = 0,

        #[Required, Email]
        public string $email = '',

        #[Max(100)]
        public string $name = '',
    ) {}
}
```

## Context in Events

Events can carry payload that updates context:

```php no_run
// Sending an event with payload
$machine->send([
    'type' => 'UPDATE_SETTINGS',
    'settings' => [
        'theme' => 'dark',
        'notifications' => true,
    ],
]);
```

Access event payload in actions:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
use Tarfinlabs\EventMachine\Behavior\EventBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

class UpdateSettingsAction extends ActionBehavior
{
    public function __invoke(
        ContextManager $context,
        EventBehavior $event
    ): void {
        $settings = $event->payload['settings'];
        $context->set('theme', $settings['theme']);
        $context->set('notifications', $settings['notifications']);
    }
}
```

## Required Context in Behaviors

Declare required context keys for behaviors:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

class ProcessPaymentAction extends ActionBehavior
{
    public static array $requiredContext = [
        'total',
        'customerId',
    ];

    public function __invoke(ContextManager $context): void
    {
        // Guaranteed to have 'total' and 'customerId'
        $total = $context->get('total');
        $customerId = $context->get('customerId');
    }
}
```

If required context is missing, an exception is thrown before the behavior executes.

## Context Persistence

Context changes are persisted to the database with each transition:

```php no_run
// Initial state
$machine = OrderMachine::create();
// Context: { count: 0 }

// After transition
$machine->send(['type' => 'INCREMENT']);
// Context: { count: 1 }

// Later, restore from database
$machine = OrderMachine::create(state: $rootEventId);
// Context: { count: 1 } - restored!
```

## Complete Example

```php ignore
// Context class
class ShoppingCartContext extends ContextManager
{
    public function __construct(
        public array $items = [],
        public int $total = 0,
        public ?string $coupon = null,
        public int $discount = 0,
    ) {}

    public function addItem(array $item): void
    {
        $this->items[] = $item;
        $this->recalculateTotal();
    }

    public function recalculateTotal(): void
    {
        $this->total = array_sum(array_column($this->items, 'price'));
        $this->total -= $this->discount;
    }
}

// Machine definition
MachineDefinition::define(
    config: [
        'id' => 'cart',
        'initial' => 'browsing',
        'context' => ShoppingCartContext::class,
        'states' => [
            'browsing' => [
                'on' => [
                    'ADD_ITEM' => ['actions' => 'addItem'],
                    'REMOVE_ITEM' => ['actions' => 'removeItem'],
                    'APPLY_COUPON' => ['actions' => 'applyCoupon'],
                    'CHECKOUT' => [
                        'target' => 'checkout',
                        'guards' => 'hasItems',
                    ],
                ],
            ],
            'checkout' => [...],
        ],
    ],
    behavior: [
        'actions' => [
            'addItem' => AddItemAction::class,
            'removeItem' => RemoveItemAction::class,
            'applyCoupon' => ApplyCouponAction::class,
        ],
        'guards' => [
            'hasItems' => HasItemsGuard::class,
        ],
    ],
);

// Usage
$cart = ShoppingCartMachine::create();

$state = $cart->send([
    'type' => 'ADD_ITEM',
    'item' => ['name' => 'Widget', 'price' => 1999],
]);

echo $state->context->total; // 1999
```
