# Typed Inter-Machine Communication Contracts

**Status:** Draft
**Date:** 2026-03-25

---

## Problem

Inter-machine data flow in EventMachine relies on untyped string-key arrays at every boundary:

```php
// Parent → Child: blind array key selection
'with' => ['order_id', 'customer_name'],

// Child → Parent: string keys, no IDE support
'output' => ['result', 'total'],

// Parent @done action: fragile payload access
function (ContextManager $ctx, EventBehavior $event): void {
    $ctx->set('result', $event->payload['output']['result'] ?? null);  // 🔴 No type safety
}
```

**Consequences:**
1. No IDE autocompletion for inter-machine data
2. No compile-time or definition-time validation of data contracts
3. Typos in key names cause silent `null` values, not errors
4. Refactoring a child's context keys silently breaks parent integrations
5. No way to document "what does this machine expect/produce" as code

---

## Vision

Machines should declare typed contracts for their inputs and outputs — like function signatures. A parent invoking a child should know exactly what data to provide and what to expect back, with IDE support and validation.

```php
// Child machine declares its contract
class PaymentMachine extends Machine
{
    public static function input(): PaymentInput { /* ... */ }
    public static function output(): PaymentOutput { /* ... */ }
}

// Parent knows exactly what to send and what to receive
'delegating' => [
    'machine' => PaymentMachine::class,
    'input'   => PaymentInput::from($context),   // Typed, validated
    '@done'   => [
        'target'  => 'completed',
        'actions' => function (ContextManager $ctx, PaymentOutput $output): void {
            $ctx->set('payment_id', $output->paymentId);  // IDE autocomplete ✅
        },
    ],
],
```

---

## Design

### 1. MachineInput — Parent → Child Contract

A typed class that defines what data a child machine requires from its parent.

```php
class PaymentInput extends MachineInput
{
    public function __construct(
        public readonly string $orderId,
        public readonly int $amount,
        public readonly string $currency = 'TRY',
    ) {}
}
```

**Usage in machine config:**

```php
'delegating' => [
    'machine' => PaymentMachine::class,
    'input'   => PaymentInput::class,  // Resolved from parent context automatically
    // OR
    'input'   => function (ContextManager $ctx): PaymentInput {
        return new PaymentInput(
            orderId: $ctx->get('order_id'),
            amount: $ctx->get('total_amount'),
        );
    },
],
```

**Resolution rules:**
- `PaymentInput::class` → auto-resolve: match constructor params to parent context keys (snake_case ↔ camelCase mapping)
- `Closure` → manual mapping, full control
- `['order_id', 'amount']` → legacy array format (current `with` behavior, kept for simple cases)

**Child machine declares its expected input:**

```php
class PaymentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'payment',
                'input'   => PaymentInput::class,   // Declares expected input type
                'initial' => 'processing',
                'context' => [
                    'payment_id' => null,
                    'status'     => 'pending',
                ],
                // ...
            ],
        );
    }
}
```

When the child starts, input is validated against the declared type. Mismatches throw `MachineInputValidationException` at invocation time — not silently producing nulls.

**Input is injected into context:**

```php
// In entry actions, input is available via type-hint:
class ProcessPaymentAction extends ActionBehavior
{
    public function __invoke(ContextManager $ctx, PaymentInput $input): void
    {
        $gateway->charge($input->orderId, $input->amount, $input->currency);
    }
}
```

### 2. MachineOutput — Child → Parent Contract

A typed class that defines what data a child machine produces when it completes.

```php
class PaymentOutput extends MachineOutput
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $status,
        public readonly ?string $transactionRef = null,
    ) {}
}
```

**Usage in child's final state:**

```php
'completed' => [
    'type'   => 'final',
    'output' => function (ContextManager $ctx): PaymentOutput {
        return new PaymentOutput(
            paymentId: $ctx->get('payment_id'),
            status: 'success',
            transactionRef: $ctx->get('ref'),
        );
    },
],
```

**Parent receives typed output in @done action:**

