# Guards and Conditions

Guards are boolean functions that determine whether a state transition should occur. They act as gatekeepers, ensuring that transitions only happen when specific conditions are met.

## Understanding Guards

Think of guards as "if statements" that protect your state transitions:

```php
// Without guards - transition always happens
'on' => [
    'SUBMIT' => 'submitted'
]

// With guards - transition only happens if condition is met
'on' => [
    'SUBMIT' => [
        'target' => 'submitted',
        'guards' => 'isFormValid'
    ]
]
```

If the guard returns `false`, the transition is blocked and the machine stays in its current state.

## Guard Types

### 1. Inline Function Guards

Define simple guards directly in the machine definition:

```php
behavior: [
    'guards' => [
        'hasMinimumAge' => function (UserContext $context): bool {
            return $context->age >= 18;
        },
        'isWorkingHours' => function (): bool {
            $hour = now()->hour;
            return $hour >= 9 && $hour <= 17;
        },
        'hasBalance' => function (AccountContext $context, EventDefinition $event): bool {
            return $context->balance >= $event->payload['amount'];
        }
    ]
]
```

### 2. Guard Classes

Create dedicated guard classes for complex logic:

```php
<?php

use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class HasSufficientInventoryGuard extends GuardBehavior
{
    public function __invoke(OrderContext $context, EventDefinition $event): bool
    {
        $requestedQuantity = $event->payload['quantity'];
        $sku = $event->payload['sku'];
        
        $availableStock = Inventory::where('sku', $sku)->value('quantity') ?? 0;
        
        return $availableStock >= $requestedQuantity;
    }
}
```

### 3. Validation Guards

For guards that need to provide specific error messages:

```php
<?php

use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;

class IsValidEmailGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = 'Invalid email address provided';
    public bool $shouldLog = true; // Log validation failures

    public function __invoke(UserContext $context): bool
    {
        return filter_var($context->email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
```

## Using Guards

### Single Guard

```php
'on' => [
    'PLACE_ORDER' => [
        'target' => 'processing',
        'guards' => HasSufficientInventoryGuard::class,
        'actions' => 'processOrder'
    ]
]
```

### Multiple Guards (AND Logic)

All guards must pass for the transition to occur:

```php
'on' => [
    'CHECKOUT' => [
        'target' => 'payment',
        'guards' => [
            'hasItems',
            'isAddressValid',
            'isPaymentMethodValid'
        ],
        'actions' => 'initiatePayment'
    ]
]
```

### Multiple Transitions (OR Logic)

First transition whose guards pass will be taken:

```php
'on' => [
    'PROCESS_PAYMENT' => [
        [
            'target' => 'premium_processing',
            'guards' => ['isPremiumCustomer', 'hasHighValue'],
            'actions' => 'processPremium'
        ],
        [
            'target' => 'express_processing',
            'guards' => 'isExpressShipping',
            'actions' => 'processExpress'
        ],
        [
            'target' => 'standard_processing',
            'actions' => 'processStandard'
        ]
    ]
]
```

## Guard Parameters

Guards can receive various parameters based on their signature:

### Context Only

```php
public function __invoke(OrderContext $context): bool
{
    return $context->totalItems() > 0;
}
```

### Context and Event

```php
public function __invoke(OrderContext $context, EventDefinition $event): bool
{
    $maxItems = $event->payload['maxItems'] ?? 10;
    return $context->totalItems() <= $maxItems;
}
```

### Context, Event, and State

```php
public function __invoke(
    OrderContext $context, 
    EventDefinition $event, 
    StateDefinition $state
): bool {
    // Access current state information
    $isInCartState = $state->id === 'machine.cart';
    return $isInCartState && $context->hasItems();
}
```

### With Dependency Injection

Guards can use Laravel's service container:

```php
class HasValidSubscriptionGuard extends GuardBehavior
{
    public function __construct(
        private SubscriptionService $subscriptionService
    ) {}

    public function __invoke(UserContext $context): bool
    {
        return $this->subscriptionService->isActive($context->userId);
    }
}
```

## Advanced Guard Patterns

### Time-Based Guards

```php
class IsBusinessHoursGuard extends GuardBehavior
{
    public function __invoke(): bool
    {
        $now = now();
        $hour = $now->hour;
        $dayOfWeek = $now->dayOfWeek;
        
        // Monday to Friday, 9 AM to 5 PM
        return $dayOfWeek >= 1 && $dayOfWeek <= 5 && 
               $hour >= 9 && $hour <= 17;
    }
}
```

### Rate Limiting Guards

```php
class RateLimitGuard extends GuardBehavior
{
    public function __invoke(UserContext $context): bool
    {
        $key = "api_calls:{$context->userId}";
        $calls = Cache::get($key, 0);
        
        if ($calls >= 100) { // 100 calls per hour
            return false;
        }
        
        Cache::put($key, $calls + 1, 3600); // 1 hour TTL
        return true;
    }
}
```

