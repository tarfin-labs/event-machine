# Behaviors API

Reference for all behavior classes.

## InvokableBehavior (Base)

Abstract base class for all behaviors.

```php
namespace Tarfinlabs\EventMachine\Behavior;

abstract class InvokableBehavior
```

### Properties

| Property | Type | Description |
|----------|------|-------------|
| `$requiredContext` | `array<string>` | Required context keys with types |
| `$shouldLog` | `bool` | Enable console logging |
| `$eventQueue` | `?Collection` | Queue for raised events |

### Methods

#### raise()

Queue an event to be processed.

```php
public function raise(EventBehavior|array $eventBehavior): void
```

#### hasMissingContext()

Check for missing required context.

```php
public static function hasMissingContext(ContextManager $context): ?string
```

#### validateRequiredContext()

Validate all required context is present.

```php
public static function validateRequiredContext(ContextManager $context): void
```

**Throws:** `MissingMachineContextException`

#### getType()

Get behavior type name.

```php
public static function getType(): string
```

#### injectInvokableBehaviorParameters()

Inject parameters for invocation.

```php
public static function injectInvokableBehaviorParameters(
    callable $actionBehavior,
    State $state,
    ?EventBehavior $eventBehavior = null,
    ?array $actionArguments = null
): array
```

#### run()

Execute behavior directly.

```php
public static function run(mixed ...$args): mixed
```

---

## ActionBehavior

For state machine actions (side effects).

```php
namespace Tarfinlabs\EventMachine\Behavior;

class ActionBehavior extends InvokableBehavior
```

### Usage

```php
class SendEmailAction extends ActionBehavior
{
    public static array $requiredContext = [
        'email' => 'string',
    ];

    public function __invoke(ContextManager $context): void
    {
        Mail::to($context->email)->send(new OrderConfirmation());
    }
}
```

### With Dependency Injection

```php
class ProcessPaymentAction extends ActionBehavior
{
    public function __construct(
        private PaymentGateway $gateway
    ) {
        parent::__construct();
    }

    public function __invoke(ContextManager $context): void
    {
        $this->gateway->charge($context->amount);
    }
}
```

### With raise()

```php
class CompleteOrderAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->completedAt = now();

        $this->raise(['type' => 'ORDER_COMPLETED']);
    }
}
```

---

## GuardBehavior

For transition conditions.

```php
namespace Tarfinlabs\EventMachine\Behavior;

class GuardBehavior extends InvokableBehavior
```

### Usage

```php
class HasItemsGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return count($context->items) > 0;
    }
}
```

### With Event Data

```php
class IsAuthorizedGuard extends GuardBehavior
{
    public function __invoke(
        ContextManager $context,
        EventBehavior $event
    ): bool {
        return $event->payload['userId'] === $context->ownerId;
    }
}
```

---

## ValidationGuardBehavior

Guard with validation error messages.

```php
namespace Tarfinlabs\EventMachine\Behavior;

class ValidationGuardBehavior extends GuardBehavior
```

### Properties

| Property | Type | Description |
|----------|------|-------------|
| `$errorMessage` | `?string` | Error message on failure |

### Usage

```php
class ValidAmountGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = 'Amount must be positive';

    public function __invoke(ContextManager $context): bool
    {
        return $context->amount > 0;
    }
}
```

When guard fails, throws `MachineValidationException` with the error message.

---

## CalculatorBehavior

For context modifications before guards.

```php
namespace Tarfinlabs\EventMachine\Behavior;

class CalculatorBehavior extends InvokableBehavior
```

### Usage

```php
class CalculateTotalCalculator extends CalculatorBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->total = collect($context->items)
            ->sum(fn($item) => $item['price'] * $item['quantity']);
    }
}
```

### With Dependencies

```php
class ApplyTaxCalculator extends CalculatorBehavior
{
    public function __construct(
        private TaxService $taxService
    ) {
        parent::__construct();
    }

    public function __invoke(ContextManager $context): void
    {
        $context->tax = $this->taxService->calculate($context->total);
    }
}
```

---

## EventBehavior

For event definitions and validation.

```php
namespace Tarfinlabs\EventMachine\Behavior;

class EventBehavior extends \Spatie\LaravelData\Data
```

### Properties

| Property | Type | Description |
|----------|------|-------------|
| `$type` | `string` | Event type identifier |
| `$payload` | `?array` | Event payload data |
| `$version` | `?int` | Event version |
| `$source` | `SourceType` | Event source (EXTERNAL/INTERNAL) |

### Methods

#### getType()

Get the event type.

```php
public static function getType(): string
```

#### validatePayload()

Define payload validation rules.

```php
public function validatePayload(): ?array
```

#### getScenario()

Get scenario identifier.

```php
public function getScenario(): ?string
```

#### actor()

Get actor identifier from context.

```php
public function actor(ContextManager $context): ?string
```

#### isTransactional()

Whether event runs in database transaction.

```php
public function isTransactional(): bool
```

### Usage

```php
class SubmitOrderEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'SUBMIT_ORDER';
    }

    public function validatePayload(): ?array
    {
        return [
            'express' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'string', 'max:500'],
        ];
    }
}
```

### With Scenario

```php
class ProcessEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'PROCESS';
    }

    public function getScenario(): ?string
    {
        return $this->payload['scenario'] ?? null;
    }
}
```

---

## ResultBehavior

For final state results.

```php
namespace Tarfinlabs\EventMachine\Behavior;

class ResultBehavior extends InvokableBehavior
```

### Usage

```php
class OrderCompletedResult extends ResultBehavior
{
    public function __invoke(
        ContextManager $context,
        ?EventBehavior $event = null
    ): array {
        return [
            'orderId' => $context->orderId,
            'total' => $context->total,
            'completedAt' => $context->completedAt,
        ];
    }
}
```

### Registration

```php
MachineDefinition::define(
    config: [
        'states' => [
            'completed' => [
                'type' => 'final',
                'result' => OrderCompletedResult::class,
            ],
        ],
    ],
);
```

### Getting Result

```php
$machine = OrderMachine::create();
$machine->send(['type' => 'COMPLETE']);

$result = $machine->result();
// ['orderId' => '...', 'total' => 100, ...]
```

---

## Fakeable Trait

All behaviors include the Fakeable trait for testing.

### Methods

#### fake()

Create a mock instance.

```php
public static function fake(): Mockery\MockInterface
```

#### isFaked()

Check if behavior is faked.

```php
public static function isFaked(): bool
```

#### getFake()

Get the mock instance.

```php
public static function getFake(): ?Mockery\MockInterface
```

#### shouldRun()

Set mock expectations.

```php
public static function shouldRun(): Mockery\Expectation
```

#### assertRan()

Assert behavior was invoked.

```php
public static function assertRan(): void
```

#### assertNotRan()

Assert behavior was not invoked.

```php
public static function assertNotRan(): void
```

#### resetFakes()

Clear mock state.

```php
public static function resetFakes(): void
```

### Testing Example

```php
ProcessOrderAction::fake();

ProcessOrderAction::shouldRun()
    ->once()
    ->andReturnUsing(function ($context) {
        $context->processed = true;
    });

$machine = OrderMachine::create();
$machine->send(['type' => 'PROCESS']);

ProcessOrderAction::assertRan();
```

## Related

- [Actions](/behaviors/actions) - Action patterns
- [Guards](/behaviors/guards) - Guard patterns
- [Calculators](/behaviors/calculators) - Calculator patterns
- [Events](/behaviors/events) - Event patterns
- [Results](/behaviors/results) - Result patterns
