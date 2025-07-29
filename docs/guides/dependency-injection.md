# Dependency Injection in EventMachine

EventMachine has its own built-in dependency injection system that automatically injects specific types into behavior methods. This system is **independent from Laravel's dependency injection** and focuses on providing machine-specific objects and context to your behaviors.

## Overview

EventMachine's dependency injection works by analyzing the type hints in your behavior method signatures and automatically providing the appropriate objects. This happens at runtime when the behavior is executed, without requiring any additional configuration.

## How It Works

### The Injection Mechanism

EventMachine uses the `InvokableBehavior::injectInvokableBehaviorParameters()` method to resolve dependencies. Here's how it works:

```php
// From InvokableBehavior.php (simplified)
public static function injectInvokableBehaviorParameters(
    callable $actionBehavior,
    State $state,
    ?EventBehavior $eventBehavior = null,
    ?array $actionArguments = null,
): array {
    $invocableBehaviorParameters = [];

    // Use reflection to analyze method parameters
    $invocableBehaviorReflection = $actionBehavior instanceof self
        ? new ReflectionMethod($actionBehavior, '__invoke')
        : new ReflectionFunction($actionBehavior);

    foreach ($invocableBehaviorReflection->getParameters() as $parameter) {
        $parameterType = $parameter->getType();
        $typeName = $parameterType instanceof ReflectionUnionType
            ? $parameterType->getTypes()[0]->getName()
            : $parameterType->getName();

        $value = match (true) {
            // Context Manager injection
            is_a($typeName, ContextManager::class, true) || 
            is_subclass_of($typeName, ContextManager::class) => $state->context,
            
            // Event Behavior injection
            is_a($typeName, EventBehavior::class, true) || 
            is_subclass_of($typeName, EventBehavior::class) => $eventBehavior,
            
            // State injection
            is_a($state, $typeName) => $state,
            
            // Event Collection (history) injection
            is_a($state->history, $typeName) => $state->history,
            
            // Array arguments injection
            $typeName === 'array' => $actionArguments,
            
            // Unknown types get null
            default => null,
        };

        $invocableBehaviorParameters[] = $value;
    }

    return $invocableBehaviorParameters;
}
```

### Parameter Resolution Process

1. **Reflection Analysis**: Uses PHP reflection to analyze `__invoke()` method parameters
2. **Type Matching**: Matches parameter types against available machine objects using `match` statement
3. **Automatic Injection**: Injects the appropriate values based on exact type matching
4. **Order Independence**: Parameters can be in any order in your method signature

### Supported Injection Types

EventMachine automatically injects these **specific** parameter types:

- **ContextManager** (and subclasses) - The current machine context (`$state->context`)
- **EventBehavior** (and subclasses) - The current event being processed (`$eventBehavior`)  
- **State** - The current machine state object (`$state`)
- **EventCollection** - The machine's event history (`$state->history`)
- **array** - Action arguments (when using `action:arg1,arg2` syntax)

**Important**: EventMachine does **NOT** use Laravel's service container. Only the above types are automatically injected. For other dependencies, you need to handle them manually.

## Dependency Injection in Actions

### Basic Context Injection

```php
<?php

namespace App\Actions\Order;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use App\Contexts\OrderContext;

class UpdateOrderStatusAction extends ActionBehavior
{
    // Context is automatically injected based on type hint
    public function __invoke(OrderContext $context): void
    {
        $context->status = 'processing';
        $context->updatedAt = now();
    }
}
```

### Event and Context Injection

```php
<?php

namespace App\Actions\Order;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use App\Contexts\OrderContext;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

class ProcessOrderEventAction extends ActionBehavior
{
    public function __invoke(
        OrderContext $context,      // Injected: current context
        EventDefinition $event      // Injected: current event
    ): void {
        // Access event payload
        $paymentMethod = $event->payload['payment_method'] ?? null;
        $notes = $event->payload['notes'] ?? '';
        
        // Update context based on event
        if ($paymentMethod) {
            $context->paymentMethod = $paymentMethod;
        }
        
        $context->processingNotes = $notes;
        $context->lastEventType = $event->type;
        $context->processedAt = now();
        
        // Log the event
        error_log("Processing {$event->type} for order {$context->orderId}");
    }
}
```

