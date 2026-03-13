# Data Flow & Context Isolation

Machine delegation uses explicit data flow — no implicit sharing between parent and child contexts.

## Data Flow Overview

```
Parent Context
    │
    ├── 'with' filters/maps ──→ Child Context (initial)
    │                                │
    │                                ├── Child lives its own lifecycle
    │                                │   (entry → events → transitions → actions)
    │                                │
    │                                └── Child reaches final state
    │                                      │
    │                                      ├── ResultBehavior output (if defined)
    │                                      └── Child's final context
    │
    ├── Available in @done event ◄───────┘
    │     {
    │       result:        <ResultBehavior output>,
    │       child_context: <child's final context>,
    │       machine_id:    <child's root_event_id>,
    │       machine_class: <child's FQCN>,
    │     }
    │
    └── @done actions write to parent context
```

## Parent → Child: The `with` Key

The `with` key controls what data the child receives from the parent. Three formats:

<!-- doctest-attr: ignore -->
```php
// Format 1: Same-named keys
'with' => ['order_id', 'total_amount'],
// Child context: { order_id: ..., total_amount: ... }

// Format 2: Key mapping (child_key => parent_key)
'with' => [
    'id'     => 'order_id',        // child sees 'id', parent has 'order_id'
    'amount' => 'total_amount',
],

// Format 3: Closure (dynamic)
'with' => fn (ContextManager $ctx) => [
    'order_id' => $ctx->get('order_id'),
    'amount'   => $ctx->get('total_amount') * 100,
],
```

Without `with`, the child starts with its own default context. No parent data is transferred automatically.

## Child → Parent: The `@done` Event

When the child reaches a final state, `@done` fires with a `ChildMachineDoneEvent` that provides typed accessors:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;

class StorePaymentResultAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, ChildMachineDoneEvent $event): void
    {
        // ResultBehavior output from child (if defined)
        $context->set('payment_id', $event->result('payment_id'));

        // Child's final context values
        $context->set('receipt', $event->childContext('receipt_url'));

        // Child identity
        $childId    = $event->childMachineId();
        $childClass = $event->childMachineClass();
    }
}
```

## Auto-Injected Context Keys

When a child machine is created via delegation, special keys are auto-injected into the child context:

| Key | Value | Purpose |
|-----|-------|---------|
| `_machine_id` | Child's own `root_event_id` | Self-identification (e.g., webhook URLs) |
| `_parent_root_event_id` | Parent's `root_event_id` | Enables `sendToParent()` |

Access via typed methods:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ChildAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $myId     = $context->machineId();        // own root_event_id
        $parentId = $context->parentMachineId();   // parent's root_event_id
    }
}
```

::: info
`_machine_id` is injected for **all** machines — not just children. Every machine can access its own identity via `$context->machineId()`.
:::

## Context Isolation

Child context is **completely isolated** from the parent. There is no automatic merge. This differs from [parallel regions](/advanced/parallel-states), where context IS merged because parallel regions share the same machine instance.

**Why isolated?**
- Child has a different machine definition with different context keys
- Automatic merge could pollute parent context with unexpected keys
- Explicit mapping via `@done` actions is safer and more readable
- Consistent with EventMachine's "data flows through actions" principle

**Example of isolation:**

<!-- doctest-attr: ignore -->
```php
// Parent context: { order_id: 'ORD-1', total: 100 }
// Child context (via with): { order_id: 'ORD-1' }

// Child sets order_id to something else:
$context->set('order_id', 'CHILD-MODIFIED');
// Parent's order_id is STILL 'ORD-1' — completely unaffected
```

## State Isolation

Parent's `State::$value` does **not** include child machine states:

<!-- doctest-attr: ignore -->
```php
// Parent is in 'processing_payment', child is in 'awaiting_charge'
$state->value; // ['order_workflow.processing_payment']
// NOT: ['processing_payment' => ['payment' => 'awaiting_charge']]
```

Child states are the child's business. The parent interacts with the child only through `@done`, `@fail`, and `forward` — never by inspecting its internal state.
