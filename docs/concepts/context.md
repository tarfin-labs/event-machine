# Context Management

Context is the data that travels with your state machine through all state transitions. It's where you store the information that your machine needs to make decisions and perform actions.

## Understanding Context

Think of context as the "memory" of your state machine. While states represent *where* you are, context represents *what you know*.

```php
// State tells you where you are
$machine->state->value; // 'processing'

// Context tells you what you know
$machine->state->context; // ['orderId' => 123, 'total' => 99.99, 'customerEmail' => '...']
```

## Context Types

### 1. Array Context (Simple)

For simple use cases, use a plain array:

```php
MachineDefinition::define(
    config: [
        'initial' => 'idle',
        'context' => [
            'count' => 0,
            'lastUpdated' => null,
            'isEnabled' => true
        ],
        // ... states
    ]
);
```

Access context values:

```php
$machine = CounterMachine::create();
echo $machine->state->context['count']; // 0

// In actions
'actions' => [
    'increment' => function (ContextManager $context): void {
        $context->count++;
        $context->lastUpdated = now();
    }
]
```

### 2. Context Classes (Recommended)

For complex applications, create dedicated context classes:

```php
<?php

use Tarfinlabs\EventMachine\ContextManager;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Email;

class OrderContext extends ContextManager
{
    public function __construct(
        public string|Optional $orderId,
        public int|Optional $customerId,
        #[Email]
        public string|Optional $customerEmail,
        #[Min(0)]
        public float|Optional $total,
        public array|Optional $items,
        public string|Optional $status,
    ) {
        parent::__construct();
        
        // Set defaults for Optional values
        if ($this->orderId instanceof Optional) {
            $this->orderId = Str::uuid();
        }
        if ($this->total instanceof Optional) {
            $this->total = 0.0;
        }
        if ($this->items instanceof Optional) {
            $this->items = [];
        }
        if ($this->status instanceof Optional) {
            $this->status = 'pending';
        }
    }

    // Computed properties
    public function totalItems(): int
    {
        return array_sum(array_column($this->items, 'quantity'));
    }

    public function hasItems(): bool
    {
        return count($this->items) > 0;
    }

    public function isPremiumOrder(): bool
    {
        return $this->total >= 1000;
    }
}
```

Register the context class:

```php
MachineDefinition::define(
    config: [
        'initial' => 'cart',
        'context' => OrderContext::class,
        // ... states
    ]
);
```

## Working with Context

### Creating Machines with Context

```php
// With initial context data
$machine = OrderMachine::create([
    'customerId' => 123,
    'customerEmail' => 'customer@example.com'
]);

// Context class constructor handles defaults
echo $machine->state->context->orderId; // Auto-generated UUID
echo $machine->state->context->total;   // 0.0
```

### Modifying Context in Actions

```php
class AddItemAction extends ActionBehavior
{
    public function __invoke(OrderContext $context, EventDefinition $event): void
    {
        $item = [
            'sku' => $event->payload['sku'],
            'price' => $event->payload['price'],
            'quantity' => $event->payload['quantity']
        ];
        
        $context->items[] = $item;
        $context->total += $item['price'] * $item['quantity'];
    }
}
```

### Reading Context in Guards

```php
class HasMinimumOrderValueGuard extends GuardBehavior
{
    public function __invoke(OrderContext $context): bool
    {
        return $context->total >= 25.00;
    }
}
```

## Context Validation

### Automatic Validation

Context classes leverage Spatie Laravel Data for automatic validation:

```php
class UserRegistrationContext extends ContextManager
{
    public function __construct(
        #[Required]
        #[Email]
        public string|Optional $email,
        
        #[Required]
        #[Min(8)]
        public string|Optional $password,
        
        #[Required]
        #[Alpha]
        public string|Optional $firstName,
        
        #[Required]
        #[Alpha]
        public string|Optional $lastName,
        
        #[IntegerType]
        #[Min(18)]
        #[Max(120)]
        public int|Optional $age,
    ) {
        parent::__construct();
    }
}
```

Validation happens automatically when context is created or modified:

