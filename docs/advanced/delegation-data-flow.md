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
    │                                      ├── 'output' filters (if defined)
    │                                      └── OutputBehavior (if defined)
    │
    ├── Available in @done event ◄───────┘
    │     {
    │       output:        <OutputBehavior output or filtered context>,
    │       machine_id:    <child's root_event_id>,
    │       machine_class: <child's FQCN>,
    │       final_state:   <child's final state key>,
    │     }
    │
    └── @done actions write to parent context
```

## Parent → Child: The `with` Key

The `with` key controls what data the child receives from the parent. Three formats are supported: same-named keys, key mapping, and closures.

See [Machine Delegation — `with` key](/advanced/machine-delegation#with-context-transfer) for format examples.

Without `with`, the child starts with its own default context. No parent data is transferred automatically.

## Child → Parent: The `output` Key

The `output` key on a final state controls which context values are exposed to the parent. This creates a symmetric data flow: `with` controls input, `output` controls output.

### Array Format

<!-- doctest-attr: ignore -->
```php
'approved' => [
    'type'   => 'final',
    'output' => ['paymentId', 'status'],  // only these keys are exposed
],
```

### Closure Format

<!-- doctest-attr: ignore -->
```php
'approved' => [
    'type'   => 'final',
    'output' => fn(ContextManager $ctx) => [
        'paymentId' => $ctx->get('paymentId'),
        'total'      => $ctx->get('amount') + $ctx->get('tax'),
    ],
],
```

### No Output Key

When no `output` key is defined, the full child context is returned (default behavior).

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
        // Filtered output (respects child's output key)
        $context->set('paymentId', $event->output('paymentId'));
        $context->set('status', $event->output('status'));

        // OutputBehavior output from child (if defined)
        $context->set('receipt', $event->output('receipt_url'));

        // Child identity
        $childId    = $event->childMachineId();
        $childClass = $event->childMachineClass();

        // Which final state the child reached (for @done.{state} routing)
        $finalState = $event->finalState(); // 'approved', 'rejected', etc.
    }
}
```

| Accessor | Return Type | Description |
|----------|-------------|-------------|
| `output(?$key)` | `mixed` | Output data (filtered context, OutputBehavior output, or full context if no `output` key defined) |
| `childMachineId()` | `string` | Child's `root_event_id` |
| `childMachineClass()` | `string` | Child's FQCN |
| `finalState()` | `?string` | The child's final state key name (e.g., `'approved'`). Used for `@done.{state}` routing. |

## Child → Parent: The `@fail` Event

When the child machine throws an exception or reaches a failure state, `@fail` fires with a `ChildMachineFailEvent`:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\ChildMachineFailEvent;

class HandlePaymentFailureAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, ChildMachineFailEvent $event): void
    {
        // Error message from the child failure
        $context->set('error', $event->errorMessage());

        // Child's context at the time of failure
        $context->set('failedAmount', $event->output('amount'));

        // Child identity
        $childId    = $event->childMachineId();
        $childClass = $event->childMachineClass();
    }
}
```

| Accessor | Return Type | Description |
|----------|-------------|-------------|
| `errorMessage()` | `?string` | Error message from exception or manual failure |
| `childMachineId()` | `string` | Child's `root_event_id` |
| `childMachineClass()` | `string` | Child's FQCN |
| `output(?$key)` | `mixed` | Child's context at failure time (full array or single key) |

## with/output Symmetry

| Direction | Config Key | Formats | Purpose |
|-----------|-----------|---------|---------|
| Parent → Child | `with` | array, rename map, closure | Controls what data child receives |
| Child → Parent | `output` | array, closure | Controls what data parent receives |

## Auto-Injected Context Keys

When a child machine is created via delegation, special keys are auto-injected into the child context:

| Key | Value | Purpose |
|-----|-------|---------|
| `_machine_id` | Child's own `root_event_id` | Self-identification (e.g., webhook URLs) |
| `_parent_root_event_id` | Parent's `root_event_id` | Enables `sendToParent()` |

Access via typed methods:

<!-- doctest-attr: no_run -->
```php
$context->machineId();           // child's own root_event_id
$context->parentMachineId();     // parent's root_event_id
$context->parentMachineClass();  // parent's FQCN
```

These are stored as separate properties on `ContextManager`, **not** in the `data` array. They don't appear in context diffs or persist beyond the current lifecycle.

## Forward Response Data Flow

Unlike the `@done` data flow (which modifies parent context), forward responses go **directly to the HTTP response**. The parent context is NOT modified by a forward event.

```
Forward Event (HTTP request)
    ├── Validated by child's EventBehavior
    ├── Routed: parent.send() → tryForwardEventToChild() → child.send()
    ├── Child transitions
    ├── Child State returned to parent
    └── Response built from parent + child State
          ├── Default: { id, state, child: { state, output } }
          ├── output (array): filtered child context
          └── output (class): parent's OutputBehavior (ForwardContext injection)