```php
'@done' => [
    'target'  => 'payment_received',
    'actions' => function (ContextManager $ctx, PaymentOutput $output): void {
        $ctx->set('payment_id', $output->paymentId);        // IDE autocomplete ✅
        $ctx->set('transaction_ref', $output->transactionRef);
    },
],
```

The `PaymentOutput` is injected via parameter injection (same as EventBehavior, ContextManager). The framework resolves it from `ChildMachineDoneEvent::output`.

### 3. MachineFailure — Child → Parent Error Contract

```php
class PaymentFailure extends MachineFailure
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $message,
        public readonly ?string $gatewayResponse = null,
    ) {}
}
```

```php
'@fail' => [
    'target'  => 'payment_failed',
    'actions' => function (ContextManager $ctx, PaymentFailure $failure): void {
        $ctx->set('error_code', $failure->errorCode);
        $ctx->set('error_detail', $failure->gatewayResponse);
    },
],
```

### 4. Per-Final-State Output (Discriminated Outputs)

Different final states can produce different output types:

```php
'states' => [
    'approved' => [
        'type'   => 'final',
        'output' => ApprovalOutput::class,    // Has approvalId, approvedBy
    ],
    'rejected' => [
        'type'   => 'final',
        'output' => RejectionOutput::class,   // Has reason, reviewerId
    ],
],
```

Parent uses `@done.{finalState}` routing:

```php
'@done.approved' => [
    'target'  => 'completed',
    'actions' => function (ContextManager $ctx, ApprovalOutput $output): void {
        $ctx->set('approval_id', $output->approvalId);
    },
],
'@done.rejected' => [
    'target'  => 'under_review',
    'actions' => function (ContextManager $ctx, RejectionOutput $output): void {
        $ctx->set('rejection_reason', $output->reason);
    },
],
```

### 5. Machine Contract Declaration (Full Example)

```php
class PaymentMachine extends Machine
{
    // Machine-level contract declaration
    public static function input(): string { return PaymentInput::class; }
    public static function output(): string { return PaymentOutput::class; }
    public static function failure(): string { return PaymentFailure::class; }

    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'payment',
                'input'   => PaymentInput::class,
                'initial' => 'processing',
                'context' => [
                    'payment_id' => null,
                    'status'     => 'pending',
                ],
                'states' => [
                    'processing' => [
                        'entry' => ProcessPaymentAction::class,
                        'on'    => [
                            'PAYMENT_COMPLETED' => 'completed',
                            'PAYMENT_FAILED'    => 'failed',
                        ],
                    ],
                    'completed' => [
                        'type'   => 'final',
                        'output' => function (ContextManager $ctx): PaymentOutput {
                            return new PaymentOutput(
                                paymentId: $ctx->get('payment_id'),
                                status: 'success',
                                transactionRef: $ctx->get('ref'),
                            );
                        },
                    ],
                    'failed' => [
                        'type'    => 'final',
                        'failure' => function (ContextManager $ctx): PaymentFailure {
                            return new PaymentFailure(
                                errorCode: $ctx->get('error_code'),
                                message: $ctx->get('error_message'),
                                gatewayResponse: $ctx->get('raw_response'),
                            );
                        },
                    ],
                ],
            ],
        );
    }
}
```

### 6. XState v5 Alignment

| XState v5 | EventMachine (proposed) | Notes |
|-----------|------------------------|-------|
| `invoke.input` | `'input' => PaymentInput::class` | Closure or class |
| `invoke.output` | `'output' => PaymentOutput::class` | On final state |
| `types.input` | `PaymentInput` class | PHP class = TypeScript type |
| `types.output` | `PaymentOutput` class | PHP class = TypeScript type |
| `actor.provide` | N/A (context injection) | EventMachine uses DI |

**XState export (`machine:xstate`)** should map:
- `PaymentInput` → `{ type: 'PaymentInput', properties: { orderId: 'string', ... } }`
- `PaymentOutput` → `{ type: 'PaymentOutput', properties: { paymentId: 'string', ... } }`

### 7. Validation

**Definition-time validation (`machine:validate-config`):**
- Input class constructor params must be resolvable from parent context keys
- Output class must be constructable from child context keys
- `@done` action with typed output must match child's declared output type

