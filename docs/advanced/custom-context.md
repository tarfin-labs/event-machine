# Custom Context Classes

Custom context classes provide type safety, validation, and computed methods for your machine's data. They extend `ContextManager`.

## Basic Custom Context

```php
use Tarfinlabs\EventMachine\ContextManager;

class OrderContext extends ContextManager
{
    public function __construct(
        public string $orderId = '',
        public array $items = [],
        public float $total = 0.0,
        public ?string $customerId = null,
    ) {
        parent::__construct();
    }
}
```

## Using Custom Context

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
MachineDefinition::define(
    config: [
        'initial' => 'pending',
        'context' => OrderContext::class,
        'states' => [...],
    ],
);
```

## Type-Safe Access

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
// In behaviors, you get typed context
class ProcessAction extends ActionBehavior
{
    public function __invoke(OrderContext $context): void
    {
        // Full IDE autocompletion
        $orderId = $context->orderId;
        $items = $context->items;

        // Type-safe assignment
        $context->total = 99.99;
    }
}
```

## Validation Rules

Define validation rules via the `rules()` method:

<!-- doctest-attr: ignore -->
```php
class OrderContext extends ContextManager
{
    public function __construct(
        public string $orderId = '',
        public string $customerEmail = '',
        public array $items = [],
        public int $quantity = 0,
        public float $total = 0.0,
    ) {
        parent::__construct();
    }

    public static function rules(): array
    {
        return [
            'orderId'       => ['required', 'string'],
            'customerEmail' => ['required', 'email'],
            'items'         => ['array'],
            'quantity'      => ['integer', 'min:0'],
            'total'         => ['numeric', 'min:0', 'max:1000000'],
        ];
    }
}
```

## Nullable Properties

Use nullable types with defaults for fields that may not be set:

<!-- doctest-attr: ignore -->
```php
class UserContext extends ContextManager
{
    public function __construct(
        public string $userId = '',
        public ?string $email = null,
        public ?string $name = null,
        public ?int $age = null,
    ) {
        parent::__construct();
    }
}
```

## Computed Methods

Add methods for complex calculations:

```php
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
class CartContext extends ContextManager
{
    public function __construct(
        public array $items = [],
        public float $discountPercent = 0,
        public string $shippingMethod = 'standard',
    ) {
        parent::__construct();
    }

    public function subtotal(): float
    {
        return collect($this->items)->sum(
            fn($item) => $item['price'] * $item['quantity']
        );
    }

    public function discount(): float
    {
        return $this->subtotal() * ($this->discountPercent / 100);
    }

    public function shipping(): float
    {
        return match ($this->shippingMethod) {
            'express' => 15.99,
            'overnight' => 29.99,
            default => 5.99,
        };
    }

    public function tax(): float
    {
        return ($this->subtotal() - $this->discount()) * 0.1;
    }

    public function total(): float
    {
        return $this->subtotal() - $this->discount() + $this->tax() + $this->shipping();
    }

    public function itemCount(): int
    {
        return collect($this->items)->sum('quantity');
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }
}
```

### Using Computed Methods

<!-- doctest-attr: ignore -->
```php
// In guards
'guards' => [
    'hasMinimumTotalGuard' => fn(CartContext $ctx) => $ctx->total() >= 10,
    'isNotEmptyGuard' => fn(CartContext $ctx) => !$ctx->isEmpty(),
],

// In actions
'actions' => [
    'applyDiscountAction' => function (CartContext $ctx, EventBehavior $event) {
        $ctx->discountPercent = $event->payload()['percent'];
        // `total()` will automatically reflect the discount
    },
],
```

### Exposing Computed Values in API Responses

By default, computed methods are only available in PHP — in guards, actions, calculators, and ResultBehavior. They do **not** appear in endpoint JSON responses, because `toArray()` only serializes properties.

Override `computedContext()` to declare which computed values should be included in API responses:

<!-- doctest-attr: ignore -->
```php
class CartContext extends ContextManager
{
    public function __construct(
        public array $items = [],
        public float $discountPercent = 0,
        public string $shippingMethod = 'standard',
    ) {
        parent::__construct();
    }

    public function subtotal(): float { /* ... */ }
    public function total(): float { /* ... */ }
    public function isEmpty(): bool { return empty($this->items); }

    protected function computedContext(): array
    {
        return [
            'subtotal'  => $this->subtotal(),
            'total'     => $this->total(),
            'is_empty'  => $this->isEmpty(),
            'item_count' => count($this->items),
        ];
    }
}
```

Now the endpoint response includes computed values alongside regular properties:

```json
{
  "context": {
    "items": [...],
    "discountPercent": 10,
    "shippingMethod": "express",
    "subtotal": 99.99,
    "total": 104.98,
    "is_empty": false,
    "item_count": 3
  }
}
```

::: info Not Persisted
Computed values are **not** stored in the database — they are recomputed fresh on every API response. This keeps the `machine_events` table clean and avoids stale derived data.
:::

::: tip contextKeys Filtering
Computed keys respect `contextKeys` filtering on endpoints. If an endpoint specifies `contextKeys: ['total', 'item_count']`, only those keys appear — both regular and computed.
:::

## Context Interfaces for Shared Behaviors

When a behavior is reused across multiple machines with different context classes, avoid coupling the behavior to specific context types with union types. Instead, define a PHP interface:

<!-- doctest-attr: no_run -->
```php
interface HasFarmer
{
    public function farmer(): Farmer;
}

interface HasTckn
{
    public function tckn(): string;
}
```

Implement the interface in each context class:

<!-- doctest-attr: no_run -->
```php
class CarSalesContext extends ContextManager implements HasFarmer, HasTckn
{
    // ...
    public function farmer(): Farmer { return $this->farmer; }
    public function tckn(): string { return $this->tckn; }
}

class FindeksContext extends ContextManager implements HasFarmer, HasTckn
{
    // ...
    public function farmer(): Farmer { return $this->farmer; }
    public function tckn(): string { return $this->tckn; }
}
```

Now the shared behavior type-hints the interface — no coupling to specific machines:

<!-- doctest-attr: no_run -->
```php
class VerifyIdentityAction extends ActionBehavior
{
    public function __invoke(HasTckn $context): void
    {
        $tckn = $context->tckn(); // IDE autocompletion, static analysis ✓
    }
}
```

This scales cleanly: adding a new machine that uses `VerifyIdentityAction` only requires the new context to implement `HasTckn`. The behavior class never changes.

::: tip When to use interfaces vs union types
**Interfaces** — when the behavior only needs a few shared properties and is reused across 2+ machines. Scales indefinitely.

**Union types** (`CarSalesContext|FindeksContext`) — when the behavior needs access to machine-specific properties that differ between contexts. Acceptable for 2-3 types.
:::

## Model Casting

Eloquent models in context are auto-detected and cast automatically (stored as IDs, loaded when accessed):

<!-- doctest-attr: ignore -->
```php
class OrderContext extends ContextManager
{
    public function __construct(
        public ?User $user = null,
        public ?Order $order = null,
        public ?Product $product = null,
    ) {
        parent::__construct();
    }
}
```

