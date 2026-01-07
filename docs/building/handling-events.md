# Handling Events

Events are the triggers that cause state machines to transition. This guide covers sending events and creating custom event classes.

## Sending Events

### Array Syntax

The simplest way to send an event:

```php
$machine = OrderMachine::create();

$state = $machine->send([
    'type' => 'SUBMIT',
]);
```

### With Payload

Include data with your event:

```php
$state = $machine->send([
    'type' => 'ADD_ITEM',
    'payload' => [
        'productId' => 123,
        'quantity' => 2,
        'price' => 1999,
    ],
]);
```

### With Actor

Track who triggered the event:

```php
$state = $machine->send([
    'type' => 'APPROVE',
    'actor' => $currentUser->id,
]);
```

## Custom Event Classes

For complex events, create dedicated classes:

```php
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Min;

class AddItemEvent extends EventBehavior
{
    public function __construct(
        #[Required]
        public int $productId,

        #[Required, Min(1)]
        public int $quantity = 1,

        public ?int $price = null,
    ) {
        parent::__construct();
    }

    public static function getType(): string
    {
        return 'ADD_ITEM';
    }
}
```

### Using Custom Events

```php
// In transition definition
'on' => [
    AddItemEvent::class => [
        'actions' => 'addItemToCart',
    ],
],

// Sending the event
$state = $machine->send(AddItemEvent::from([
    'productId' => 123,
    'quantity' => 2,
]));
```

### Event Validation

Custom events are validated automatically:

```php
class PaymentEvent extends EventBehavior
{
    public function __construct(
        #[Required]
        public string $paymentMethod,

        #[Required, Min(1)]
        public int $amount,

        #[Email]
        public ?string $receiptEmail = null,
    ) {
        parent::__construct();
    }

    public static function getType(): string
    {
        return 'PAY';
    }
}
```

Invalid events throw `MachineEventValidationException`:

```php
// This will throw validation exception
$machine->send(PaymentEvent::from([
    'paymentMethod' => 'card',
    'amount' => 0,  // Fails Min(1) validation
]));
```

## Accessing Event Data in Actions

### From Array Events

```php
class AddItemAction extends ActionBehavior
{
    public function __invoke(
        ContextManager $context,
        EventBehavior $event
    ): void {
        $productId = $event->payload['productId'];
        $quantity = $event->payload['quantity'];

        // Add to cart...
    }
}
```

### From Custom Event Classes

```php
class AddItemAction extends ActionBehavior
{
    public function __invoke(
        ContextManager $context,
        AddItemEvent $event  // Type-hinted!
    ): void {
        $productId = $event->productId;  // Direct property access
        $quantity = $event->quantity;

        // Add to cart...
    }
}
```

## Event Registration

Register event classes in the behavior array:

```php
MachineDefinition::define(
    config: [...],
    behavior: [
        'events' => [
            'ADD_ITEM' => AddItemEvent::class,
            'REMOVE_ITEM' => RemoveItemEvent::class,
            'CHECKOUT' => CheckoutEvent::class,
        ],
    ],
);
```

Or reference classes directly in transitions:

```php
'on' => [
    AddItemEvent::class => ['actions' => 'addItem'],
    RemoveItemEvent::class => ['actions' => 'removeItem'],
],
```

## Raising Events from Actions

Actions can raise events that are processed after the current transition:

```php
use Illuminate\Support\Collection;

class ProcessOrderAction extends ActionBehavior
{
    public function __construct(
        protected Collection $eventQueue
    ) {}

    public function __invoke(ContextManager $context): void
    {
        // Process order...

        // Raise a follow-up event
        $this->eventQueue->push([
            'type' => 'SEND_CONFIRMATION',
            'payload' => ['orderId' => $context->get('orderId')],
        ]);
    }
}
```

Queued events are processed in order after the current transition completes.

## Event Properties

