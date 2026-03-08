# Events

Events are the triggers that cause your state machine to transition from one state to another. They represent "something happened" in your system.

## Sending Events

The basic way to send an event:

```php no_run
$machine->send(['type' => 'PAY']);
```

With payload data:

```php no_run
$machine->send([
    'type' => 'PAY',
    'amount' => 99.99,
    'method' => 'credit_card',
]);
```

## Event Structure

Every event has a `type` that matches transitions:

```php ignore
// This event...
$machine->send(['type' => 'PAY']);

// ...matches this transition
'pending' => [
    'on' => [
        'PAY' => 'paid',  // type matches
    ],
]
```

Everything else in the event array is **payload** - data that actions and guards can access.

## Accessing Event Data

Actions receive the event:

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\ContextManager;

class ProcessPaymentAction extends ActionBehavior
{
    public function __invoke(
        ContextManager $context,
        EventBehavior $event
    ): void {
        $amount = $event->payload['amount'];
        $method = $event->payload['method'];
        // Process...
    }
}
```

## Checking Event Availability

Before sending an event, you can check if the current state can handle it:

<!-- doctest-attr: ignore -->
```php
// Check by type string
$machine->can('PAY');  // true if PAY transition exists in current state

// Check by event class
$machine->can(PaymentEvent::class);  // resolves the class to its type string

// Check with an event instance
$machine->can(new PaymentEvent(...));  // uses the instance's type
```

You can also query which events the current state accepts:

<!-- doctest-attr: ignore -->
```php
// Get all event types available in the current state
$events = $machine->getAcceptedEvents();
// ['PAY' => PaymentEvent::class, 'CANCEL' => CancelEvent::class]
```

## Invalid Events

If you send an event with no matching transition, EventMachine throws `NoTransitionDefinitionFoundException`:

```php no_run
use Tarfinlabs\EventMachine\Exceptions\NoTransitionDefinitionFoundException;

try {
    $machine->send(['type' => 'PAY']);  // No transition for PAY
} catch (NoTransitionDefinitionFoundException $e) {
    // Handle invalid event
}
```

::: tip
Use `can()` to check before sending if you want to avoid exceptions. This is useful for conditionally showing UI elements like buttons or menu items.
:::

## Internal Events

EventMachine fires internal events for lifecycle tracking:

| Event | When |
|-------|------|
| `machine.start` | Machine initialized |
| `machine.finish` | Machine reached final state |

## Learn More

For complete events documentation including:

- Custom event classes with validation
- Event versioning
- Raised events from actions
- Transactional events
- Actor tracking
- Event introspection (`can()` and `getAcceptedEvents()`)
- Complete internal events reference (18 events)
- Event source types (INTERNAL vs EXTERNAL)

See **[Handling Events](/building/handling-events)**.
