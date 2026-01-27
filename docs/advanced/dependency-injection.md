# Dependency Injection

EventMachine uses Laravel's service container for dependency injection in behaviors. This allows you to inject services, repositories, and other dependencies into your actions, guards, calculators, and other behaviors.

## Constructor Injection

Class-based behaviors support constructor injection:

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ProcessOrderAction extends ActionBehavior
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly PaymentGateway $paymentGateway,
        private readonly NotificationService $notifications,
    ) {}

    public function __invoke(ContextManager $context): void
    {
        $order = $this->orderService->create($context->items);
        $this->paymentGateway->charge($order->total);
        $this->notifications->orderConfirmed($order);

        $context->orderId = $order->id;
    }
}
```

## Parameter Injection

The `__invoke` method receives injected parameters:

```php
public function __invoke(
    ContextManager $context,      // Current context
    EventBehavior $event,         // Triggering event
    State $state,                 // Current state
    EventCollection $history,     // Event history
    array $arguments,             // Behavior arguments
): void {
    // Use injected parameters
}
```

### Available Parameters

| Type | Description |
|------|-------------|
| `ContextManager` | Machine context (or your custom context class) |
| `EventBehavior` | The event that triggered the transition |
| `State` | Current machine state |
| `EventCollection` | History of all events |
| `array` | Arguments passed via behavior string |

### Partial Injection

Only declare the parameters you need:

```php
// Only context needed
public function __invoke(ContextManager $context): void
{
    $context->count++;
}

// Context and event needed
public function __invoke(
    ContextManager $context,
    EventBehavior $event,
): void {
    $context->value = $event->payload['value'];
}

// All parameters
public function __invoke(
    ContextManager $context,
    EventBehavior $event,
    State $state,
    EventCollection $history,
): void {
    // Full access
}
```

## Typed Context Injection

Custom context classes are automatically injected:

```php
class OrderContext extends ContextManager
{
    public string $orderId = '';
    public array $items = [];
}

class ProcessOrderAction extends ActionBehavior
{
    public function __invoke(OrderContext $context): void
    {
        // $context is typed as OrderContext
        // Full IDE autocompletion available
        $orderId = $context->orderId;
    }
}
```

## Guard with Dependencies

```php
class HasPermissionGuard extends GuardBehavior
{
    public function __construct(
        private readonly AuthorizationService $auth,
    ) {}

    public function __invoke(ContextManager $context): bool
    {
        return $this->auth->can($context->userId, 'submit_order');
    }
}
```

## Calculator with Dependencies

```php
class CalculateTaxCalculator extends CalculatorBehavior
{
    public function __construct(
        private readonly TaxService $taxService,
        private readonly GeoLocationService $geo,
    ) {}

    public function __invoke(ContextManager $context): void
    {
        $location = $this->geo->locate($context->shippingAddress);
        $taxRate = $this->taxService->getRateForLocation($location);

        $context->taxRate = $taxRate;
        $context->tax = $context->subtotal * $taxRate;
    }
}
```

## Event Behavior with Dependencies

```php
class SubmitOrderEvent extends EventBehavior
{
    public function __construct(
        private readonly RequestValidator $validator,
    ) {
        parent::__construct();
    }

    public static function getType(): string
    {
        return 'SUBMIT_ORDER';
    }

    public function actor(ContextManager $context): mixed
    {
        return auth()->user();
    }
}
```

## Result with Dependencies

```php
class OrderResultBehavior extends ResultBehavior
{
    public function __construct(
        private readonly ReceiptGenerator $receipts,
        private readonly OrderRepository $orders,
    ) {}

