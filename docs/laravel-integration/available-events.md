# Available Events

HATEOAS-style event discoverability — the machine tells the consumer what it can do next. Instead of hard-coding allowed transitions in the client, each response includes the events the machine can currently accept.

## Core API

Available events are computed from the current state's transition definitions. Three access points expose the same data:

```php no_run
use Tarfinlabs\EventMachine\Actor\Machine;

// 1. Via the machine (convenience proxy)
$events = $machine->availableEvents();

// 2. Via the state (core method)
$events = $machine->state->availableEvents();

// 3. Via toArray() — auto-included
$array = $machine->state->toArray();
// $array['available_events'] contains the same data
```

Each entry in the returned array is an associative array with these keys:

| Key | Type | Always present | Description |
|-----|------|----------------|-------------|
| `type` | `string` | Yes | The event type name (e.g., `APPROVE`, `PROVIDE_CARD`) |
| `source` | `string` | Yes | Where the event is handled: `parent` or `forward` |
| `region` | `string` | Only in parallel states | The active region this event belongs to |

## HTTP Response Format

When an endpoint returns the default response (no custom `ResultBehavior`), `available_events` is included automatically:

<!-- doctest-attr: ignore -->
```json
{
    "data": {
        "machine_id": "01JARX5Z8KQVN...",
        "value": ["awaiting_approval"],
        "context": {
            "order_id": 42,
            "total_amount": 15000
        },
        "available_events": [
            { "type": "APPROVE", "source": "parent" },
            { "type": "REJECT", "source": "parent" },
            { "type": "PROVIDE_CARD", "source": "forward" }
        ]
    }
}
```

In parallel states, each event includes its `region`:

<!-- doctest-attr: ignore -->
```json
{
    "data": {
        "machine_id": "01JARX5Z8KQVN...",
        "value": [
            "fulfillment.payment.pending",
            "fulfillment.shipping.preparing"
        ],
        "context": {},
        "available_events": [
            { "type": "PAY", "source": "parent", "region": "payment" },
            { "type": "SHIP", "source": "parent", "region": "shipping" }
        ]
    }
}
```

### Opting Out

To exclude `available_events` from a specific endpoint's response, set `available_events` to `false` in the endpoint definition:

```php ignore
'SUBMIT' => [
    'available_events' => false,
],
```

When a custom `ResultBehavior` is used, `available_events` is not added to the response automatically — the result behavior has full control over the response shape. You can still call `$state->availableEvents()` inside your result if needed.

## Source Annotations

| Source | Meaning |
|--------|---------|
| `parent` | Direct on-event transition defined on the current state |
| `forward` | Event that will be forwarded to an async child machine |

The source annotation helps API consumers understand the event's routing. A `forward` event is sent to the parent endpoint but is internally forwarded to the running child machine.

## Forward-Aware Behavior

Available events are dynamic. Forward events only appear when the child machine is in a state that accepts them:

```php ignore
// Parent machine in 'processing_payment' state
// Child machine in 'awaiting_card' state
$events = $machine->availableEvents();
// → [
//     ['type' => 'CANCEL', 'source' => 'parent'],
//     ['type' => 'PROVIDE_CARD', 'source' => 'forward'],  // child accepts this
// ]

// After sending PROVIDE_CARD, child moves to 'verifying' state
$events = $machine->availableEvents();
// → [
//     ['type' => 'CANCEL', 'source' => 'parent'],
//     // PROVIDE_CARD is gone — child no longer accepts it
// ]
```

This ensures the API never advertises events that would be rejected. The check works by:

1. Looking up the child machine's current state (via `MachineCurrentState` table or cached forward state)
2. Checking if the child state's transition definitions include the forwarded event type
3. Only including the event if the child can accept it

When no child machine is running (e.g., delegation hasn't started yet), forward events are excluded entirely.

## Testing

`TestMachine` provides five assertion methods for available events:

```php no_run
use Tarfinlabs\EventMachine\Testing\TestMachine;

// Assert a specific event is available
$testMachine->assertAvailableEvent('APPROVE');

// Assert a specific event is NOT available
$testMachine->assertNotAvailableEvent('SUBMIT');

// Assert the exact set of available events (order-independent)
$testMachine->assertAvailableEvents(['APPROVE', 'CANCEL']);

// Assert a forward event is available (checks source === 'forward')
$testMachine->assertForwardAvailable('PROVIDE_CARD');

// Assert no events are available (final state, etc.)
$testMachine->assertNoAvailableEvents();
```

### Testing Forward Availability

```php no_run
use Tarfinlabs\EventMachine\Testing\TestMachine;

// Forward event appears only when child accepts it
$testMachine = TestMachine::start(OrderMachine::class);
$testMachine->send('SUBMIT');

// Now in processing_payment — child is in awaiting_card
$testMachine->assertForwardAvailable('PROVIDE_CARD');
$testMachine->assertAvailableEvent('CANCEL');

// Send the forward event — child moves past awaiting_card
$testMachine->send('PROVIDE_CARD', ['card_number' => '4242424242424242']);

// PROVIDE_CARD should no longer be available
$testMachine->assertNotAvailableEvent('PROVIDE_CARD');
```

## What's Excluded

The following event types are automatically excluded from `available_events` because they are internal and not user-sendable:

| Event Pattern | Reason |
|---------------|--------|
| `@always` | Internal automatic transition — fires without user input |
| `@done` | Child machine completion callback |
| `@fail` | Child machine failure callback |
| `@timeout` | Child machine timeout callback |
| Internal events | Framework-level events (e.g., `xstate.init`) |

Only events that a consumer can actually `POST` to an endpoint are included in the available events list.
