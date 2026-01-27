# Event Behaviors

Event behaviors define the structure, validation, and metadata for events sent to your machine.

## Basic Event Class

```php
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class SubmitOrderEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'SUBMIT_ORDER';
    }
}
```

## Event Registration

Register event classes in the behavior configuration:

```php
MachineDefinition::define(
    config: [
        'states' => [
            'cart' => [
                'on' => [
                    'SUBMIT_ORDER' => 'processing',
                    // Or use class directly
                    SubmitOrderEvent::class => 'processing',
                ],
            ],
        ],
    ],
    behavior: [
        'events' => [
            'SUBMIT_ORDER' => SubmitOrderEvent::class,
        ],
    ],
);
```

## Sending Events

```php
// As array
$machine->send(['type' => 'SUBMIT_ORDER']);

// As array with payload
$machine->send([
    'type' => 'SUBMIT_ORDER',
    'payload' => ['express' => true],
]);

// As event class
$machine->send(new SubmitOrderEvent());
```

## Event with Typed Properties

```php
class AddItemEvent extends EventBehavior
{
    public function __construct(
        public readonly int $productId,
        public readonly int $quantity = 1,
        public readonly ?float $customPrice = null,
    ) {
        parent::__construct();
    }

    public static function getType(): string
    {
        return 'ADD_ITEM';
    }
}

// Usage
$machine->send(new AddItemEvent(
    productId: 123,
    quantity: 2,
    customPrice: 29.99,
));
```

## Event Validation

### Using Laravel Rules

```php
class SubmitOrderEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'SUBMIT_ORDER';
    }

    public static function rules(): array
    {
        return [
            'payload.items' => ['required', 'array', 'min:1'],
            'payload.shipping_address' => ['required', 'string', 'min:10'],
            'payload.payment_method' => ['required', 'in:card,bank,cash'],
        ];
    }

    public static function messages(): array
    {
        return [
            'payload.items.required' => 'Your cart is empty',
            'payload.items.min' => 'Add at least one item to checkout',
            'payload.shipping_address.required' => 'Shipping address is required',
        ];
    }
}
```

### Validation in Action

```php
try {
    $machine->send([
        'type' => 'SUBMIT_ORDER',
        'payload' => [
            'items' => [],  // Invalid
        ],
    ]);
} catch (MachineEventValidationException $e) {
    // 'Your cart is empty'
    $errors = $e->errors();
}
```

### Using Spatie Data Attributes

```php
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\IntegerType;

class TransferEvent extends EventBehavior
{
    public function __construct(
        #[Required]
        #[IntegerType]
        #[Min(1)]
        public int $amount,

        #[Required]
        public string $recipientId,

        public ?string $note = null,
    ) {
        parent::__construct();
    }

    public static function getType(): string
    {
        return 'TRANSFER';
    }
}
```

## Event Properties

### `type`

The event identifier:

```php
public static function getType(): string
{
    return 'ORDER_SUBMITTED';
}
```

### `payload`

Event data passed through arrays:

```php
$machine->send([
    'type' => 'ADD_ITEM',
    'payload' => [
        'productId' => 123,
        'quantity' => 2,
    ],
]);

// Access in behaviors
$event->payload['productId'];
```

### `isTransactional`

Whether to wrap the transition in a database transaction:

```php
class CriticalEvent extends EventBehavior
{
    public bool $isTransactional = true; // Default

    public static function getType(): string
    {
        return 'CRITICAL_OPERATION';
    }
}

class FastEvent extends EventBehavior
{
    public bool $isTransactional = false;

    public static function getType(): string
    {
        return 'QUICK_UPDATE';
    }
}
```

### `actor`

Track who triggered the event:

```php
class SubmitEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'SUBMIT';
    }

    public function actor(ContextManager $context): mixed
    {
        return auth()->user()?->id ?? 'system';
    }
}
```

### `source`

Event origin (set automatically):

```php
use Tarfinlabs\EventMachine\Enums\SourceType;

$event->source; // SourceType::EXTERNAL (user-sent)
                // SourceType::INTERNAL (system-generated)
```

### `version`

Event versioning for schema evolution:

