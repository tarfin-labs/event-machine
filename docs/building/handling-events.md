# Handling Events

Events are the triggers that cause state machines to transition. This guide covers sending events and creating custom event classes.

## Sending Events

### Array Syntax

The simplest way to send an event:

```php no_run
$machine = OrderMachine::create();

$state = $machine->send([
    'type' => 'SUBMIT',
]);
```

### With Payload

Include data with your event:

```php no_run
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

```php no_run
$state = $machine->send([
    'type' => 'APPROVE',
    'actor' => $currentUser->id,
]);
```

## Custom Event Classes

For complex events, create dedicated classes:

```php no_run
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

```php ignore
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

```php no_run
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

```php no_run
// This will throw validation exception
$machine->send(PaymentEvent::from([
    'paymentMethod' => 'card',
    'amount' => 0,  // Fails Min(1) validation
]));
```

## Accessing Event Data in Actions

### From Array Events

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
use Tarfinlabs\EventMachine\Behavior\EventBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

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

```php no_run
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

```php ignore
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

```php ignore
'on' => [
    AddItemEvent::class => ['actions' => 'addItem'],
    RemoveItemEvent::class => ['actions' => 'removeItem'],
],
```

## Raising Events from Actions

Actions can raise events that are processed after the current transition:

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

class ProcessOrderAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // Process order...

        // Raise a follow-up event
        $this->raise([
            'type' => 'SEND_CONFIRMATION',
            'payload' => ['orderId' => $context->get('orderId')],
        ]);
    }
}
```

The `raise()` method is inherited from `InvokableBehavior` and queues events to be processed in order after the current transition completes.

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

```php ignore
class PaymentEvent extends EventBehavior
{
    public bool $isTransactional = true;  // Default

    // ...
}
```

Disable for events that shouldn't roll back:

```php ignore
class LogEvent extends EventBehavior
{
    public bool $isTransactional = false;

    // ...
}
```

Or per-event:

```php no_run
$machine->send([
    'type' => 'LOG',
    'isTransactional' => false,
]);
```

## Actor Tracking

Events can track who triggered them:

```php no_run
$machine->send([
    'type' => 'APPROVE',
    'actor' => auth()->id(),
]);
```

Custom logic in event classes:

```php no_run
class ApprovalEvent extends EventBehavior
{
    public function actor(ContextManager $context): mixed
    {
        // Custom actor resolution
        return $this->payload['approvedBy'] ?? auth()->id();
    }
}
```

## Event Source Types

Events have a `source` property indicating where they originated:

```php no_run
use Tarfinlabs\EventMachine\Enums\SourceType;

// SourceType::EXTERNAL - Events sent by your code
$machine->send(['type' => 'PAY']);  // source = EXTERNAL

// SourceType::INTERNAL - Events generated by the machine
// (lifecycle events, raised events from actions)
```

Query events by source:

```php no_run
// Get only user-triggered events
$userEvents = $machine->state->history
    ->filter(fn($event) => $event->source === SourceType::EXTERNAL);

// Get internal lifecycle events
$lifecycleEvents = $machine->state->history
    ->filter(fn($event) => $event->source === SourceType::INTERNAL);
```

## Internal Events

EventMachine fires internal events throughout the machine lifecycle. These are recorded in the event history and useful for debugging, auditing, and observability.

### Complete Internal Events Reference

| Event Pattern | When Fired |
|--------------|------------|
| `{machine}.start` | Machine initializes |
| `{machine}.finish` | Machine reaches final state |
| `{machine}.state.{state}.enter` | Entering a state |
| `{machine}.state.{state}.entry.start` | Entry actions starting |
| `{machine}.state.{state}.entry.finish` | Entry actions completed |
| `{machine}.state.{state}.exit.start` | Exit actions starting |
| `{machine}.state.{state}.exit.finish` | Exit actions completed |
| `{machine}.state.{state}.exit` | Exited a state |
| `{machine}.transition.{state}.{event}.start` | Transition beginning |
| `{machine}.transition.{state}.{event}.finish` | Transition completed |
| `{machine}.transition.{state}.{event}.fail` | Transition failed |
| `{machine}.action.{action}.start` | Action starting |
| `{machine}.action.{action}.finish` | Action completed |
| `{machine}.guard.{guard}.pass` | Guard passed |
| `{machine}.guard.{guard}.fail` | Guard failed |
| `{machine}.calculator.{calculator}.pass` | Calculator succeeded |
| `{machine}.calculator.{calculator}.fail` | Calculator threw exception |
| `{machine}.event.{event}.raised` | Event raised from action |

### Example Event History

```php no_run
$machine = OrderMachine::create();
$machine->send(['type' => 'SUBMIT']);

// Event history shows complete lifecycle:
$machine->state->history->pluck('type')->toArray();
// [
//     'order.start',
//     'order.state.pending.enter',
//     'order.state.pending.entry.start',
//     'order.state.pending.entry.finish',
//     'SUBMIT',
//     'order.transition.pending.SUBMIT.start',
//     'order.guard.hasItems.pass',
//     'order.action.processOrder.start',
//     'order.action.processOrder.finish',
//     'order.transition.pending.SUBMIT.finish',
//     'order.state.pending.exit.start',
//     'order.state.pending.exit.finish',
//     'order.state.pending.exit',
//     'order.state.submitted.enter',
//     ...
// ]
```

### Filtering Internal Events

```php no_run
// Get only transition events
$transitions = $machine->state->history
    ->filter(fn($e) => str_contains($e->type, '.transition.'));

// Get failed guards
$failedGuards = $machine->state->history
    ->filter(fn($e) => str_ends_with($e->type, '.fail'))
    ->filter(fn($e) => str_contains($e->type, '.guard.'));
```

::: tip
Internal events have `source = SourceType::INTERNAL`. They're recorded for observability but don't trigger transitions.
:::

::: warning Storage Impact
With persistence enabled, internal events are stored in the `machine_events` table. A single transition can generate 10+ internal events (enter, exit, guard, action lifecycle events). For long-running machines or high-frequency state changes, consider:
- Archiving old events periodically with `php artisan machine:archive-events`
- Disabling persistence for ephemeral machines (`'should_persist' => false`)
- Querying external events only when displaying history to users
:::

## Reserved Events

The `@always` event is reserved for automatic transitions:

```php ignore
'on' => [
    '@always' => [
        'target' => 'nextState',
        'guards' => 'shouldTransition',
    ],
],
```

## Complete Example

```php ignore
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