    public function __invoke(ContextManager $context): array
    {
        $order = $this->orders->find($context->orderId);
        $receipt = $this->receipts->generate($order);

        return [
            'order' => $order->toArray(),
            'receiptUrl' => $receipt->url,
        ];
    }
}
```

## Practical Examples

### Complete Action with Multiple Services

```php
class CompleteCheckoutAction extends ActionBehavior
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly InventoryService $inventory,
        private readonly PaymentProcessor $payments,
        private readonly EmailService $email,
        private readonly AnalyticsService $analytics,
    ) {}

    public function __invoke(
        CheckoutContext $context,
        EventBehavior $event,
    ): void {
        // Create order
        $order = $this->orders->create([
            'customer_id' => $context->customerId,
            'items' => $context->items,
            'total' => $context->total,
        ]);

        // Reserve inventory
        foreach ($context->items as $item) {
            $this->inventory->reserve($item['id'], $item['quantity']);
        }

        // Process payment
        $payment = $this->payments->charge(
            $context->paymentMethod,
            $context->total,
        );

        // Send confirmation
        $this->email->sendOrderConfirmation($order);

        // Track analytics
        $this->analytics->track('order_completed', [
            'order_id' => $order->id,
            'total' => $order->total,
        ]);

        // Update context
        $context->orderId = $order->id;
        $context->paymentId = $payment->id;
    }
}
```

### Guard with External Service

```php
class CheckInventoryGuard extends GuardBehavior
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function __invoke(ContextManager $context): bool
    {
        foreach ($context->items as $item) {
            if (!$this->inventory->isAvailable($item['id'], $item['quantity'])) {
                return false;
            }
        }
        return true;
    }
}
```

### Validation Guard with Repository

```php
class ValidateUserGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = null;

    public function __construct(
        private readonly UserRepository $users,
    ) {}

    public function __invoke(ContextManager $context): bool
    {
        $user = $this->users->find($context->userId);

        if (!$user) {
            $this->errorMessage = 'User not found';
            return false;
        }

        if ($user->isSuspended()) {
            $this->errorMessage = 'User account is suspended';
            return false;
        }

        if (!$user->hasVerifiedEmail()) {
            $this->errorMessage = 'Please verify your email first';
            return false;
        }

        return true;
    }
}
```

## Binding Custom Implementations

### In Service Provider

```php
// app/Providers/AppServiceProvider.php
public function register(): void
{
    $this->app->bind(PaymentGateway::class, StripeGateway::class);

    $this->app->bind(NotificationService::class, function ($app) {
        return new SlackNotificationService(
            config('services.slack.webhook_url'),
        );
    });
}
```

### Environment-Based Binding

```php
public function register(): void
{
    if ($this->app->environment('testing')) {
        $this->app->bind(PaymentGateway::class, FakePaymentGateway::class);
        $this->app->bind(EmailService::class, FakeEmailService::class);
    } else {
        $this->app->bind(PaymentGateway::class, StripeGateway::class);
        $this->app->bind(EmailService::class, SendGridService::class);
    }
}
```

## Testing with DI

### Mock Dependencies

```php
it('processes order with mocked services', function () {
    $orderService = Mockery::mock(OrderService::class);
    $orderService->shouldReceive('create')
        ->once()
        ->andReturn(new Order(['id' => 'order-123']));

    app()->instance(OrderService::class, $orderService);

    $machine = OrderMachine::create();
    $machine->send(['type' => 'SUBMIT']);

    expect($machine->state->context->orderId)->toBe('order-123');
});
```

### Using Fake Behaviors

```php
it('uses fake behavior for testing', function () {
    ProcessOrderAction::fake();

    ProcessOrderAction::shouldRun()
        ->once()
        ->andReturnUsing(function ($context) {
            $context->orderId = 'fake-order-123';
        });

    $machine = OrderMachine::create();
    $machine->send(['type' => 'SUBMIT']);

    ProcessOrderAction::assertRan();
    expect($machine->state->context->orderId)->toBe('fake-order-123');
});
```

## Best Practices

### 1. Use Interface Bindings

```php
// Define interface
interface PaymentGatewayInterface
{
    public function charge(float $amount): PaymentResult;
}

// Bind in service provider
$this->app->bind(PaymentGatewayInterface::class, StripeGateway::class);

// Use in behavior
public function __construct(
    private readonly PaymentGatewayInterface $payments,
) {}
```

### 2. Keep Dependencies Minimal

```php
// Good - focused dependencies
public function __construct(
    private readonly OrderRepository $orders,
) {}

// Avoid - too many dependencies
public function __construct(
    private readonly OrderRepository $orders,
    private readonly UserRepository $users,
    private readonly ProductRepository $products,
    private readonly PaymentService $payments,
    private readonly ShippingService $shipping,
    private readonly TaxService $tax,
    private readonly NotificationService $notifications,
    private readonly AnalyticsService $analytics,
) {}
// Consider breaking into smaller actions
```

### 3. Use Readonly Properties

```php
public function __construct(
    private readonly OrderService $orders,  // readonly prevents reassignment
) {}
```

### 4. Inject Interfaces, Not Implementations

```php
// Good
public function __construct(
    private readonly LoggerInterface $logger,
) {}

// Avoid
public function __construct(
    private readonly MonologLogger $logger,
) {}
```
