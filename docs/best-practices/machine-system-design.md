# Machine System Design

A multi-machine system is not a collection of independent machines -- it is an organized structure where machines communicate through well-defined interfaces. The parent orchestrates, children report status through their states, and the hierarchy keeps the system comprehensible.

[Machine Decomposition](./machine-decomposition) covers _when_ to split a machine. This page covers how to _organize_ the resulting system: communication rules, hierarchy, and timer placement.

::: tip Built-In Handshaking
EventMachine's delegation pattern implements the handshaking rule automatically: the parent's delegation state (e.g., `processing_payment`) acts as the "busy" state, and `@done`/`@fail` acts as the "done" acknowledgment. You don't need to design this manually -- the framework guarantees it.
:::

## The Command--State Interface

Information flows one way in a well-designed machine system: **commands down, states up.** The parent sends commands to the child (via the `machine` key or `sendTo`). The parent reads the child's outcome (via `@done`, `@done.{state}`, or `sendToParent`). The child never reads the parent's state.

### Anti-Pattern: Leaking Parent State to Child

When a parent passes its own status flags to a child via `with`, the child becomes coupled to the parent's lifecycle:

```php ignore
// Anti-pattern: parent passes its own state to child
'awaiting_payment' => [
    'machine' => PaymentMachine::class,
    'with'    => ['order_id', 'order_total', 'order_status'],  // ← parent state leaked
    '@done'   => 'shipping',
    '@fail'   => 'failed',
],
```

```php no_run
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\ContextManager;

// Anti-pattern: child reads parent state to decide behavior
class CapturePaymentAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // Child uses parent's order_status — tight coupling
        if ($context->get('order_status') === 'cancelled') {
            return;
        }

        // ... capture logic ...
    }
}
```

The child should not care whether the order is cancelled. That is the parent's concern.

### Fix: Parent Handles Its Own Concerns

Pass only payment-relevant data via `with`. Handle cancellation in the parent with an `on` transition:

```php ignore
// Parent handles cancellation — child only knows about payment
'awaiting_payment' => [
    'machine' => PaymentMachine::class,
    'with'    => ['order_id', 'order_total'],  // only payment-relevant data
    'on'      => ['ORDER_CANCELLED' => 'cancelled'],
    '@done'   => 'shipped',
    '@fail'   => 'failed',
],
```

If the parent receives `ORDER_CANCELLED` while the child is running, the parent transitions to `cancelled` directly. The child is cleaned up automatically. No coupling needed.

**Takeaway:** Pass only IDs and values the child needs via `with` -- never parent state or status flags. Commands flow down (`machine`, `forward`, `sendTo`), states flow up (`@done`, `@done.{state}`, `sendToParent`).
