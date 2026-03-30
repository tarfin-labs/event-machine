# Typed Contracts

Typed contracts bring type safety to machine delegation boundaries. Instead of passing untyped arrays between parent and child machines, you define typed DTOs that the framework validates and injects automatically.

## Three Contract Types

| Contract | Direction | Base Class |
|----------|-----------|------------|
| **MachineInput** | Parent → Child | `MachineInput` |
| **MachineOutput** | Child → Parent (success) | `MachineOutput` |
| **MachineFailure** | Child → Parent (error) | `MachineFailure` |

## MachineInput

Defines what data a child requires from its parent. Consumed at the delegation boundary — validates, merges into context, then is gone.

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

### Parent-side usage

```php
'delegating' => [
    'machine' => PaymentMachine::class,
    // Auto-resolve: constructor param names match parent context keys
    'input'   => PaymentInput::class,
    // OR closure adapter for name mismatches
    'input'   => function (ContextManager $ctx): PaymentInput {
        return new PaymentInput(
            orderId: $ctx->get('currentOrderId'),
            amount: $ctx->get('totalAmount'),
        );
    },
    // OR untyped array (renamed from 'with')
    'input'   => ['orderId', 'amount'],
],
```

### Child-side declaration

```php
MachineDefinition::define(config: [
    'input'   => PaymentInput::class,
    'initial' => 'processing',
    // ...
]);
```

## MachineOutput

Defines what data a machine produces. Works on any state (not just final). Plugs into v9's output type dispatch as a new case alongside `OutputBehavior`.

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

### Usage on states

```php
'completed' => [
    'type'   => 'final',
    'output' => PaymentOutput::class,  // Auto-resolved from context
],
```

### Type dispatch order

When `output` is a string:
1. Behavior registry key → resolve registered behavior
2. `MachineOutput` subclass → `fromContext()` → typed DTO
3. `OutputBehavior` subclass → container resolve → `__invoke()`
4. Neither → `InvalidOutputDefinitionException`

### Parent receives typed output

```php
'@done' => [
    'target'  => 'shipped',
    'actions' => function (ContextManager $ctx, PaymentOutput $output): void {
        $ctx->set('paymentId', $output->paymentId);  // IDE autocomplete
    },
],
```

### Composition with OutputBehavior

When output needs computation, `OutputBehavior` can return a `MachineOutput`:

```php
class ComputedOutput extends OutputBehavior
{
    public function __invoke(ContextManager $ctx): PaymentOutput
    {
        return new PaymentOutput(
            paymentId: $ctx->get('paymentId'),
            status: $this->computeStatus($ctx),
        );
    }
}
```

## MachineFailure

Maps exceptions to structured error data. Used for both machine and job delegation.

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

### Sensible default

`fromException()` auto-maps `$message` → `getMessage()`, `$code` → `getCode()`. Override for domain-specific mapping:

```php
public static function fromException(Throwable $e): static
{
    return new static(
        errorCode: $e instanceof GatewayException ? $e->gatewayCode : 'UNKNOWN',
        message: $e->getMessage(),
    );
}
```

### Declaration

```php
MachineDefinition::define(config: [
    'failure' => PaymentFailure::class,
    // ...
]);
```

The `failure` key is optional. Without it, `@fail` actions receive raw exception data.

## Discriminated Outputs

Different final states can produce different typed outputs:

```php
'approved' => ['type' => 'final', 'output' => ApprovalOutput::class],
'rejected' => ['type' => 'final', 'output' => RejectionOutput::class],
```

Parent routes per-state:

```php
'@done.approved' => [
    'actions' => function (ContextManager $ctx, ApprovalOutput $output): void { ... },
],
'@done.rejected' => [
    'actions' => function (ContextManager $ctx, RejectionOutput $output): void { ... },
],
```

## Job Delegation

Jobs use the same contracts via interfaces:

```php
class ProcessPaymentJob implements ReturnsOutput, ProvidesFailure
{
    public function output(): PaymentOutput { ... }
    public static function failure(Throwable $e): PaymentFailure { ... }
}
```

Parent DX is identical for machine and job delegation.

## Serialization

Typed contracts travel through queues via `ChildMachineCompletionJob`. The framework serializes via `toArray()` + stores the class FQCN, then reconstructs on the parent side for typed injection.

## Testing

```php
// Machine::fake with typed output
PaymentMachine::fake(output: new PaymentOutput(paymentId: 'pay_1', status: 'ok'));

// simulateChildDone with typed output
$tm->simulateChildDone(
    childClass: PaymentMachine::class,
    output: new PaymentOutput(paymentId: 'pay_1', status: 'ok'),
);
```
