# Context

Context is the data that travels with your state machine. While states describe "where" your machine is, context describes the "what" - the accumulated data from events and computations.

## Initial Context

Define starting data in your machine configuration:

```php
MachineDefinition::define(
    config: [
        'initial' => 'idle',
        'context' => [
            'count' => 0,
            'items' => [],
            'total' => 0.0,
            'customer' => null,
        ],
        'states' => [...],
    ],
);
```

## Reading Context

Access context from the state:

```php
$state = $machine->state;

// Get a value
$count = $state->context->get('count');

// Check if key exists
$state->context->has('customer');  // true or false

// Get with default
$state->context->get('discount', 0);  // 0 if not set

// Get all context as array
$data = $state->context->toArray();
```

## Writing Context

Modify context in actions:

```php
'actions' => [
    'incrementCount' => function ($context) {
        $current = $context->get('count');
        $context->set('count', $current + 1);
    },

    'addItem' => function ($context, $event) {
        $items = $context->get('items');
        $items[] = $event->payload['item'];
        $context->set('items', $items);
    },

    'setCustomer' => function ($context, $event) {
        $context->set('customer', [
            'id' => $event->payload['customer_id'],
            'name' => $event->payload['customer_name'],
        ]);
    },
]
```

Using class-based actions:

```php
class AddItemAction extends ActionBehavior
{
    public function __invoke(
        ContextManager $context,
        EventBehavior $event
    ): void {
        $items = $context->get('items', []);
        $items[] = [
            'sku' => $event->payload['sku'],
            'quantity' => $event->payload['quantity'],
            'price' => $event->payload['price'],
        ];
        $context->set('items', $items);

        // Recalculate total
        $total = collect($items)->sum(fn($item) =>
            $item['quantity'] * $item['price']
        );
        $context->set('total', $total);
    }
}
```

## Context in Guards

Guards can read context to make decisions:

```php
'guards' => [
    'hasItems' => function ($context) {
        return count($context->get('items', [])) > 0;
    },

    'totalExceedsLimit' => function ($context) {
        return $context->get('total', 0) > 10000;
    },

    'isEligibleForDiscount' => function ($context) {
        $customer = $context->get('customer');
        return $customer && $customer['is_premium'];
    },
]
```

## Context in Calculators

Calculators prepare context before guards run:

```php
'calculators' => [
    'calculateTotal' => function ($context) {
        $items = $context->get('items', []);
        $subtotal = collect($items)->sum(fn($item) =>
            $item['quantity'] * $item['price']
        );

        $discount = $context->get('discount_percent', 0);
        $total = $subtotal * (1 - $discount / 100);

        $context->set('subtotal', $subtotal);
        $context->set('total', $total);
    },
]
```

## Context from Event Payload

Copy event data to context:

```php
'actions' => [
    'storePaymentDetails' => function ($context, $event) {
        $context->set('payment', [
            'amount' => $event->payload['amount'],
            'method' => $event->payload['method'],
            'transaction_id' => $event->payload['transaction_id'],
            'paid_at' => now(),
        ]);
    },
]
```

## Custom Context Classes

For complex context, create a dedicated class:

```php
namespace App\Machines\Context;

use Tarfinlabs\EventMachine\ContextManager;

class OrderContext extends ContextManager
{
    public function __construct(
        public array $items = [],
        public float $subtotal = 0,
        public float $discount = 0,
        public float $total = 0,
        public ?array $customer = null,
        public ?array $payment = null,
        public ?array $shipping = null,
    ) {}

    public function addItem(array $item): void
    {
        $this->items[] = $item;
        $this->recalculate();
    }

    public function removeItem(string $sku): void
    {
        $this->items = array_filter(
            $this->items,
            fn($item) => $item['sku'] !== $sku
        );
        $this->recalculate();
    }

    public function recalculate(): void
    {
        $this->subtotal = collect($this->items)->sum(
            fn($item) => $item['quantity'] * $item['price']
        );
        $this->total = $this->subtotal - $this->discount;
    }

    public function hasItems(): bool
    {
        return count($this->items) > 0;
    }

    public function isPaid(): bool
    {
        return $this->payment !== null;
    }
}
```

Use in your machine:

```php
MachineDefinition::define(
    config: [
        'context' => OrderContext::class,
        // ...
    ],
    behavior: [
        'context' => OrderContext::class,
    ],
);
```

Now actions get a typed context:

```php
class AddItemAction extends ActionBehavior
{
    public function __invoke(
        OrderContext $context,  // Typed!
        EventBehavior $event
    ): void {
        $context->addItem([
            'sku' => $event->payload['sku'],
            'quantity' => $event->payload['quantity'],
            'price' => $event->payload['price'],
        ]);
    }
}
```

## Context Persistence

Context is automatically persisted with each event:

```php
// Event 1: Add item
$machine->send(['type' => 'ADD_ITEM', 'sku' => 'ABC', ...]);
// Context saved: {items: [{sku: 'ABC', ...}], total: 29.99}

// Event 2: Add another item
$machine->send(['type' => 'ADD_ITEM', 'sku' => 'DEF', ...]);
// Context saved: {items: [{...}, {...}], total: 59.98}
```

When restoring, context is rebuilt from event history:

```php
$restored = OrderMachine::create(state: $rootEventId);
$restored->state->context->get('items');  // Both items present
$restored->state->context->get('total');  // 59.98
```

### Incremental Context Storage

EventMachine optimizes storage by only persisting context changes:

```php
// If only 'total' changed, only 'total' is stored
// Not the entire context object
```

## Context Best Practices

### Keep Context Serializable

Context is stored as JSON. Avoid:
- Closures
- Resource handles
- Circular references
- Non-serializable objects

```php
// Bad - can't serialize
$context->set('handler', fn() => 'process');
$context->set('connection', $pdo);

// Good - serializable data
$context->set('handler_type', 'email');
$context->set('connection_name', 'mysql');
```

### Use Meaningful Keys

```php
// Good
$context->set('order_total', 99.99);
$context->set('customer_email', 'user@example.com');

// Avoid
$context->set('t', 99.99);
$context->set('e', 'user@example.com');
```

### Don't Store Derivable Data

```php
// Avoid storing what you can calculate
$context->set('item_count', count($items));  // Can derive from items

// Better - calculate when needed
count($context->get('items'));
```

### Initialize All Required Keys

```php
'context' => [
    'items' => [],           // Empty array, not null
    'total' => 0.0,          // Zero, not null
    'customer' => null,      // Explicitly null if optional
    'status' => 'pending',   // Default value
]
```