| Property | Type | Description |
|----------|------|-------------|
| `type` | string | Event identifier |
| `payload` | array | Event data |
| `actor` | mixed | Who triggered the event |
| `version` | int | Event version (default: 1) |
| `source` | SourceType | EXTERNAL or INTERNAL |
| `isTransactional` | bool | Wrap in DB transaction |

## Transactional Events

By default, events are wrapped in database transactions:

```php
class PaymentEvent extends EventBehavior
{
    public bool $isTransactional = true;  // Default

    // ...
}
```

Disable for events that shouldn't roll back:

```php
class LogEvent extends EventBehavior
{
    public bool $isTransactional = false;

    // ...
}
```

Or per-event:

```php
$machine->send([
    'type' => 'LOG',
    'isTransactional' => false,
]);
```

## Actor Tracking

Events can track who triggered them:

```php
$machine->send([
    'type' => 'APPROVE',
    'actor' => auth()->id(),
]);
```

Custom logic in event classes:

```php
class ApprovalEvent extends EventBehavior
{
    public function actor(ContextManager $context): mixed
    {
        // Custom actor resolution
        return $this->payload['approvedBy'] ?? auth()->id();
    }
}
```

## Internal Events

EventMachine uses internal events for lifecycle tracking:

| Event | When Fired |
|-------|------------|
| `machine.start` | Machine initializes |
| `machine.finish` | Machine reaches final state |
| `state.entry.{state}` | Entering a state |
| `state.exit.{state}` | Exiting a state |
| `transition.start` | Transition begins |
| `transition.finish` | Transition completes |
| `action.start.{action}` | Action begins |
| `action.finish.{action}` | Action completes |
| `guard.pass.{guard}` | Guard passes |
| `guard.fail.{guard}` | Guard fails |

These are used for debugging and event history, not for transitions.

## Reserved Events

The `@always` event is reserved for automatic transitions:

```php
'on' => [
    '@always' => [
        'target' => 'nextState',
        'guards' => 'shouldTransition',
    ],
],
```

## Complete Example

```php
// Event class
class CheckoutEvent extends EventBehavior
{
    public function __construct(
        #[Required]
        public string $shippingAddress,

        #[Required]
        public string $paymentMethod,

        public ?string $couponCode = null,
    ) {
        parent::__construct();
    }

    public static function getType(): string
    {
        return 'CHECKOUT';
    }
}

// Machine definition
MachineDefinition::define(
    config: [
        'id' => 'cart',
        'initial' => 'browsing',
        'states' => [
            'browsing' => [
                'on' => [
                    'ADD_ITEM' => ['actions' => 'addItem'],
                    CheckoutEvent::class => [
                        'target' => 'processing',
                        'guards' => 'hasItems',
                        'actions' => ['validateAddress', 'startCheckout'],
                    ],
                ],
            ],
            'processing' => [
                'entry' => 'processOrder',
                'on' => [
                    '@always' => [
                        ['target' => 'completed', 'guards' => 'isProcessed'],
                        ['target' => 'failed'],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
            'failed' => ['type' => 'final'],
        ],
    ],
    behavior: [
        'events' => [
            'CHECKOUT' => CheckoutEvent::class,
        ],
        'actions' => [...],
        'guards' => [...],
    ],
);

// Usage
$cart = CartMachine::create();

// Add items
$cart->send(['type' => 'ADD_ITEM', 'payload' => ['productId' => 1]]);
$cart->send(['type' => 'ADD_ITEM', 'payload' => ['productId' => 2]]);

// Checkout with typed event
$state = $cart->send(CheckoutEvent::from([
    'shippingAddress' => '123 Main St',
    'paymentMethod' => 'card',
    'couponCode' => 'SAVE10',
]));

echo $state->value; // 'completed' or 'failed'
```

## Next Steps

- [Configuration](/building/configuration) - Machine configuration options
- [Actions](/behaviors/actions) - Handle events with side effects
- [Testing](/testing/introduction) - Test event handling