Model, Enum, and DateTime types are auto-detected. For custom value objects, use `typeCasts()`, `casts()`, or register them in `config/machine.php`. See [Cast Resolution](#cast-resolution) below.

## Cast Resolution

Both `ContextManager` and `EventBehavior` extend `TypedData`, which provides a 4-layer cast resolution system for serializing and deserializing property values:

| Layer | Method | Scope | Use Case |
|-------|--------|-------|----------|
| 1 | `casts()` | Per-property | Override cast for a specific property name |
| 2 | `typeCasts()` | Per-type, class-level | Override cast for all properties of a given type in this class |
| 3 | `config/machine.php` `casts` | Per-type, app-wide | Register a cast once for the entire application |
| 4 | Auto-detect | Per-type, built-in | Model, BackedEnum, DateTimeInterface, Arrayable |

### Layer 1: `casts()` — Per-Property

Override how a specific property is serialized:

<!-- doctest-attr: ignore -->
```php
class OrderContext extends ContextManager
{
    public function __construct(
        public ?Collection $orderItems = null,
    ) {}

    public static function casts(): array
    {
        return [
            'orderItems' => [OrderItemData::class],  // Collection<OrderItemData>
        ];
    }
}
```

### Layer 2: `typeCasts()` — Per-Type, Class-Level

Apply a cast to all properties of a given type within this class:

<!-- doctest-attr: ignore -->
```php
class FinanceContext extends ContextManager
{
    public function __construct(
        public ?Money $totalPrice = null,
        public ?Money $taxAmount = null,
    ) {}

    public static function typeCasts(): array
    {
        return [
            Money::class => MoneyCast::class,
        ];
    }
}
```

### Layer 3: `config/machine.php` — App-Wide

Register casts globally so every `ContextManager` and `EventBehavior` subclass can use them:

```php
// config/machine.php
return [
    'casts' => [
        \Brick\Money\Money::class => \App\Machines\Casts\MoneyCast::class,
    ],
    // ...
];
```

### Layer 4: Auto-Detect

The following types are automatically detected and cast without any configuration:

- **Eloquent Model** — stored as ID, loaded when accessed
- **BackedEnum** — stored as value, cast back to enum
- **DateTimeInterface** — stored as ISO 8601 string
- **Arrayable** — stored via `toArray()`, reconstructed via constructor

## Initialization Logic

Add initialization in the constructor:

```php
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
class GameContext extends ContextManager
{
    public function __construct(
        public int $score = 0,
        public int $lives = 3,
        public array $inventory = [],
        public ?string $currentLevel = null,
    ) {
        parent::__construct();

        // Set default level
        if ($this->currentLevel === null) {
            $this->currentLevel = 'level_1';
        }
    }
}
```

## Self-Validation

<!-- doctest-attr: ignore -->
```php
$context = new OrderContext(
    orderId: 'order-123',
    customerEmail: 'invalid-email',  // Invalid
);

$context->selfValidate(); // Throws MachineContextValidationException
```

## Validation and Creation

<!-- doctest-attr: ignore -->
```php
// Create with validation
$context = OrderContext::validateAndCreate([
    'orderId' => 'order-123',
    'customerEmail' => 'customer@example.com',
    'items' => [],
]);

// This will throw if validation fails
$context = OrderContext::validateAndCreate([
    'customerEmail' => 'invalid',
]);
```

## Practical Examples

### E-commerce Order Context

```php
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
class EcommerceContext extends ContextManager
{
    public function __construct(
        public string $sessionId = '',
        public array $cartItems = [],
        public ?string $customerEmail = null,
        public ?array $shippingAddress = null,
        public ?array $billingAddress = null,
        public ?string $paymentMethod = null,
        public ?string $couponCode = null,
        public float $discountAmount = 0,
        public ?string $orderId = null,
        public ?string $trackingNumber = null,
    ) {
        parent::__construct();
    }

    public static function rules(): array
    {
        return [
            'sessionId'      => ['required', 'string'],
            'customerEmail'  => ['nullable', 'email'],
            'discountAmount' => ['numeric', 'min:0'],
        ];
    }

    public function subtotal(): float
    {
        return collect($this->cartItems)->sum(
            fn($item) => $item['price'] * $item['quantity']
        );
    }

    public function hasItems(): bool
    {
        return !empty($this->cartItems);
    }

    public function hasShippingAddress(): bool
    {
        return $this->shippingAddress !== null;
    }

    public function hasPaymentMethod(): bool
    {
        return $this->paymentMethod !== null;
    }

    public function isReadyForCheckout(): bool
    {
        return $this->hasItems()
            && $this->hasShippingAddress()
            && $this->hasPaymentMethod();
    }
}
```

### Loan Application Context

```php
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
class LoanApplicationContext extends ContextManager
{
    public function __construct(
        public string $applicationId = '',
        public float $requestedAmount = 0,
        public int $termMonths = 12,
        public ?float $approvedAmount = null,
        public ?float $interestRate = null,
        public ?int $creditScore = null,
        public float $debtToIncomeRatio = 0,
        public array $documents = [],
        public array $approvals = [],
        public ?string $rejectionReason = null,
    ) {
        parent::__construct();
    }

    public static function rules(): array
    {
        return [
            'applicationId'  => ['required', 'string'],
            'requestedAmount' => ['required', 'numeric', 'min:1000', 'max:1000000'],
            'termMonths'     => ['required', 'integer', 'min:12', 'max:360'],
        ];
    }

    public function monthlyPayment(): ?float
    {
        if (!$this->approvedAmount || !$this->interestRate) {
            return null;
        }

        $principal = $this->approvedAmount;
        $rate = $this->interestRate / 12 / 100;
        $months = $this->termMonths;

        return $principal * ($rate * pow(1 + $rate, $months))
            / (pow(1 + $rate, $months) - 1);
    }

    public function hasAllDocuments(): bool
    {
        $required = ['id', 'income_proof', 'bank_statements'];
        return empty(array_diff($required, array_keys($this->documents)));
    }

    public function approvalCount(): int
    {
        return count($this->approvals);
    }

    public function needsMoreApprovals(int $required): bool
    {
        return $this->approvalCount() < $required;
    }
}
```

### Workflow Context

```php
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
class WorkflowContext extends ContextManager
{
    public function __construct(
        public string $workflowId = '',
        public string $requesterId = '',

        public string $currentStep = 'start',

        public array $completedSteps = [],

        public array $formData = [],

        public array $approvers = [],

        public array $comments = [],

        public ?string $finalDecision = null,

        public ?\DateTimeImmutable $submittedAt = null,

        public ?\DateTimeImmutable $completedAt = null,
    ) {
        parent::__construct();
    }

    public function hasCompletedStep(string $step): bool
    {
        return in_array($step, $this->completedSteps);
    }

    public function addComment(string $author, string $text): void
    {
        $this->comments[] = [
            'author' => $author,
            'text' => $text,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function duration(): ?string
    {
        if (!$this->submittedAt || !$this->completedAt) {
            return null;
        }

        return $this->submittedAt->diff($this->completedAt)->format('%d days, %h hours');
    }
}
```

## Best Practices

### 1. Use Appropriate Types

<!-- doctest-attr: ignore -->
```php
// Good - specific types
public string $orderId;
public float $total;
public array $items;

// Avoid - mixed types
public mixed $data;
```

### 2. Use Nullable with Defaults

<!-- doctest-attr: ignore -->
```php
public function __construct(
    public ?int $count = 0,
    public ?string $label = null,
) {
    parent::__construct();
}
```

### 3. Add Computed Methods

<!-- doctest-attr: ignore -->
```php
// Good - encapsulated logic
public function isEligible(): bool
{
    return $this->creditScore >= 650 && $this->debtRatio < 0.4;
}

// Use in guards
'guards' => [
    'checkEligibilityGuard' => fn($ctx) => $ctx->isEligible(),
],
```

### 4. Validate at Boundaries

<!-- doctest-attr: ignore -->
```php
// Validate on creation
$context = OrderContext::validateAndCreate($input);

// Or self-validate
$context->selfValidate();
```

::: tip Detailed Guide
For comprehensive design guidelines with Do/Don't examples, see [Context Design](/best-practices/context-design).
:::

::: tip Testing
For testing custom context classes, see [Testing Overview](/testing/overview).
:::