```php
class SubmitEventV2 extends EventBehavior
{
    public int $version = 2;

    public static function getType(): string
    {
        return 'SUBMIT';
    }
}
```

## Practical Examples

### E-commerce Events

```php
class AddToCartEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'ADD_TO_CART';
    }

    public static function rules(): array
    {
        return [
            'payload.product_id' => 'required|integer|exists:products,id',
            'payload.quantity' => 'required|integer|min:1|max:100',
        ];
    }
}

class CheckoutEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'CHECKOUT';
    }

    public static function rules(): array
    {
        return [
            'payload.shipping_method' => 'required|in:standard,express,overnight',
            'payload.payment_method' => 'required|in:card,paypal,bank',
            'payload.address_id' => 'required|exists:addresses,id',
        ];
    }
}
```

### Financial Events

```php
class TransferFundsEvent extends EventBehavior
{
    public bool $isTransactional = true;

    public static function getType(): string
    {
        return 'TRANSFER_FUNDS';
    }

    public static function rules(): array
    {
        return [
            'payload.amount' => 'required|numeric|min:0.01|max:1000000',
            'payload.from_account' => 'required|exists:accounts,id',
            'payload.to_account' => 'required|exists:accounts,id|different:payload.from_account',
            'payload.description' => 'nullable|string|max:255',
        ];
    }

    public function actor(ContextManager $context): mixed
    {
        return [
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
        ];
    }
}
```

### Workflow Events

```php
class ApproveRequestEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'APPROVE';
    }

    public static function rules(): array
    {
        return [
            'payload.comment' => 'nullable|string|max:500',
            'payload.conditions' => 'nullable|array',
        ];
    }

    public function actor(ContextManager $context): mixed
    {
        $user = auth()->user();
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
        ];
    }
}

class RejectRequestEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'REJECT';
    }

    public static function rules(): array
    {
        return [
            'payload.reason' => 'required|string|min:10|max:500',
        ];
    }
}
```

## Scenario Support

Events can specify scenarios:

```php
$machine->send([
    'type' => 'SUBMIT',
    'payload' => [
        'scenarioType' => 'test',  // Use 'test' scenario
        'data' => [...],
    ],
]);
```

See [Scenarios](/advanced/scenarios) for details.

## Event in Actions

Access event data in behaviors:

```php
class ProcessOrderAction extends ActionBehavior
{
    public function __invoke(
        ContextManager $context,
        EventBehavior $event,
    ): void {
        // Access typed properties (class events)
        if ($event instanceof AddItemEvent) {
            $productId = $event->productId;
        }

        // Access payload (array events)
        $productId = $event->payload['productId'] ?? null;

        // Access event type
        $type = $event->type;
    }
}
```

## Testing Events

```php
it('validates event payload', function () {
    $machine = OrderMachine::create();

    expect(fn() => $machine->send([
        'type' => 'ADD_ITEM',
        'payload' => [
            'quantity' => -1,  // Invalid
        ],
    ]))->toThrow(MachineEventValidationException::class);
});

it('processes valid event', function () {
    $machine = OrderMachine::create();

    $machine->send(new AddItemEvent(
        productId: 123,
        quantity: 2,
    ));

    expect($machine->state->context->items)->toHaveCount(1);
});
```

## Best Practices

### 1. Use Descriptive Event Names

```php
// Good
'ORDER_SUBMITTED'
'PAYMENT_PROCESSED'
'INVENTORY_RESERVED'

// Avoid
'SUBMIT'
'PROCESS'
'UPDATE'
```

### 2. Validate at Event Level

```php
class SubmitEvent extends EventBehavior
{
    public static function rules(): array
    {
        return [
            'payload.items' => 'required|array|min:1',
        ];
    }
}
```

### 3. Use Classes for Complex Events

```php
// Simple event - array is fine
$machine->send(['type' => 'CANCEL']);

// Complex event - use class
$machine->send(new TransferEvent(
    amount: 1000,
    recipientId: 'user-123',
));
```

### 4. Include Actor Information

```php
public function actor(ContextManager $context): mixed
{
    return auth()->user() ?? 'anonymous';
}
```