```php
try {
    $machine = UserRegistrationMachine::create([
        'email' => 'invalid-email',  // Will fail validation
        'password' => '123',         // Too short
        'age' => 15                  // Too young
    ]);
} catch (MachineContextValidationException $e) {
    // Handle validation errors
    dd($e->errors());
}
```

### Custom Validation Rules

Add custom validation logic:

```php
class PaymentContext extends ContextManager
{
    public function __construct(
        public string|Optional $cardNumber,
        public string|Optional $expiryDate,
        public string|Optional $cvv,
        public float|Optional $amount,
    ) {
        parent::__construct();
    }

    public static function rules(): array
    {
        return [
            'cardNumber' => ['required', 'regex:/^\d{16}$/'],
            'expiryDate' => ['required', 'date_format:m/y', 'after:today'],
            'cvv' => ['required', 'regex:/^\d{3,4}$/'],
            'amount' => ['required', 'numeric', 'min:0.01']
        ];
    }

    public static function messages(): array
    {
        return [
            'cardNumber.regex' => 'Card number must be 16 digits',
            'expiryDate.after' => 'Card has expired',
            'cvv.regex' => 'CVV must be 3 or 4 digits'
        ];
    }
}
```

## Advanced Context Patterns

### Context Transformers

Transform data when storing or retrieving context:

```php
use Spatie\LaravelData\Attributes\WithTransformer;
use Tarfinlabs\EventMachine\Transformers\ModelTransformer;

class OrderContext extends ContextManager
{
    public function __construct(
        #[WithTransformer(ModelTransformer::class)]
        public User|int|Optional $customer,
        
        #[WithTransformer(MoneyTransformer::class)]
        public Money|float|Optional $total,
    ) {
        parent::__construct();
    }
}
```

### Nested Context

Organize complex context with nested structures:

```php
class EcommerceContext extends ContextManager
{
    public function __construct(
        public CustomerInfo|Optional $customer,
        public ShippingInfo|Optional $shipping,
        public PaymentInfo|Optional $payment,
        public array|Optional $items,
    ) {
        parent::__construct();
    }
}

class CustomerInfo extends Data
{
    public function __construct(
        public int $id,
        public string $email,
        public string $name,
        public bool $isVip = false,
    ) {}
}

class ShippingInfo extends Data
{
    public function __construct(
        public string $address,
        public string $city,
        public string $zipCode,
        public string $method = 'standard',
    ) {}
}
```

### Context Factories

Create context factories for testing:

```php
class OrderContextFactory
{
    public static function make(array $overrides = []): OrderContext
    {
        return new OrderContext(
            orderId: $overrides['orderId'] ?? Str::uuid(),
            customerId: $overrides['customerId'] ?? fake()->randomNumber(5),
            customerEmail: $overrides['customerEmail'] ?? fake()->email(),
            total: $overrides['total'] ?? fake()->randomFloat(2, 10, 1000),
            items: $overrides['items'] ?? [],
            status: $overrides['status'] ?? 'pending',
        );
    }

    public static function withItems(int $itemCount = 3): OrderContext
    {
        $items = [];
        $total = 0;
        
        for ($i = 0; $i < $itemCount; $i++) {
            $price = fake()->randomFloat(2, 5, 100);
            $quantity = fake()->numberBetween(1, 5);
            
            $items[] = [
                'sku' => fake()->lexify('???-###'),
                'price' => $price,
                'quantity' => $quantity,
                'name' => fake()->words(3, true)
            ];
            
            $total += $price * $quantity;
        }

        return self::make([
            'items' => $items,
            'total' => $total
        ]);
    }
}
```

## Context Best Practices

### 1. **Keep Context Focused**

Don't store everything in context—only what's needed for machine decisions:

```php
// Good: Only store what affects machine behavior
class AuthContext extends ContextManager
{
    public function __construct(
        public string|Optional $userId,
        public string|Optional $role,
        public bool|Optional $isVerified,
        public DateTime|Optional $lastLogin,
    ) {}
}

// Avoid: Storing unrelated data
class BadAuthContext extends ContextManager
{
    public function __construct(
        public string|Optional $userId,
        public string|Optional $favoriteColor,  // Irrelevant to auth
        public array|Optional $shoppingCart,    // Wrong domain
        public string|Optional $lastPageVisited, // UI concern
    ) {}
}
```