**Runtime validation:**
- Input is validated when child is invoked (ChildMachineJob)
- Output is validated when child reaches final state (ChildMachineCompletionJob)
- Mismatches throw typed exceptions with clear messages:
  ```
  PaymentMachine input validation failed:
    Missing required field 'amount' — parent context has: [order_id, customer_name]
  ```

### 8. Cross-Machine Communication (sendTo/dispatchTo)

For `sendTo`/`dispatchTo`, the typed event system already exists (`EventBehavior`). No changes needed — events ARE the contract for cross-machine communication.

For `dispatchToParent`, the parent should declare what events it accepts from children:

```php
'processing' => [
    'machine'  => PaymentMachine::class,
    'input'    => PaymentInput::class,
    'on'       => [
        ChildProgressEvent::class => [   // Typed event from child
            'target'  => 'processing',
            'actions' => function (ContextManager $ctx, ChildProgressEvent $event): void {
                $ctx->set('progress', $event->progress);
            },
        ],
    ],
    '@done' => 'completed',
],
```

### 9. Forward Endpoint Integration

Forward endpoints should carry input/output types through the HTTP layer:

```php
'delegating' => [
    'machine' => PaymentMachine::class,
    'input'   => PaymentInput::class,
    'forward' => ['PROVIDE_CARD'],
    '@done'   => [
        'target'  => 'completed',
        'actions' => function (ContextManager $ctx, PaymentOutput $output, ForwardContext $fwd): void {
            // Both typed output AND forward context available
            $ctx->set('payment_id', $output->paymentId);
        },
    ],
],
```

### 10. Base Classes

```php
abstract class MachineInput
{
    // Auto-resolve from context: match constructor params to context keys
    public static function fromContext(ContextManager $context): static
    {
        $reflection = new ReflectionClass(static::class);
        $params = [];
        foreach ($reflection->getConstructor()->getParameters() as $param) {
            $key = Str::snake($param->getName());
            $params[$param->getName()] = $context->get($key) ?? $param->getDefaultValue();
        }
        return new static(...$params);
    }

    public function toArray(): array { /* reflection-based */ }
}

abstract class MachineOutput
{
    public static function fromContext(ContextManager $context): static { /* same pattern */ }
    public function toArray(): array { /* reflection-based */ }
}

abstract class MachineFailure
{
    public static function fromException(Throwable $e): static { /* map exception to failure */ }
    public function toArray(): array { /* reflection-based */ }
}
```

---

## Migration Path

1. **Phase 1**: Add `MachineInput`, `MachineOutput`, `MachineFailure` base classes
2. **Phase 2**: Support `input` key alongside `with` (both work, `input` preferred)
3. **Phase 3**: Add parameter injection for typed output in @done/@fail actions
4. **Phase 4**: Add `failure` key on final states (alongside `output`)
5. **Phase 5**: Definition-time validation in `machine:validate-config`
6. **Phase 6**: XState export support for typed contracts
7. **Phase 7**: Deprecate `with` in favor of `input`

---

## Scope

| Component | Change |
|-----------|--------|
| `MachineInput` | New base class |
| `MachineOutput` | New base class |
| `MachineFailure` | New base class |
| `StateDefinition` | Support `input` key, `failure` key on final states |
| `MachineInvokeDefinition` | Accept `MachineInput` class reference |
| `ChildMachineJob` | Validate and inject typed input |
| `ChildMachineCompletionJob` | Resolve typed output/failure |
| `MachineDefinition` | Parameter injection for typed output in @done/@fail actions |
| `InvokableBehavior` | Inject `MachineInput`/`MachineOutput` by type-hint |
| `machine:validate-config` | Input/output type compatibility checks |
| `machine:xstate` | Export typed contracts as JSON schema |

---

## Non-Goals

- Changing how `EventBehavior` works (events are already typed)
- Replacing `ContextManager` (context remains the internal state store)
- Making `sendTo`/`dispatchTo` typed beyond EventBehavior (events ARE the contract)
- Runtime schema evolution or versioning (future concern)
