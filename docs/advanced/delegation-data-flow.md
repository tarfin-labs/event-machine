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
    │                                      └── ResultBehavior (if defined)
    │
    ├── Available in @done event ◄───────┘
    │     {
    │       result:        <ResultBehavior output>,
    │       output:        <filtered context or full context>,
    │       machine_id:    <child's root_event_id>,
    │       machine_class: <child's FQCN>,
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
    'output' => ['payment_id', 'status'],  // only these keys are exposed
],
```

### Closure Format

<!-- doctest-attr: ignore -->
```php
'approved' => [
    'type'   => 'final',
    'output' => fn(ContextManager $ctx) => [
        'payment_id' => $ctx->get('payment_id'),
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
        $context->set('payment_id', $event->output('payment_id'));
        $context->set('status', $event->output('status'));

        // ResultBehavior output from child (if defined)
        $context->set('receipt', $event->result('receipt_url'));

        // Child identity
        $childId    = $event->childMachineId();
        $childClass = $event->childMachineClass();
    }
}
```

| Accessor | Return Type | Description |
|----------|-------------|-------------|
| `output(?$key)` | `mixed` | Filtered output (or full context if no `output` key defined) |
| `result(?$key)` | `mixed` | ResultBehavior output (if defined on final state) |
| `childMachineId()` | `string` | Child's `root_event_id` |
| `childMachineClass()` | `string` | Child's FQCN |

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
        $context->set('failed_amount', $event->output('amount'));

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