### All Supported Parameter Types

```php
<?php

namespace App\Actions\Order;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\EventCollection;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use App\Contexts\OrderContext;

class ComprehensiveAuditAction extends ActionBehavior
{
    public function __invoke(
        OrderContext $context,        // Injected: Current context (typed subclass)
        EventDefinition $event,       // Injected: Current event
        State $state,                 // Injected: Current state
        EventCollection $history      // Injected: Event history
    ): void {
        // All these parameters are automatically injected by EventMachine
        // You can use them in any order in your method signature
        
        $auditData = [
            'order_id' => $context->orderId,
            'event_type' => $event->type,
            'event_payload' => $event->payload,
            'current_state' => $state->value,
            'current_state_definition_id' => $state->currentStateDefinition->id,
            'previous_events_count' => $history->count(),
            'context_snapshot' => $context->toArray(),
            'timestamp' => now()->toISOString()
        ];
        
        // Store audit data in context
        if (!isset($context->auditLog)) {
            $context->auditLog = [];
        }
        $context->auditLog[] = $auditData;
        
        // Or log directly (since Laravel helpers are available)
        logger('Order state change', $auditData);
    }
}
```

### Action Arguments Injection

EventMachine supports passing arguments to actions using the `action:arg1,arg2,arg3` syntax:

```php
<?php

namespace App\Actions\Order;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use App\Contexts\OrderContext;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

class ApplyDiscountAction extends ActionBehavior
{
    public function __invoke(
        OrderContext $context,
        EventDefinition $event,
        ?array $arguments = null  // Injected from action:arg1,arg2 syntax
    ): void {
        if ($arguments) {
            $discountType = $arguments[0] ?? 'percentage';
            $discountValue = (float)($arguments[1] ?? 0);
            
            $discount = match($discountType) {
                'percentage' => $context->subtotal * ($discountValue / 100),
                'fixed' => $discountValue,
                default => 0
            };
            
            $context->discountAmount = $discount;
            $context->total = $context->subtotal + $context->taxAmount - $discount;
            $context->discountType = $discountType;
        }
    }
}
```

Usage in machine definition:

```php
'states' => [
    'cart' => [
        'on' => [
            'APPLY_DISCOUNT' => [
                'actions' => 'applyDiscount:percentage,10'  // 10% discount
            ],
            'APPLY_FIXED_DISCOUNT' => [
                'actions' => 'applyDiscount:fixed,25.50'   // $25.50 discount
            ]
        ]
    ]
]
```

### Parameter Order Independence

The beauty of EventMachine's injection is that parameter order doesn't matter:

```php
<?php

class FlexibleAction extends ActionBehavior
{
    // These are all equivalent:
    
    // Option 1
    public function __invoke(OrderContext $context, EventDefinition $event): void {}
    
    // Option 2 - reversed order
    public function __invoke(EventDefinition $event, OrderContext $context): void {}
    
    // Option 3 - with state
    public function __invoke(State $state, OrderContext $context, EventDefinition $event): void {}
    
    // Option 4 - with history and arguments
    public function __invoke(
        ?array $arguments, 
        EventCollection $history, 
        OrderContext $context, 
        EventDefinition $event
    ): void {}
}
```

## Dependency Injection in Guards

Guards support the same dependency injection patterns as actions:

### Basic Guard with Context

```php
<?php

namespace App\Guards\Order;

use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use App\Contexts\OrderContext;

class HasItemsGuard extends GuardBehavior
{
    public function __invoke(OrderContext $context): bool
    {
        return count($context->items) > 0;
    }
}
```

### Guard with Event Data

