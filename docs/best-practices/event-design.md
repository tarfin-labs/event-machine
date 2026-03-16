# Event Design

Events are the inputs to your machine. They represent things that have happened -- facts about the world that the machine reacts to. Getting event design right makes your machine self-documenting and your transitions unambiguous.

## Events Are Facts, Not Commands

Events describe what _happened_, not what _should_ happen. Use past tense.

```php ignore
// Do: past tense -- facts

'on' => [
    'ORDER_SUBMITTED'    => 'processing',
    'PAYMENT_RECEIVED'   => 'paid',
    'SHIPMENT_DISPATCHED' => 'shipped',
    'DELIVERY_CONFIRMED' => 'completed',
],
```

```php ignore
// Don't: imperative -- commands

'on' => [
    'SUBMIT_ORDER'  => 'processing',    // Who is commanding whom?
    'PAY'           => 'paid',           // Too vague
    'SHIP_ORDER'    => 'shipped',        // Command, not fact
],
```

The distinction matters for clarity. When you read `ORDER_SUBMITTED => processing`, you understand: "when the fact of submission is recorded, the order moves to processing". Commands blur this: `SUBMIT_ORDER => processing` reads like the machine _is_ the submitter.

## Be Specific

Generic event types make machines ambiguous and fragile.

```php ignore
// Anti-pattern: generic events

'on' => [
    'UPDATE'  => 'processing',    // Update what?
    'CHANGE'  => 'modified',      // Change what?
    'ACTION'  => 'handled',       // Which action?
],
```

```php ignore
// Do: specific events

'on' => [
    'SHIPPING_ADDRESS_UPDATED' => 'processing',
    'PAYMENT_METHOD_CHANGED'   => 'modified',
    'REFUND_REQUESTED'         => 'handling_refund',
],
```

Specific events also make debugging easier. When you see `SHIPPING_ADDRESS_UPDATED` in the event log, you know exactly what happened without reading the payload.

## Anti-Pattern: State-Encoded Event Names

```php ignore
// Anti-pattern: encoding state into the event name

'on' => [
    'APPROVE_AS_MANAGER'  => 'approved',
    'APPROVE_AS_DIRECTOR' => 'approved',
    'APPROVE_AS_VP'       => 'approved',
],
```

Three events that do the same thing. The "who" belongs in the payload, not the event type.

**Fix:** One event, role in payload.

```php ignore
'on' => [
    'ORDER_APPROVED' => [
        'target'  => 'approved',
        'actions' => 'recordApproverAction',  // reads role from payload
    ],
],
```

```php no_run
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class OrderApprovedEvent extends EventBehavior
{
    public function getType(): string
    {
        return 'ORDER_APPROVED';
    }

    public function getPayload(): array
    {
        return [
            'approved_by' => $this->data['approved_by'],  // 'manager', 'director', 'vp'
            'approved_at' => $this->data['approved_at'],
        ];
    }
}
```

If different approver roles require different transitions (manager goes to one state, director to another), use guards on the same event rather than separate event types:

```php ignore
'on' => [
    'ORDER_APPROVED' => [
        ['target' => 'awaiting_director_approval', 'guards' => 'isManagerApprovalGuard'],
        ['target' => 'processing'],   // director/VP approval completes the flow
    ],
],
```

## Class vs String Events

EventMachine supports both string event types and class-based events. Choose based on complexity.

**String events** for simple cases with no payload or validation:

```php ignore
'on' => [
    'ORDER_CANCELLED' => 'cancelled',
    'RETRY_REQUESTED' => 'retrying',
],
```

**Class events** when you need typed payloads or validation:

```php no_run
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class PaymentReceivedEvent extends EventBehavior
{
    public function getType(): string
    {
        return 'PAYMENT_RECEIVED';
    }

    public function getPayload(): array
    {
        return [
            'transaction_id' => $this->data['transaction_id'],
            'amount'         => $this->data['amount'],
            'currency'       => $this->data['currency'],
        ];
    }
}
```

Generally, start with string events. Upgrade to class-based when you need validation or typed payload access.

## v7 Messaging: raise() vs sendTo() vs dispatchTo()

EventMachine v7 provides three ways to send events. Each has a distinct scope and execution model.

| Method | Target | Execution | Use Case |
|--------|--------|-----------|----------|
| `raise()` | Same machine | Sync, same macrostep | Internal workflow progression |
| `sendTo()` | Any machine | Sync, blocking | Immediate cross-machine coordination |
| `dispatchTo()` | Any machine | Async, queued | Fire-and-forget notifications |

```php no_run
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\ContextManager;

class CompleteOrderAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // Internal: next step in this machine
        $this->raise(['type' => 'ORDER_FINALIZED']);

        // Sync: update inventory immediately
        $this->sendTo(
            machineClass: InventoryMachine::class,
            rootEventId: $context->get('inventory_machine_id'),
            event: ['type' => 'STOCK_RESERVED'],
        );

        // Async: notify analytics (fire-and-forget)
        $this->dispatchTo(
            machineClass: AnalyticsMachine::class,
            rootEventId: $context->get('analytics_machine_id'),
            event: ['type' => 'ORDER_COMPLETED_TRACKED'],
        );
    }
}
```

## Example: E-Commerce Event Taxonomy

A well-designed event taxonomy for an order workflow:

```php ignore
// Order lifecycle events
'ORDER_SUBMITTED'
'ORDER_CONFIRMED'
'ORDER_CANCELLED'
'ORDER_COMPLETED'

// Payment events
'PAYMENT_RECEIVED'
'PAYMENT_DECLINED'
'PAYMENT_REFUNDED'

// Shipping events
'SHIPMENT_DISPATCHED'
'SHIPMENT_DELAYED'
'DELIVERY_CONFIRMED'

// Internal progression (raised by actions)
'VALIDATION_PASSED'
'VALIDATION_FAILED'
'INVENTORY_RESERVED'
'INVENTORY_UNAVAILABLE'

// Timer events
'ORDER_EXPIRED'
'PAYMENT_REMINDER'
'RETRY_PAYMENT'
```

Notice the pattern: `{DOMAIN}_{PAST_PARTICIPLE}` for external facts, `{DOMAIN}_{PAST_PARTICIPLE}` for internal progression, `{DOMAIN}_{NOUN}` for recurring timer events.

## Guidelines

1. **Past tense for event types.** `ORDER_SUBMITTED`, not `SUBMIT_ORDER`. Events are facts.

2. **Be specific.** `SHIPPING_ADDRESS_UPDATED`, not `UPDATE`. Future-you will thank past-you when reading logs.

3. **Payload carries data, type carries intent.** Do not encode variable information (actor, amount, status) into the event type. Use the payload.

4. **Start with strings, upgrade to classes.** Class-based events add value when you need validation or typed payloads.

5. **No abbreviations.** `ORDER_SUBMITTED`, not `ORD_SUB`. Clarity over brevity.

## Related

- [Events](/behaviors/events) -- reference documentation
- [Naming Conventions](/building/conventions) -- event naming rules
- [Raised Events](/advanced/raised-events) -- `raise()` mechanics
- [Cross-Machine Messaging](/advanced/sendto) -- `sendTo()` and `dispatchTo()`
