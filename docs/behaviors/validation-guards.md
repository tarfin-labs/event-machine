# Validation Guards

Validation guards extend regular guards with the ability to provide error messages when validation fails. They're ideal for user input validation and form processing.

## Basic Usage

```php
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;

class ValidateOrderGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = null;

    public function __invoke(ContextManager $context): bool
    {
        if (empty($context->items)) {
            $this->errorMessage = 'Order must have at least one item';
            return false;
        }

        if ($context->total <= 0) {
            $this->errorMessage = 'Order total must be greater than zero';
            return false;
        }

        return true;
    }
}
```

## Exception Handling

When a validation guard fails, `MachineValidationException` is thrown:

```php
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;

try {
    $machine->send(['type' => 'SUBMIT']);
} catch (MachineValidationException $e) {
    $errorMessage = $e->getMessage();
    // 'Order must have at least one item'
}
```

## Multiple Validations

Chain multiple validation guards:

```php
'on' => [
    'CHECKOUT' => [
        'target' => 'processing',
        'guards' => [
            ValidateItemsGuard::class,
            ValidatePaymentGuard::class,
            ValidateAddressGuard::class,
        ],
    ],
],
```

Guards are evaluated in order. The first failing guard throws an exception.

## Practical Examples

### Form Field Validation

```php
class ValidateEmailGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = null;

    public function __invoke(ContextManager $context): bool
    {
        if (empty($context->email)) {
            $this->errorMessage = 'Email is required';
            return false;
        }

        if (!filter_var($context->email, FILTER_VALIDATE_EMAIL)) {
            $this->errorMessage = 'Please enter a valid email address';
            return false;
        }

        return true;
    }
}
```

### Amount Validation

```php
class ValidateAmountGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = null;

    public function __invoke(
        ContextManager $context,
        EventBehavior $event,
    ): bool {
        $amount = $event->payload['amount'] ?? 0;

        if ($amount <= 0) {
            $this->errorMessage = 'Amount must be greater than zero';
            return false;
        }

        if ($amount > $context->balance) {
            $this->errorMessage = sprintf(
                'Insufficient balance. Available: $%.2f',
                $context->balance
            );
            return false;
        }

        if ($amount > 10000) {
            $this->errorMessage = 'Amount cannot exceed $10,000';
            return false;
        }

        return true;
    }
}
```

### Business Rule Validation

```php
class ValidateBusinessHoursGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = null;

    public function __invoke(): bool
    {
        $now = now();
        $hour = $now->hour;
        $dayOfWeek = $now->dayOfWeek;

        // Weekend check
        if ($dayOfWeek === 0 || $dayOfWeek === 6) {
            $this->errorMessage = 'Orders cannot be placed on weekends';
            return false;
        }

        // Business hours check
        if ($hour < 9 || $hour >= 17) {
            $this->errorMessage = 'Orders can only be placed between 9 AM and 5 PM';
            return false;
        }

        return true;
    }
}
```

### Inventory Validation

```php
class ValidateInventoryGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = null;

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function __invoke(ContextManager $context): bool
    {
        foreach ($context->items as $item) {
            $available = $this->inventory->getAvailable($item['id']);

            if ($available < $item['quantity']) {
                $this->errorMessage = sprintf(
                    'Insufficient stock for "%s". Available: %d, Requested: %d',
                    $item['name'],
                    $available,
                    $item['quantity']
                );
                return false;
            }
        }

        return true;
    }
}
```

### User Permission Validation

```php
class ValidatePermissionGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = null;

    public function __construct(
        private readonly AuthorizationService $auth,
    ) {}

    public function __invoke(
        ContextManager $context,
        array $arguments,
    ): bool {
        $permission = $arguments[0] ?? 'default';

        if (!$this->auth->can($context->userId, $permission)) {
            $this->errorMessage = sprintf(
                'You do not have permission to perform this action. Required: %s',
                $permission
            );
            return false;
        }

        return true;
    }
}

// Usage
'guards' => 'validatePermission:approve_orders',
```

## Localized Messages

Use Laravel's translation:

```php
class ValidateOrderGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = null;

    public function __invoke(ContextManager $context): bool
    {
        if (empty($context->items)) {
            $this->errorMessage = __('validation.order.items_required');
            return false;
        }

        return true;
    }
}
```

## Integration with Laravel Forms

```php
// In Controller
public function submit(Request $request)
{
    $machine = OrderMachine::create();

    try {
        $machine->send([
            'type' => 'SUBMIT',
            'payload' => $request->all(),
        ]);

        return redirect()->route('orders.show', $machine->state->context->orderId);

    } catch (MachineValidationException $e) {
        return back()
            ->withInput()
            ->withErrors(['submit' => $e->getMessage()]);
    }
}
```

## Combining with Regular Guards

Mix validation guards with regular guards:

```php
'on' => [
    'SUBMIT' => [
        'target' => 'submitted',
        'guards' => [
            // Regular guard - silent failure
            'hasItems',
            // Validation guard - throws with message
            ValidatePaymentGuard::class,
            // Another validation guard
            ValidateAddressGuard::class,
        ],
    ],
],
```

::: tip
Regular guards fail silently (no transition occurs). Validation guards throw exceptions with messages. Use regular guards for flow control and validation guards for user feedback.
:::

## Testing Validation Guards

```php
it('shows validation error when amount is too high', function () {
    $machine = TransferMachine::create();
    $machine->state->context->balance = 100;

    expect(fn() => $machine->send([
        'type' => 'TRANSFER',
        'payload' => ['amount' => 500],
    ]))->toThrow(
        MachineValidationException::class,
        'Insufficient balance'
    );
});

it('passes validation with valid amount', function () {
    $machine = TransferMachine::create();
    $machine->state->context->balance = 1000;

    $machine->send([
        'type' => 'TRANSFER',
        'payload' => ['amount' => 500],
    ]);

    expect($machine->state->matches('transferred'))->toBeTrue();
});
```

## Best Practices

### 1. Be Specific with Error Messages

```php
// Good - actionable message
$this->errorMessage = 'Email is invalid. Please use format: name@example.com';

// Avoid - vague message
$this->errorMessage = 'Invalid input';
```

### 2. Validate Early

```php
// Check required fields first
if (empty($context->email)) {
    $this->errorMessage = 'Email is required';
    return false;
}

// Then validate format
if (!filter_var($context->email, FILTER_VALIDATE_EMAIL)) {
    $this->errorMessage = 'Invalid email format';
    return false;
}
```

### 3. Include Context in Messages

```php
$this->errorMessage = sprintf(
    'Cannot transfer $%.2f. Maximum allowed: $%.2f',
    $requestedAmount,
    $maxAmount
);
```

### 4. Use Separate Guards for Separate Concerns

```php
// Good - separate guards
'guards' => [
    ValidateEmailGuard::class,
    ValidatePasswordGuard::class,
    ValidateTermsAcceptedGuard::class,
],

// Avoid - one monolithic guard
'guards' => ValidateEverythingGuard::class,
```