```php
<?php

namespace App\Guards\Order;

use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use App\Contexts\OrderContext;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

class ValidQuantityGuard extends GuardBehavior
{
    public function __invoke(
        OrderContext $context,
        EventDefinition $event
    ): bool {
        $requestedQuantity = $event->payload['quantity'] ?? 0;
        $maxQuantityPerItem = 100;
        
        return $requestedQuantity > 0 && $requestedQuantity <= $maxQuantityPerItem;
    }
}
```

### Validation Guard with Error Messages

```php
<?php

namespace App\Guards\Order;

use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;
use App\Contexts\OrderContext;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

class OrderValueLimitGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = 'Order value exceeds customer limit';
    public bool $shouldLog = true;

    public function __invoke(
        OrderContext $context,
        EventDefinition $event
    ): bool {
        // Simple business logic (no external services injected)
        $customerLimit = match($context->customerTier) {
            'premium' => 10000.00,
            'standard' => 5000.00,
            'basic' => 1000.00,
            default => 500.00
        };

        if ($context->total > $customerLimit) {
            $this->errorMessage = "Order value ${context->total} exceeds {$context->customerTier} customer limit of ${customerLimit}";
            return false;
        }

        return true;
    }
}
```

## Dependency Injection in Events

Event classes can also use dependency injection for validation and processing:

### Event with Context Access

```php
<?php

namespace App\Events\Order;

use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use App\Contexts\OrderContext;

class AddItemEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'ADD_ITEM';
    }

    public function validatePayload(): array
    {
        return [
            'sku' => 'required|string',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0'
        ];
    }

    // Custom validation method (if you need context-aware validation)
    public function validateWithContext(OrderContext $context): bool
    {
        $sku = $this->payload['sku'] ?? '';
        
        // Check if item already exists in order
        $existingItem = collect($context->items)->firstWhere('sku', $sku);
        
        if ($existingItem) {
            $this->addError('sku', 'Item already exists in order');
            return false;
        }
        
        return true;
    }
}
```

## Working with External Services

Since EventMachine doesn't inject Laravel services automatically, you need to handle external dependencies manually. Here are the recommended patterns:

### Pattern 1: Static Methods and Helpers

```php
<?php

namespace App\Actions\Order;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use App\Contexts\OrderContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendOrderConfirmationAction extends ActionBehavior
{
    public function __invoke(OrderContext $context): void
    {
        // Use Laravel facades (static methods)
        Mail::to($context->customerEmail)->send(
            new OrderConfirmationMail($context->orderId, $context->total)
        );
        
        Log::info('Order confirmation sent', [
            'order_id' => $context->orderId,
            'customer_email' => $context->customerEmail
        ]);
        
        $context->confirmationSentAt = now();
    }
}
```

### Pattern 2: Laravel Helpers

```php
<?php

namespace App\Actions\Order;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use App\Contexts\OrderContext;

class CacheOrderDataAction extends ActionBehavior
{
    public function __invoke(OrderContext $context): void
    {
        // Use Laravel helpers
        cache()->put(
            "order:{$context->orderId}",
            $context->toArray(),
            now()->addHours(24)
        );
        
        // Log using helper
        logger('Order cached', ['order_id' => $context->orderId]);
        
        $context->cachedAt = now();
    }
}
```

### Pattern 3: Manual Service Resolution

```php
<?php

namespace App\Actions\Order;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use App\Contexts\OrderContext;
use App\Services\PaymentService;

class ProcessPaymentAction extends ActionBehavior
{
    public function __invoke(OrderContext $context): void
    {
        // Manually resolve service from container
        $paymentService = app(PaymentService::class);
        
        $result = $paymentService->charge([
            'amount' => $context->total,
            'payment_method' => $context->paymentMethod,
            'customer_id' => $context->customerId
        ]);
        
        if ($result->successful()) {
            $context->paymentId = $result->id;
            $context->paidAt = now();
        } else {
            throw new PaymentFailedException($result->error);
        }
    }
}
```

### Pattern 4: Service Classes with Static Methods