### Permission Guards

```php
class HasPermissionGuard extends GuardBehavior
{
    private string $permission;

    public function __construct(string $permission)
    {
        $this->permission = $permission;
    }

    public function __invoke(UserContext $context): bool
    {
        $user = User::find($context->userId);
        return $user?->can($this->permission) ?? false;
    }
}

// Usage with parameters
'guards' => [
    new HasPermissionGuard('edit-orders')
]
```

### External API Guards

```php
class FraudDetectionGuard extends GuardBehavior
{
    public function __invoke(PaymentContext $context, EventDefinition $event): bool
    {
        $response = Http::timeout(5)->post('https://fraud-api.example.com/check', [
            'amount' => $context->amount,
            'card_number' => $context->cardNumber,
            'user_id' => $context->userId,
            'transaction_data' => $event->payload
        ]);

        if (!$response->successful()) {
            // Fail safe - allow transaction if fraud service is down
            Log::warning('Fraud detection service unavailable');
            return true;
        }

        return $response->json('risk_score') < 0.7;
    }
}
```

## Guard Composition

### Complex Boolean Logic

Create guards that combine multiple conditions:

```php
class AdvancedOrderGuard extends GuardBehavior
{
    public function __invoke(OrderContext $context): bool
    {
        // Complex business logic
        $hasValidItems = $this->hasValidItems($context);
        $meetsMinimum = $context->total >= 25.00;
        $shippingAvailable = $this->isShippingAvailable($context);
        $paymentValid = $this->isPaymentMethodValid($context);
        
        return $hasValidItems && 
               ($meetsMinimum || $context->isPremiumCustomer()) &&
               $shippingAvailable &&
               $paymentValid;
    }

    private function hasValidItems(OrderContext $context): bool
    {
        return count($context->items) > 0 && 
               collect($context->items)->every(fn($item) => $item['quantity'] > 0);
    }

    private function isShippingAvailable(OrderContext $context): bool
    {
        return ShippingService::isAvailable($context->shippingAddress);
    }

    private function isPaymentMethodValid(OrderContext $context): bool
    {
        return PaymentService::validateMethod($context->paymentMethod);
    }
}
```

### Guard Chains

Create reusable guard building blocks:

```php
abstract class BaseGuard extends GuardBehavior
{
    protected function and(GuardBehavior ...$guards): bool
    {
        foreach ($guards as $guard) {
            if (!$guard(...func_get_args())) {
                return false;
            }
        }
        return true;
    }

    protected function or(GuardBehavior ...$guards): bool
    {
        foreach ($guards as $guard) {
            if ($guard(...func_get_args())) {
                return true;
            }
        }
        return false;
    }
}

class CompositeOrderGuard extends BaseGuard
{
    public function __invoke(OrderContext $context): bool
    {
        return $this->and(
            new HasItemsGuard(),
            new HasValidAddressGuard()
        ) && $this->or(
            new IsPremiumCustomerGuard(),
            new MeetsMinimumOrderGuard()
        );
    }
}
```

## Error Handling

### Validation Guards with Messages

```php
class CreditCardValidationGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = 'Invalid credit card information';
    public bool $shouldLog = true;

    public function __invoke(PaymentContext $context): bool
    {
        // Validate card number
        if (!$this->isValidCardNumber($context->cardNumber)) {
            $this->errorMessage = 'Invalid card number format';
            return false;
        }

        // Validate expiry
        if (!$this->isValidExpiry($context->expiryDate)) {
            $this->errorMessage = 'Card has expired';
            return false;
        }

        // Validate CVV
        if (!$this->isValidCVV($context->cvv)) {
            $this->errorMessage = 'Invalid security code';
            return false;
        }

        return true;
    }

    private function isValidCardNumber(string $cardNumber): bool
    {
        return preg_match('/^\d{16}$/', $cardNumber) && 
               $this->luhnCheck($cardNumber);
    }

    private function luhnCheck(string $cardNumber): bool
    {
        // Luhn algorithm implementation
        $sum = 0;
        $alternate = false;
        
        for ($i = strlen($cardNumber) - 1; $i >= 0; $i--) {
            $digit = intval($cardNumber[$i]);
            
            if ($alternate) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit = ($digit % 10) + 1;
                }
            }
            
            $sum += $digit;
            $alternate = !$alternate;
        }
        
        return $sum % 10 === 0;
    }
}
```

### Handling Guard Failures

When a guard fails, the transition is blocked:

```php
// In your application code
try {
    $machine = $machine->send('PLACE_ORDER');
} catch (MachineValidationException $e) {
    // Handle guard failure
    $errorMessage = $e->getMessage();
    $failedGuards = $e->getFailedGuards();
    
    // Log the failure
    Log::warning('Order placement failed', [
        'error' => $errorMessage,
        'failed_guards' => $failedGuards
    ]);
    
    // Return error response
    return response()->json([
        'error' => 'Order could not be placed',
        'details' => $errorMessage
    ], 422);
}
```

## Required Context

Guards can specify required context fields:

```php
class InventoryGuard extends GuardBehavior
{
    public static array $requiredContext = [
        'items' => 'array',
        'warehouseId' => 'integer',
        'reservationId' => 'string'
    ];

    public function __invoke(OrderContext $context): bool
    {
        // Guard will only execute if required context is present
        return $this->checkInventoryAvailability($context);
    }
}
```

## Testing Guards

### Basic Guard Testing

```php
public function test_has_sufficient_inventory_guard_passes_with_stock()
{
    // Arrange
    $context = new OrderContext(/* ... */);
    $event = new EventDefinition('ADD_ITEM', ['sku' => 'ABC123', 'quantity' => 5]);
    
    // Mock inventory
    Inventory::factory()->create(['sku' => 'ABC123', 'quantity' => 10]);
    
    $guard = new HasSufficientInventoryGuard();
    
    // Act & Assert
    $this->assertTrue($guard($context, $event));
}

public function test_has_sufficient_inventory_guard_fails_without_stock()
{
    $context = new OrderContext(/* ... */);
    $event = new EventDefinition('ADD_ITEM', ['sku' => 'ABC123', 'quantity' => 5]);
    
    // No inventory record = 0 stock
    $guard = new HasSufficientInventoryGuard();
    
    $this->assertFalse($guard($context, $event));
}
```

### Testing Validation Guards

```php
public function test_email_validation_guard_provides_error_message()
{
    $context = new UserContext(email: 'invalid-email');
    $guard = new IsValidEmailGuard();
    
    $result = $guard($context);
    
    $this->assertFalse($result);
    $this->assertEquals('Invalid email address provided', $guard->errorMessage);
}
```

### Mocking External Dependencies

```php
public function test_fraud_detection_guard_with_mocked_api()
{
    Http::fake([
        'https://fraud-api.example.com/check' => Http::response([
            'risk_score' => 0.3
        ])
    ]);

    $context = new PaymentContext(/* ... */);
    $guard = new FraudDetectionGuard();
    
    $this->assertTrue($guard($context, new EventDefinition('PROCESS_PAYMENT', [])));
}
```

## Best Practices

### 1. **Keep Guards Pure**

Guards should be side-effect free and deterministic:

```php
// Good - pure function
public function __invoke(OrderContext $context): bool
{
    return $context->total >= 25.00;
}

// Avoid - has side effects
public function __invoke(OrderContext $context): bool
{
    Log::info('Checking minimum order'); // Side effect
    $context->lastChecked = now();       // Modifies context
    return $context->total >= 25.00;
}
```

### 2. **Use Descriptive Names**

Make guard names clearly express their purpose:

```php
// Good
'guards' => [
    'hasValidPaymentMethod',
    'isWithinOrderLimit',
    'hasShippingAddress'
]

// Avoid
'guards' => [
    'checkPayment',
    'validate',
    'check'
]
```

### 3. **Handle Failures Gracefully**

Always consider what happens when external services fail:

```php
public function __invoke(UserContext $context): bool
{
    try {
        return $this->externalService->checkPermission($context->userId);
    } catch (Exception $e) {
        Log::error('Permission check failed', ['error' => $e->getMessage()]);
        // Fail safe - deny access
        return false;
    }
}
```

### 4. **Use Caching for Expensive Operations**

Cache results of expensive guard operations:

```php
public function __invoke(UserContext $context): bool
{
    $cacheKey = "user_permissions:{$context->userId}";
    
    return Cache::remember($cacheKey, 300, function () use ($context) {
        return $this->checkUserPermissions($context->userId);
    });
}
```

### 5. **Test Guard Edge Cases**

Always test boundary conditions and failure scenarios:

```php
public function test_business_hours_guard_at_boundaries()
{
    // Test exact start time
    Carbon::setTestNow('2023-01-02 09:00:00'); // Monday 9 AM
    $this->assertTrue((new IsBusinessHoursGuard())());
    
    // Test exact end time
    Carbon::setTestNow('2023-01-02 17:00:00'); // Monday 5 PM
    $this->assertTrue((new IsBusinessHoursGuard())());
    
    // Test weekend
    Carbon::setTestNow('2023-01-01 12:00:00'); // Sunday noon
    $this->assertFalse((new IsBusinessHoursGuard())());
}
```

## Next Steps

- [Hierarchical States](./hierarchical-states.md) - Organizing complex state machines
- [Machine Definition](../guides/machine-definition.md) - Complete configuration reference
- [Testing](../testing/) - Comprehensive testing strategies