```

The default forward response shape:

```json
{
  "data": {
    "id": "evt_abc123",
    "state": ["processing_payment"],
    "child": {
      "state": ["awaiting_confirmation"],
      "output": {
        "cardLast4": "1111",
        "status": "card_provided"
      }
    }
  }
}
```

When `output` is configured as an array on the forward entry, only the specified keys from the child's context appear in the response. Without `output`, the full child context is returned.

### Parent OutputBehavior with ForwardContext

When a forward entry specifies an `output` class, the parent's `OutputBehavior` runs instead of the default response. The `ForwardContext` value object is injected, providing type-safe access to the child's `ContextManager` and `State`:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Routing\ForwardContext;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

class PaymentStepOutput extends OutputBehavior
{
    public function __invoke(ContextManager $context, ForwardContext $forwardContext): array
    {
        return [
            'orderId'    => $context->get('orderId'),                             // Parent context
            'cardLast4'  => $forwardContext->childContext->get('cardLast4'),    // Child context
            'child_step' => $forwardContext->childState->value[0] ?? null,     // Child state
        ];
    }
}
```

`ForwardContext` is only available in forward endpoint context -- it is not injected in regular endpoints or `@done` actions.

| Property | Type | Description |
|----------|------|-------------|
| `childContext` | `ContextManager` | The child machine's context after processing the forwarded event |
| `childState` | `State` | The child machine's full state (value, context, history) |

### `available_events` in Response

The `State::availableEvents()` method reflects both the parent's own `on` events and the forward events that the child's current state accepts:

```json
{
  "availableEvents": [
    { "type": "CANCEL", "source": "parent" },
    { "type": "PROVIDE_CARD", "source": "forward" },
    { "type": "CONFIRM_PAYMENT", "source": "forward" }
  ]
}
```

Forward events only appear when the child's **current state** has a matching transition. After the child transitions (e.g., from `awaiting_card` to `awaiting_confirmation`), the available forward events update accordingly -- `PROVIDE_CARD` would disappear and only `CONFIRM_PAYMENT` would remain.

Regular (non-forwarded) endpoints include `availableEvents` in the response by default. Forward endpoints do not include `availableEvents` in their default response shape. To get `availableEvents` from a forward endpoint, use a custom `OutputBehavior` with `ForwardContext` injection and call `$forwardContext->childState->availableEvents()`.

## Testing Data Flow

<!-- doctest-attr: ignore -->
```php
PaymentMachine::fake(result: ['paymentId' => 'pay_123', 'status' => 'settled']);

OrderMachine::test()
    ->send('START_PAYMENT')
    ->assertContext('paymentId', 'pay_123');

Machine::resetMachineFakes();
```

::: tip Full Testing Guide
See [Delegation Testing](/testing/delegation-testing) for more examples.
:::

::: tip Forward vs @done
Unlike `@done` data flow, forward responses go directly to the HTTP caller. The parent context is **not** modified -- the child state is included in the response for the caller's benefit, but the parent machine remains in its delegating state.
:::