```php
<?php

namespace App\Services;

class OrderService
{
    public static function calculateShipping(array $items, string $destination): float
    {
        // Static service method that can be called without instantiation
        $totalWeight = array_sum(array_column($items, 'weight'));
        
        return match($destination) {
            'local' => $totalWeight * 0.1,
            'national' => $totalWeight * 0.25,
            'international' => $totalWeight * 0.5,
            default => $totalWeight * 0.2
        };
    }
}
```

```php
<?php

namespace App\Actions\Order;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use App\Contexts\OrderContext;
use App\Services\OrderService;

class CalculateShippingAction extends ActionBehavior
{
    public function __invoke(OrderContext $context): void
    {
        // Use static service method
        $shippingCost = OrderService::calculateShipping(
            $context->items,
            $context->shippingAddress['country']
        );
        
        $context->shippingCost = $shippingCost;
        $context->total = $context->subtotal + $context->taxAmount + $shippingCost;
    }
}
```

## Testing with EventMachine's Dependency Injection

### Basic Action Testing

```php
<?php

namespace Tests\Unit\Actions;

use Tests\TestCase;
use App\Actions\Order\UpdateOrderStatusAction;
use App\Contexts\OrderContext;

class UpdateOrderStatusActionTest extends TestCase
{
    public function test_updates_order_status()
    {
        $context = new OrderContext(
            orderId: 'ORD-123',
            status: 'pending'
        );

        $action = new UpdateOrderStatusAction();
        $action($context);

        $this->assertEquals('processing', $context->status);
        $this->assertNotNull($context->updatedAt);
    }
}
```

### Testing with Event Data

```php
<?php

namespace Tests\Unit\Actions;

use Tests\TestCase;
use App\Actions\Order\ProcessOrderEventAction;
use App\Contexts\OrderContext;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

class ProcessOrderEventActionTest extends TestCase
{
    public function test_processes_event_with_payment_method()
    {
        $context = new OrderContext(orderId: 'ORD-123');
        $event = new EventDefinition('PROCESS_PAYMENT', [
            'payment_method' => 'stripe',
            'notes' => 'Rush order'
        ]);

        $action = new ProcessOrderEventAction();
        $action($context, $event);

        $this->assertEquals('stripe', $context->paymentMethod);
        $this->assertEquals('Rush order', $context->processingNotes);
        $this->assertEquals('PROCESS_PAYMENT', $context->lastEventType);
        $this->assertNotNull($context->processedAt);
    }
}
```

### Testing Guards

```php
<?php

namespace Tests\Unit\Guards;

use Tests\TestCase;
use App\Guards\Order\ValidQuantityGuard;
use App\Contexts\OrderContext;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

class ValidQuantityGuardTest extends TestCase
{
    public function test_allows_valid_quantity()
    {
        $context = new OrderContext(orderId: 'ORD-123');
        $event = new EventDefinition('ADD_ITEM', ['quantity' => 5]);

        $guard = new ValidQuantityGuard();
        
        $this->assertTrue($guard($context, $event));
    }

    public function test_blocks_invalid_quantity()
    {
        $context = new OrderContext(orderId: 'ORD-123');
        $event = new EventDefinition('ADD_ITEM', ['quantity' => 150]); // Over limit

        $guard = new ValidQuantityGuard();
        
        $this->assertFalse($guard($context, $event));
    }

    public function test_blocks_zero_quantity()
    {
        $context = new OrderContext(orderId: 'ORD-123');
        $event = new EventDefinition('ADD_ITEM', ['quantity' => 0]);

        $guard = new ValidQuantityGuard();
        
        $this->assertFalse($guard($context, $event));
    }
}
```

## Understanding the Injection Source Code

To better understand how EventMachine's dependency injection works, let's examine the key parts of the source code:

### The Match Statement

