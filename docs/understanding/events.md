# Events

Events are the triggers that cause your state machine to transition from one state to another. They represent "something happened" in your system.

## Sending Events

The basic way to send an event:

```php
$machine->send(['type' => 'PAY']);
```

With payload data:

```php
$machine->send([
    'type' => 'PAY',
    'amount' => 99.99,
    'method' => 'credit_card',
    'transaction_id' => 'txn_123',
]);
```

The `send()` method returns the new `State`:

```php
$state = $machine->send(['type' => 'SHIP']);

$state->matches('shipped');           // true
$state->context->get('shipped_at');   // timestamp
```

## Event Structure

Every event has a `type` - the event name that matches transitions:

```php
// This event...
$machine->send(['type' => 'PAY']);

// ...matches this transition
'pending' => [
    'on' => [
        'PAY' => 'paid',  // <-- type matches
    ],
]
```

Everything else in the event array is **payload** - data that actions and guards can access:

```php
$machine->send([
    'type' => 'PAY',
    // Everything below is payload
    'amount' => 99.99,
    'method' => 'credit_card',
]);
```

## Accessing Event Data in Behaviors

Actions receive the event:

```php
'actions' => [
    'processPayment' => function ($context, $event) {
        $amount = $event->payload['amount'];
        $method = $event->payload['method'];

        // Process the payment...

        $context->set('paid_amount', $amount);
    },
]
```

Using class-based actions:

```php
class ProcessPaymentAction extends ActionBehavior
{
    public function __invoke(
        ContextManager $context,
        EventBehavior $event
    ): void {
        $amount = $event->payload['amount'];
        // ...
    }
}
```

## Custom Event Classes

For type safety and validation, define event classes:

```php
namespace App\Machines\Events;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class PayEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'PAY';
    }

    public function __construct(
        public readonly float $amount,
        public readonly string $method,
        public readonly ?string $transactionId = null,
    ) {}
}
```

Register in your machine:

```php
MachineDefinition::define(
    config: [...],
    behavior: [
        'events' => [
            PayEvent::class,
        ],
    ],
);
```

Send using the class:

```php
$machine->send(new PayEvent(
    amount: 99.99,
    method: 'credit_card',
    transactionId: 'txn_123',
));
```

Benefits:
- **Type safety** - IDE autocomplete for event properties
- **Validation** - Constructor enforces required fields
- **Documentation** - Event structure is explicit

## Event Versioning

Events support versioning for schema evolution:

```php
class PayEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'PAY';
    }

    public int $version = 2;  // Current version

    public function __construct(
        public readonly float $amount,
        public readonly string $method,
        public readonly string $currency = 'USD',  // Added in v2
    ) {}
}
```

## Invalid Events

If you send an event that has no matching transition, nothing happens:

```php
// Machine is in 'shipped' state
$machine->send(['type' => 'PAY']);  // No transition for PAY in 'shipped'

// Machine stays in 'shipped' - no error thrown
$machine->state->matches('shipped');  // true
```

To detect if a transition happened, check the state:

```php
$before = $machine->state->currentStateDefinition->id;
$machine->send(['type' => 'SOME_EVENT']);
$after = $machine->state->currentStateDefinition->id;

if ($before === $after) {
    // No transition happened
}
```

## Raised Events

Actions can raise additional events to be processed after the current transition:

```php
class ProcessPaymentAction extends ActionBehavior
{
    public function __invoke(
        ContextManager $context,
        EventBehavior $event
    ): void {
        // Process payment...

        // Raise follow-up event
        $this->raise([
            'type' => 'PAYMENT_CONFIRMED',
            'confirmation_id' => $confirmationId,
        ]);
    }
}
```

Raised events are queued and processed in order after the main transition completes.

See [Raised Events](/advanced/raised-events) for details.

## Internal Events

EventMachine uses special internal events:

| Event | When |
|-------|------|
| `machine.start` | Machine initialized |
| `machine.stop` | Machine reached final state |

You generally don't need to handle these directly.

## Event Sourcing

Every event sent is automatically persisted:

```php
$machine->send(['type' => 'PAY', 'amount' => 99.99]);
$machine->send(['type' => 'SHIP', 'tracking' => 'ABC123']);
```

Creates records in `machine_events`:

```
| id | type | payload | machine_value |
|----|------|---------|---------------|
| 1  | PAY  | {"amount":99.99} | ["order.paid"] |
| 2  | SHIP | {"tracking":"ABC123"} | ["order.shipped"] |
```

Access event history:

```php
$history = $machine->state->history;

foreach ($history as $event) {
    echo $event->type;        // PAY, SHIP
    echo $event->payload;     // Event payload
    echo $event->created_at;  // When it happened
}
```

## Best Practices

### Use Past Tense for External Events

Events represent things that happened:

```php
// Good - past tense, something happened
'PAYMENT_RECEIVED'
'ORDER_PLACED'
'USER_REGISTERED'

// Avoid - imperative, sounds like a command
'PAY'
'PLACE_ORDER'
'REGISTER_USER'
```

### Use Descriptive Event Names

```php
// Good - specific and clear
'CREDIT_CARD_PAYMENT_RECEIVED'
'SHIPPING_LABEL_GENERATED'
'CUSTOMER_SUPPORT_CONTACTED'

// Avoid - too generic
'UPDATE'
'CHANGE'
'DO_THING'
```

### Include Relevant Payload

```php
// Good - all data needed for processing
$machine->send([
    'type' => 'PAYMENT_RECEIVED',
    'amount' => 99.99,
    'currency' => 'USD',
    'method' => 'credit_card',
    'transaction_id' => 'txn_123',
    'processed_at' => now(),
]);

// Avoid - missing context
$machine->send([
    'type' => 'PAYMENT_RECEIVED',
    // Where's the amount? Method? Transaction ID?
]);
```

## Next Steps

- [Context](/understanding/context) - Data that flows with events
- [Machine Lifecycle](/understanding/machine-lifecycle) - Complete flow
- [Event Behaviors](/behaviors/events) - Custom event classes in depth
