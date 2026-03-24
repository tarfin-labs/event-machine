# Working with Context

Context is the data that accompanies your state machine throughout its lifecycle. It persists across transitions and can be read or modified by behaviors.

## Defining Context

Context must be defined as a typed class extending `ContextManager`:

```php ignore
use Tarfinlabs\EventMachine\ContextManager;

class CounterContext extends ContextManager
{
    public function __construct(
        public int $count = 0,
        public array $items = [],
        public ?string $user = null,
    ) {}
}
```

Reference it in your machine configuration:

```php ignore
MachineDefinition::define(
    config: [
        'initial' => 'idle',
        'context' => CounterContext::class,
        'states' => [...],
    ],
);
```

## Reading Context

### In Actions

```php no_run
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class IncrementCountAction extends ActionBehavior
{
    public function __invoke(CounterContext $context): void
    {
        $context->count++;
    }
}
```

### In Guards

```php no_run
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class HasItemsGuard extends GuardBehavior
{
    public function __invoke(CounterContext $context): bool
    {
        return count($context->items) > 0;
    }
}
```

### From State Object

```php no_run
$machine = OrderMachine::create();
$state = $machine->send(['type' => 'ADD_ITEM', 'item' => $item]);

$count = $state->context->count;
$items = $state->context->items;
```

## Writing Context

### Direct Property Access

```php no_run
class UpdateAction extends ActionBehavior
{
    public function __invoke(CounterContext $context): void
    {
        $context->count = 5;
        $context->user = 'Alice';
        $context->items = [...$context->items, 'new_item'];
    }
}
```

## Context Methods

| Method | Description |
|--------|-------------|
| `get(string $key)` | Get a value by key |
| `set(string $key, mixed $value)` | Set a value |
| `has(string $key, ?string $type = null)` | Check if key exists (optionally with type) |
| `remove(string $key)` | Remove a key |
| `computedContext()` | Override in subclasses to define computed key-value pairs for API responses |
| `toResponseArray()` | Returns `toArray()` merged with `computedContext()` — used by endpoints and `State::toArray()` |

## Creating Context Classes

Create a custom ContextManager for type safety, validation, and IDE support:

```php no_run
use Tarfinlabs\EventMachine\ContextManager;

class OrderContext extends ContextManager
{
    public function __construct(
        public int $total = 0,
        public int $itemCount = 0,
        public ?string $customerId = null,
        public array $items = [],
        public ?string $couponCode = null,
    ) {}

    public static function rules(): array
    {
        return [
            'total'     => ['required', 'integer'],
            'itemCount' => ['required', 'integer', 'min:0'],
        ];
    }
}
```

### Using Your Context

Reference the class in your configuration:

```php ignore
MachineDefinition::define(
    config: [
        'initial' => 'cart',
        'context' => OrderContext::class,
        'states' => [...],
    ],
);
```

### Benefits

1. **Type Safety**: Properties have explicit types
2. **Validation**: Uses `rules()` method with Laravel validation rules
3. **IDE Support**: Full autocomplete and type hints
4. **Documentation**: Self-documenting structure

### Accessing Context in Behaviors

Type-hint your context class directly:

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

Define validation via the `rules()` method with standard Laravel validation rules:

```php no_run
class UserContext extends ContextManager
{
    public function __construct(
        public int $balance = 0,
        public string $email = '',
        public string $name = '',
    ) {}

    public static function rules(): array
    {
        return [
            'balance' => ['required', 'integer', 'min:1'],
            'email'   => ['required', 'email'],
            'name'    => ['string', 'max:100'],
        ];
    }
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

Access event payload in actions using the `payload()` method:

```php no_run
class UpdateSettingsAction extends ActionBehavior
{
    public function __invoke(
        AppContext $context,
        EventBehavior $event
    ): void {
        $settings = $event->payload()['settings'];
        $context->theme = $settings['theme'];
        $context->notifications = $settings['notifications'];
    }
}
```

## Required Context in Behaviors

Declare required context keys for behaviors:

```php no_run
class ProcessPaymentAction extends ActionBehavior
{
    public static array $requiredContext = [
        'total',
        'customerId',
    ];

    public function __invoke(OrderContext $context): void
    {
        // Guaranteed to have 'total' and 'customerId'
        $total = $context->total;
        $customerId = $context->customerId;
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
use Tarfinlabs\EventMachine\ContextManager;

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
                    'ADD_ITEM' => ['actions' => 'addItemAction'],
                    'REMOVE_ITEM' => ['actions' => 'removeItemAction'],
                    'APPLY_COUPON' => ['actions' => 'applyCouponAction'],
                    'CHECKOUT' => [
                        'target' => 'checkout',
                        'guards' => 'hasItemsGuard',
                    ],
                ],
            ],
            'checkout' => [...],
        ],
    ],
    behavior: [
        'actions' => [
            'addItemAction' => AddItemAction::class,
            'removeItemAction' => RemoveItemAction::class,
            'applyCouponAction' => ApplyCouponAction::class,
        ],
        'guards' => [
            'hasItemsGuard' => HasItemsGuard::class,
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

## Testing Context

<!-- doctest-attr: ignore -->
```php
OrderMachine::test(['total' => 500, 'currency' => 'TRY'])
    ->assertContext('total', 500)
    ->assertContextHas('currency')
    ->send('APPLY_DISCOUNT')
    ->assertContext('total', 450)
    ->assertContextIncludes(['total' => 450, 'currency' => 'TRY']);
```

::: tip Full Testing Guide
See [TestMachine Context Assertions](/testing/test-machine#context-assertions) for more examples.
:::
