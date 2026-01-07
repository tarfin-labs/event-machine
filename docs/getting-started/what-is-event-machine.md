# What is EventMachine?

EventMachine is an **event-driven state machine library** for Laravel, inspired by [XState](https://xstate.js.org/). It helps you model complex application workflows as predictable, testable state machines with full event sourcing support.

## The Problem

Consider an order processing system. An order can be:
- **Pending** - waiting for payment
- **Paid** - payment received, ready to ship
- **Shipped** - on the way to customer
- **Delivered** - customer received it
- **Cancelled** - order was cancelled

Without a state machine, you might write code like this:

```php
class Order extends Model
{
    public function pay(): void
    {
        if ($this->status !== 'pending') {
            throw new Exception('Can only pay pending orders');
        }

        $this->status = 'paid';
        $this->paid_at = now();
        $this->save();

        // Send confirmation email
        // Update inventory
        // Notify warehouse
    }

    public function ship(): void
    {
        if ($this->status !== 'paid') {
            throw new Exception('Can only ship paid orders');
        }

        // More conditional logic...
    }

    // ... more methods with more conditions
}
```

This approach has problems:
- **Scattered logic** - validation spread across methods
- **Hidden states** - possible states not explicitly defined
- **Hard to test** - need to test many conditional paths
- **No history** - can't see how order got to current state

## The Solution

EventMachine models your workflow as an explicit state machine:

```php
class OrderMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id' => 'order',
                'initial' => 'pending',
                'states' => [
                    'pending' => [
                        'on' => [
                            'PAY' => 'paid',
                            'CANCEL' => 'cancelled',
                        ],
                    ],
                    'paid' => [
                        'on' => [
                            'SHIP' => 'shipped',
                            'REFUND' => 'cancelled',
                        ],
                    ],
                    'shipped' => [
                        'on' => [
                            'DELIVER' => 'delivered',
                        ],
                    ],
                    'delivered' => [
                        'type' => 'final',
                    ],
                    'cancelled' => [
                        'type' => 'final',
                    ],
                ],
            ],
        );
    }
}
```

Now the workflow is:
- **Explicit** - all states and transitions visible at a glance
- **Self-documenting** - the code IS the documentation
- **Predictable** - invalid transitions are impossible
- **Testable** - test each state and transition independently

## Key Concepts

### States
A state represents a distinct phase in your workflow. An order is either `pending`, `paid`, `shipped`, etc. - never something in between.

### Events
Events trigger transitions between states. When you `send` a `PAY` event to a `pending` order, it transitions to `paid`.

### Transitions
Transitions define how states connect. The arrow from `pending` to `paid` when `PAY` happens is a transition.

### Context
Context is the data that travels with your machine. For an order, this might be the customer info, line items, total amount, etc.

### Behaviors
Behaviors are the actions, guards, and calculations that happen during transitions:
- **Actions** - side effects like sending emails
- **Guards** - conditions that must be true for a transition
- **Calculators** - compute values before guards run

## Event Sourcing Built-In

Every state transition is automatically persisted as an event:

```php
$machine = OrderMachine::create();
$machine->send(['type' => 'PAY', 'amount' => 99.99]);
$machine->send(['type' => 'SHIP', 'tracking' => 'ABC123']);
```

This creates a complete audit trail:

| Event | Payload | State After |
|-------|---------|-------------|
| (initial) | - | pending |
| PAY | {amount: 99.99} | paid |
| SHIP | {tracking: ABC123} | shipped |

You can restore the exact state at any point:

```php
// Get the root event ID
$rootEventId = $machine->state->history->first()->root_event_id;

// Later: restore to the exact state
$restored = OrderMachine::create(state: $rootEventId);
$restored->state->matches('shipped'); // true
```

## Why EventMachine?

| Feature | Traditional Approach | EventMachine |
|---------|---------------------|--------------|
| State visibility | Implicit in code | Explicit configuration |
| Valid transitions | Runtime checks | Compile-time guarantees |
| History | Manual logging | Automatic event sourcing |
| Testing | Mock everything | Test states in isolation |
| Debugging | Print statements | Replay event history |
| Documentation | Separate docs | Code is documentation |

## When to Use EventMachine

EventMachine is ideal for:

- **Business workflows** - orders, subscriptions, approvals
- **Multi-step processes** - onboarding, checkout, wizards
- **Long-running processes** - background jobs with state
- **Audit requirements** - financial, compliance, legal
- **Complex conditional logic** - many if/else branches

EventMachine might be overkill for:

- Simple CRUD operations
- Stateless request/response
- One-time operations without history needs

## Next Steps

Ready to get started?

1. [Install EventMachine](/getting-started/installation)
2. [Build your first machine](/getting-started/your-first-machine)