### 2. **Use Type Hints**

Always use type hints for better IDE support and runtime safety:

```php
// Good
public function __invoke(OrderContext $context): void {
    $total = $context->total; // IDE knows this is float
}

// Avoid
public function __invoke(ContextManager $context): void {
    $total = $context->total; // IDE doesn't know the type
}
```

### 3. **Provide Sensible Defaults**

Set reasonable defaults in your context constructor:

```php
public function __construct(
    public string|Optional $status,
    public DateTime|Optional $createdAt,
    public int|Optional $retryCount,
) {
    parent::__construct();
    
    if ($this->status instanceof Optional) {
        $this->status = 'pending';
    }
    if ($this->createdAt instanceof Optional) {
        $this->createdAt = now();
    }
    if ($this->retryCount instanceof Optional) {
        $this->retryCount = 0;
    }
}
```

### 4. **Add Computed Properties**

Include methods that compute derived values:

```php
public function isExpired(): bool
{
    return $this->expiresAt->isPast();
}

public function daysUntilExpiry(): int
{
    return max(0, now()->diffInDays($this->expiresAt));
}

public function canRetry(): bool
{
    return $this->retryCount < 3;
}
```

### 5. **Validate Context Changes**

Validate context modifications to maintain data integrity:

```php
class BankAccountContext extends ContextManager
{
    public function withdraw(float $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be positive');
        }
        
        if ($this->balance < $amount) {
            throw new InsufficientFundsException();
        }
        
        $this->balance -= $amount;
    }
}
```

## Context in Different Scenarios

### Form Wizards

```php
class FormWizardContext extends ContextManager
{
    public function __construct(
        public array|Optional $step1Data,
        public array|Optional $step2Data,
        public array|Optional $step3Data,
        public int|Optional $currentStep,
        public array|Optional $validationErrors,
    ) {
        parent::__construct();
        
        if ($this->currentStep instanceof Optional) {
            $this->currentStep = 1;
        }
    }

    public function isStepComplete(int $step): bool
    {
        return match($step) {
            1 => !empty($this->step1Data),
            2 => !empty($this->step2Data),
            3 => !empty($this->step3Data),
            default => false
        };
    }
}
```

### API Integration

```php
class ApiContext extends ContextManager
{
    public function __construct(
        public string|Optional $endpoint,
        public array|Optional $requestData,
        public array|Optional $responseData,
        public int|Optional $retryCount,
        public DateTime|Optional $lastAttempt,
        public string|Optional $errorMessage,
    ) {
        parent::__construct();
    }

    public function shouldRetry(): bool
    {
        return $this->retryCount < 3 && 
               (!$this->lastAttempt || $this->lastAttempt->addMinutes(5)->isPast());
    }
}
```

## Testing Context

### Context Creation Tests

```php
public function test_order_context_creates_with_defaults()
{
    $context = new OrderContext(
        customerId: 123,
        customerEmail: 'test@example.com'
    );

    $this->assertIsString($context->orderId);
    $this->assertEquals(0.0, $context->total);
    $this->assertEquals([], $context->items);
    $this->assertEquals('pending', $context->status);
}
```

### Context Validation Tests

```php
public function test_order_context_validates_email()
{
    $this->expectException(MachineContextValidationException::class);
    
    new OrderContext(
        customerEmail: 'invalid-email',
        customerId: 123
    );
}
```

### Context Modification Tests

```php
public function test_add_item_updates_total()
{
    $context = OrderContextFactory::make(['total' => 50.0]);
    
    $action = new AddItemAction();
    $event = new EventDefinition('ADD_ITEM', [
        'sku' => 'ABC123',
        'price' => 25.0,
        'quantity' => 2
    ]);
    
    $action($context, $event);
    
    $this->assertEquals(100.0, $context->total);
    $this->assertCount(1, $context->items);
}
```

## Next Steps

- [Guards and Conditions](./guards.md) - Using context in conditional logic
- [Hierarchical States](./hierarchical-states.md) - Context inheritance patterns
- [Testing](../testing/) - Testing context behavior thoroughly