```php
// From InvokableBehavior::injectInvokableBehaviorParameters()
$value = match (true) {
    // Context Manager injection
    is_a($typeName, class: ContextManager::class, allow_string: true) || 
    is_subclass_of($typeName, class: ContextManager::class) => $state->context,
    
    // Event Behavior injection  
    is_a($typeName, class: EventBehavior::class, allow_string: true) || 
    is_subclass_of($typeName, class: EventBehavior::class) => $eventBehavior,
    
    // State injection
    is_a($state, $typeName) => $state,
    
    // Event Collection (history) injection
    is_a($state->history, $typeName) => $state->history,
    
    // Array arguments injection
    $typeName === 'array' => $actionArguments,
    
    // Unknown types get null
    default => null,
};
```

This match statement is the core of EventMachine's dependency injection. It:

1. **Checks for ContextManager types** - Injects `$state->context`
2. **Checks for EventBehavior types** - Injects `$eventBehavior`
3. **Checks for State types** - Injects `$state`
4. **Checks for EventCollection types** - Injects `$state->history`
5. **Checks for array type** - Injects `$actionArguments`
6. **Defaults to null** - For unknown types

### Union Type Support

EventMachine also supports PHP 8 union types:

```php
$typeName = $parameterType instanceof ReflectionUnionType
    ? $parameterType->getTypes()[0]->getName()  // Takes first type from union
    : $parameterType->getName();
```

This means you can use union types in your method signatures:

```php
public function __invoke(OrderContext|ContextManager $context): void
{
    // EventMachine will inject based on the first type (OrderContext)
}
```

## Best Practices

### 1. Use Specific Type Hints

Always use the most specific type hints for better IDE support and clearer intent:

```php
// Good - specific context type
public function __invoke(OrderContext $context): void

// Less ideal - generic context type  
public function __invoke(ContextManager $context): void
```

### 2. Parameter Order Doesn't Matter

Take advantage of EventMachine's order-independent injection:

```php
// These are equivalent:
public function __invoke(OrderContext $context, EventDefinition $event): void {}
public function __invoke(EventDefinition $event, OrderContext $context): void {}
```

### 3. Use Nullable Parameters When Appropriate

Some parameters might not always be available:

```php
public function __invoke(
    OrderContext $context,
    ?EventDefinition $event = null,      // Might be null
    ?array $arguments = null             // Might be null
): void {
    if ($event) {
        // Handle event data
    }
    
    if ($arguments) {
        // Handle arguments
    }
}
```

### 4. Handle External Services Explicitly

Since EventMachine doesn't inject Laravel services, be explicit about external dependencies:

```php
public function __invoke(OrderContext $context): void
{
    // Good - explicit about external service usage
    $paymentService = app(PaymentService::class);
    $result = $paymentService->process();
    
    // Or use facades/helpers
    Mail::to($context->customerEmail)->send(new OrderConfirmation());
    
    // Or use static methods
    $cost = ShippingCalculator::calculate($context->items);
}
```

### 5. Document Complex Parameter Patterns

When using many parameters, document their purpose:

```php
public function __invoke(
    OrderContext $context,        // Current order data
    EventDefinition $event,       // Triggering event
    State $state,                 // Current machine state
    EventCollection $history,     // Event history for analysis
    ?array $arguments = null      // Action arguments if provided
): void {
    // Method implementation
}
```

## Summary

EventMachine's dependency injection system:

1. **Is Independent**: Does not use Laravel's service container
2. **Is Type-Based**: Injects based on exact type matching
3. **Is Order-Independent**: Parameters can be in any order
4. **Supports 5 Types**: ContextManager, EventBehavior, State, EventCollection, and array
5. **Is Automatic**: No configuration required
6. **Is Reflection-Based**: Uses PHP reflection to analyze method signatures

Understanding this system helps you write more effective behaviors and properly handle external dependencies when needed.

## Next Steps

- [Machine Definition](./machine-definition.md) - Complete configuration options
- [Behavior System](./behavior-system.md) - Overview of all behavior types  
- [Context Management](../concepts/context.md) - Deep dive into context patterns
- [Testing](../testing/) - Advanced testing techniques