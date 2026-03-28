# Custom Context Classes

Custom context classes provide type safety, validation, and computed methods for your machine's data. They extend `ContextManager` and use Spatie Laravel Data.

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

## Validation Attributes

Use Spatie Laravel Data validation:

<!-- doctest-attr: ignore -->
```php
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Optional;

class OrderContext extends ContextManager
{
    public function __construct(
        #[Required]
        public string $orderId,

        #[Email]
        public string $customerEmail,

        #[ArrayType]
        public array $items = [],

        #[IntegerType]
        #[Min(0)]
        public int|Optional $quantity = 0,

        #[Min(0)]
        #[Max(1000000)]
        public float|Optional $total = 0.0,
    ) {
        parent::__construct();
    }
}
```

## Optional Properties

Use `Optional` for fields that may not be set:

<!-- doctest-attr: ignore -->
```php
use Spatie\LaravelData\Optional;

class UserContext extends ContextManager
{
    public function __construct(
        public string $userId,
        public string|Optional $email = '',
        public ?string $name = null,
        public int|Optional $age = 0,
    ) {
        parent::__construct();

        // Initialize Optional values
        if ($this->email instanceof Optional) {
            $this->email = '';
        }
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
        $ctx->discountPercent = $event->payload['percent'];
        // `total()` will automatically reflect the discount
    },
],
```

### Exposing Computed Values in API Responses

By default, computed methods are only available in PHP — in guards, actions, calculators, and OutputBehavior. They do **not** appear in endpoint JSON responses, because `toArray()` only serializes properties.

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
            'isEmpty'   => $this->isEmpty(),
            'itemCount' => count($this->items),
        ];
    }
}
```

Now the endpoint response includes computed values alongside regular properties:

```json
{
  "output": {
    "items": [...],
    "discountPercent": 10,
    "shippingMethod": "express",
    "subtotal": 99.99,
    "total": 104.98,
    "isEmpty": false,
    "itemCount": 3
  }
}
```

::: info Not Persisted
Computed values are **not** stored in the database — they are recomputed fresh on every API response. This keeps the `machine_events` table clean and avoids stale derived data.
:::

::: tip Output Filtering
Computed keys respect `output` array filtering on endpoints. If an endpoint specifies `output: ['total', 'itemCount']`, only those keys appear — both regular and computed.
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

## Model Transformers

Handle Eloquent models in context:

<!-- doctest-attr: ignore -->
```php
use Spatie\LaravelData\Attributes\WithTransformer;

class OrderContext extends ContextManager
{
    public function __construct(
        #[WithTransformer(ModelTransformer::class)]
        public User|int|Optional $user,

        #[WithTransformer(ModelTransformer::class)]
        public Order|int|Optional $order,

        #[WithTransformer(ModelTransformer::class)]
        public ?Product $product = null,
    ) {
        parent::__construct();
    }
}
```

The transformer handles:
- Storing model IDs for serialization
- Loading models when accessed

## Initialization Logic

Add initialization in the constructor:

```php
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
class GameContext extends ContextManager
{
    public function __construct(
        public int|Optional $score = 0,
        public int|Optional $lives = 3,
        public array $inventory = [],
        public ?string $currentLevel = null,
    ) {
        parent::__construct();

        // Initialize Optional values
        if ($this->score instanceof Optional) {
            $this->score = 0;
        }
        if ($this->lives instanceof Optional) {
            $this->lives = 3;
        }

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
        #[Required]
        public string $sessionId,

        public array $cartItems = [],

        #[Email]
        public string|Optional $customerEmail = '',

        public ?array $shippingAddress = null,

        public ?array $billingAddress = null,

        public ?string $paymentMethod = null,

        public ?string $couponCode = null,

        #[Min(0)]
        public float $discountAmount = 0,

        public ?string $orderId = null,

        public ?string $trackingNumber = null,
    ) {
        parent::__construct();
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
        #[Required]
        public string $applicationId,

        #[Required]
        #[Min(1000)]
        #[Max(1000000)]
        public float $requestedAmount,

        #[Required]
        #[Min(12)]
        #[Max(360)]
        public int $termMonths,

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
        #[Required]
        public string $workflowId,

        #[Required]
        public string $requesterId,

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

### 2. Initialize Optional Values

<!-- doctest-attr: ignore -->
```php
public function __construct(
    public int|Optional $count = 0,
) {
    parent::__construct();

    if ($this->count instanceof Optional) {
        $this->count = 0;
    }
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
