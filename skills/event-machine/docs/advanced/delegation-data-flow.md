# Data Flow & Context Isolation

Machine delegation uses explicit data flow — no implicit sharing between parent and child contexts.

## Data Flow Overview

```
Parent Context
    │
    ├── 'input' resolves ──→ Child Context (initial)
    │   (MachineInput class,      │
    │    closure, or array)       ├── Child lives its own lifecycle
    │                             │   (entry → events → transitions → actions)
    │                             │
    │                             └── Child reaches final state
    │                                   │
    │                                   ├── 'output' resolves (MachineOutput, OutputBehavior, array)
    │                                   └── Typed or untyped output
    │
    ├── Available in @done event ◄───────┘
    │     {
    │       output:        <MachineOutput DTO, OutputBehavior output, or filtered context>,
    │       output_class:  <MachineOutput FQCN for typed reconstruction>,
    │       machine_id:    <child's root_event_id>,
    │       machine_class: <child's FQCN>,
    │       final_state:   <child's final state key>,
    │     }
    │
    └── @done actions write to parent context
        (typed MachineOutput injected by type-hint)
```

## Parent → Child: The `input` Key

The `input` key controls what data the child receives from the parent. Three formats are supported:

### MachineInput Class (Typed)

<!-- doctest-attr: ignore -->
```php
'delegating' => [
    'machine' => PaymentMachine::class,
    'input'   => PaymentInput::class,  // auto-resolved from parent context
],
```

The framework calls `PaymentInput::fromContext($parentContext)` — constructor param names match camelCase context keys. Missing required params throw `MachineInputValidationException`.

See [Typed Contracts](/advanced/typed-contracts) for MachineInput details.

### Closure Adapter

<!-- doctest-attr: ignore -->
```php
'input' => function (ContextManager $ctx): PaymentInput {
    return new PaymentInput(
        orderId: $ctx->get('currentOrderId'),   // name mapping
        amount: $ctx->get('totalAmount'),
    );
},
```

Use closures when parent context key names don't match child's input param names.

### Array Format (Untyped)

<!-- doctest-attr: ignore -->
```php
'input' => ['orderId', 'amount'],                  // same-name keys
'input' => ['amount' => 'totalAmount'],             // key rename mapping
```

Without `input`, the child starts with its own default context. No parent data is transferred automatically.

### Input Lifecycle

1. **Created** — `ChildMachineJob` (async) or `handleMachineInvoke()` (sync) resolves input
2. **Validated** — against child's declared `input` type (if child config has `'input' => PaymentInput::class`)
3. **Merged into context** — input properties auto-merged into child's initial context
4. **Consumed** — the DTO is gone. Data lives in context from here.

## Child → Parent: The `output` Key

The `output` key on a state controls which context values are exposed to the parent. Supports four formats:

### MachineOutput Class (Typed)

<!-- doctest-attr: ignore -->
```php
'completed' => [
    'type'   => 'final',
    'output' => PaymentOutput::class,  // auto-resolved from child context
],
```

See [Typed Contracts](/advanced/typed-contracts) for MachineOutput details.

### OutputBehavior Class (Computed)

<!-- doctest-attr: ignore -->
```php
'completed' => [
    'type'   => 'final',
    'output' => ComputedPaymentOutput::class,  // OutputBehavior with __invoke()
],
```

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
        'total'     => $ctx->get('amount') + $ctx->get('tax'),
    ],
],
```

When no `output` key is defined, the full child context is returned (default behavior).

## Child → Parent: The `@done` Event

When the child reaches a final state, `@done` fires with a `ChildMachineDoneEvent`. With typed contracts, `MachineOutput` is injected by type-hint:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;

// Typed injection (when child uses MachineOutput)
class StorePaymentResultAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, PaymentOutput $output): void
    {
        $context->set('paymentId', $output->paymentId);      // IDE autocomplete
        $context->set('transactionRef', $output->transactionRef);
    }
}

// Untyped access (when child uses array output)
class StorePaymentResultLegacy extends ActionBehavior
{
    public function __invoke(ContextManager $context, ChildMachineDoneEvent $event): void
    {
        $context->set('paymentId', $event->output('paymentId'));
        $context->set('status', $event->output('status'));
    }
}
```

| Accessor | Return Type | Description |
|----------|-------------|-------------|
| `output(?$key)` | `mixed` | Output data (filtered context, OutputBehavior output, or full context) |
| `typedOutput()` | `?MachineOutput` | Typed MachineOutput instance (null if untyped) |
| `childMachineId()` | `string` | Child's `root_event_id` |
| `childMachineClass()` | `string` | Child's FQCN |
| `finalState()` | `?string` | The child's final state key name |

## Child → Parent: The `@fail` Event

When the child throws an exception, `@fail` fires with a `ChildMachineFailEvent`. With typed contracts, `MachineFailure` is injected:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

// Typed injection (when child declares 'failure' config key)
class HandlePaymentFailureAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, PaymentFailure $failure): void
    {
        $context->set('errorCode', $failure->errorCode);
        $context->set('errorDetail', $failure->gatewayResponse);
    }
}
```

| Accessor | Return Type | Description |
|----------|-------------|-------------|
| `errorMessage()` | `?string` | Error message from exception |
| `errorCode()` | `int\|string\|null` | Error code from exception |
| `typedFailure()` | `?MachineFailure` | Typed MachineFailure instance (null if untyped) |
| `childMachineId()` | `string` | Child's `root_event_id` |
| `childMachineClass()` | `string` | Child's FQCN |
| `output(?$key)` | `mixed` | Child's context at failure time |

## input/output Symmetry

| Direction | Config Key | Formats | Purpose |
|-----------|-----------|---------|---------|
| Parent → Child | `input` | MachineInput class, closure, array | Controls what data child receives |
| Child → Parent | `output` | MachineOutput class, OutputBehavior, array, closure | Controls what data parent receives |
| Child → Parent (error) | `failure` (machine config) | MachineFailure class | Maps exceptions to structured errors |

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

These are stored as separate properties on `ContextManager`, **not** in the `data` array.

## Forward Response Data Flow

Forward events go **directly to the HTTP response**. The parent context is NOT modified.

```
Forward Event (HTTP request)
    ├── Validated by child's EventBehavior
    ├── Routed: parent.send() → tryForwardEventToChild() → child.send()
    ├── Child transitions
    ├── Child output resolved via $machine->output()
    └── Response built
          ├── Default: { id, state, output: <child's output> }
          ├── output (array): filtered child context
          └── output (class): parent's OutputBehavior (child MachineOutput injected)
```

When a forward entry specifies an `output` class, the parent's `OutputBehavior` runs. The child's typed `MachineOutput` is injected by type-hint:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;

class PaymentStepOutput extends OutputBehavior
{
    public function __invoke(ContextManager $context, VerifyingOutput $childOutput): array
    {
        return [
            'orderId'   => $context->get('orderId'),        // Parent context
            'cardLast4' => $childOutput->cardLast4,          // Child typed output
            'step'      => $childOutput->step,
        ];
    }
}
```

## Testing Data Flow

<!-- doctest-attr: ignore -->
```php
PaymentMachine::fake(output: new PaymentOutput(paymentId: 'pay_123', status: 'settled'));

OrderMachine::test()
    ->send('START_PAYMENT')
    ->assertContext('paymentId', 'pay_123');
```

::: tip Full Testing Guide
See [Delegation Testing](/testing/delegation-testing) for more examples.
:::
