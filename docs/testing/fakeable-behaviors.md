# Fakeable Behaviors

All EventMachine behaviors include the `Fakeable` trait, allowing you to mock and test them in isolation.

## Basic Faking

### Create a Fake

<!-- doctest-attr: ignore -->
```php
use App\Machines\Actions\ProcessOrderAction;

ProcessOrderAction::fake();
```

### Check if Faked

<!-- doctest-attr: ignore -->
```php
ProcessOrderAction::isFaked(); // true
```

### Get the Fake

<!-- doctest-attr: ignore -->
```php
$fake = ProcessOrderAction::getFake(); // Mockery mock instance
```

## Setting Expectations

### Basic Expectations

<!-- doctest-attr: ignore -->
```php
ProcessOrderAction::fake();

// Expect to run once
ProcessOrderAction::shouldRun()->once();

// Expect to run twice
ProcessOrderAction::shouldRun()->twice();

// Expect to run any number of times
ProcessOrderAction::shouldRun()->zeroOrMoreTimes();

// Expect never to run
ProcessOrderAction::shouldRun()->never();
```

### With Arguments

<!-- doctest-attr: ignore -->
```php
ProcessOrderAction::fake();

ProcessOrderAction::shouldRun()
    ->once()
    ->withArgs(function (ContextManager $context) {
        return $context->orderId === 'order-123';
    });
```

### With Return Values

<!-- doctest-attr: ignore -->
```php
ProcessOrderAction::fake();

// Return nothing (void)
ProcessOrderAction::shouldRun()->once();

// Execute custom logic
ProcessOrderAction::shouldRun()
    ->once()
    ->andReturnUsing(function (ContextManager $context) {
        $context->processed = true;
        $context->processedAt = now();
    });
```

## Assertions

### Assert Ran

<!-- doctest-attr: ignore -->
```php
ProcessOrderAction::fake();

// Run machine
$machine = OrderMachine::create();
$machine->send(['type' => 'PROCESS']);

// Assert the action ran
ProcessOrderAction::assertRan();
```

### Assert Not Ran

<!-- doctest-attr: ignore -->
```php
ProcessOrderAction::fake();

// Don't trigger the action
$machine = OrderMachine::create();

// Assert the action did not run
ProcessOrderAction::assertNotRan();
```

## Resetting Fakes

### Reset Single Behavior

<!-- doctest-attr: ignore -->
```php
ProcessOrderAction::resetFakes();
```

### Reset All Fakes

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Facades\EventMachine;

EventMachine::resetAllFakes();
```

### In Test Teardown

<!-- doctest-attr: ignore -->
```php
afterEach(function () {
    EventMachine::resetAllFakes();
});
```

## Complete Example

<!-- doctest-attr: ignore -->
```php
use App\Machines\OrderMachine;
use App\Machines\Actions\ProcessOrderAction;
use App\Machines\Actions\SendNotificationAction;
use App\Machines\Guards\ValidateOrderGuard;
use Tarfinlabs\EventMachine\Facades\EventMachine;

beforeEach(function () {
    // Create fakes
    ProcessOrderAction::fake();
    SendNotificationAction::fake();
    ValidateOrderGuard::fake();
});

afterEach(function () {
    EventMachine::resetAllFakes();
});

it('processes order when valid', function () {
    // Setup guard to pass
    ValidateOrderGuard::shouldRun()
        ->once()
        ->andReturn(true);

    // Setup actions
    ProcessOrderAction::shouldRun()
        ->once()
        ->andReturnUsing(function ($context) {
            $context->orderId = 'order-123';
            $context->processed = true;
        });

    SendNotificationAction::shouldRun()->once();

    // Execute
    $machine = OrderMachine::create();
    $machine->send(['type' => 'SUBMIT']);

    // Assert
    expect($machine->state->matches('processing'))->toBeTrue();
    expect($machine->state->context->orderId)->toBe('order-123');

    ProcessOrderAction::assertRan();
    SendNotificationAction::assertRan();
});

it('rejects order when validation fails', function () {
    // Setup guard to fail
    ValidateOrderGuard::shouldRun()
        ->once()
        ->andReturn(false);

    // Execute
    $machine = OrderMachine::create();
    $machine->send(['type' => 'SUBMIT']);

    // Assert - stays in pending (guard blocked transition)
    expect($machine->state->matches('pending'))->toBeTrue();

    // Action should not have run
    ProcessOrderAction::assertNotRan();
});
```

## Faking Guards

<!-- doctest-attr: ignore -->
```php
use App\Machines\Guards\HasPermissionGuard;

HasPermissionGuard::fake();

// Always pass
HasPermissionGuard::shouldRun()
    ->andReturn(true);

// Always fail
HasPermissionGuard::shouldRun()
    ->andReturn(false);

// Conditional
HasPermissionGuard::shouldRun()
    ->andReturnUsing(function ($context) {
        return $context->userId === 'admin';
    });
```

## Faking Validation Guards

<!-- doctest-attr: ignore -->
```php
use App\Machines\Guards\ValidateAmountGuard;

ValidateAmountGuard::fake();

// Pass validation
ValidateAmountGuard::shouldRun()->andReturn(true);

// Fail validation (exception will be thrown)
ValidateAmountGuard::shouldRun()->andReturn(false);
```

## Faking Calculators

<!-- doctest-attr: ignore -->
```php
use App\Machines\Calculators\CalculateTotalCalculator;

CalculateTotalCalculator::fake();

CalculateTotalCalculator::shouldRun()
    ->once()
    ->andReturnUsing(function ($context) {
        $context->subtotal = 100;
        $context->tax = 10;
        $context->total = 110;
    });
```

## Testing with Dependencies

When behaviors have constructor dependencies, faking bypasses them:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
class ProcessOrderAction extends ActionBehavior
{
    public function __construct(
        private readonly PaymentGateway $gateway, // Not called when faked
    ) {}
}

// No need to mock PaymentGateway
ProcessOrderAction::fake();
ProcessOrderAction::shouldRun()->once();
```

## Multiple Calls

<!-- doctest-attr: ignore -->
```php
ProcessOrderAction::fake();

// Expect specific number of calls
ProcessOrderAction::shouldRun()->times(3);

// Different behavior per call
ProcessOrderAction::shouldRun()
    ->once()
    ->andReturnUsing(fn($ctx) => $ctx->step = 1);

ProcessOrderAction::shouldRun()
    ->once()
    ->andReturnUsing(fn($ctx) => $ctx->step = 2);
```

## Testing Raised Events

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
class ProcessAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->processed = true;
        $this->raise(['type' => 'PROCESSED']);
    }
}

// Fake but preserve raise behavior
ProcessAction::fake();
ProcessAction::shouldRun()
    ->once()
    ->andReturnUsing(function ($context) use ($action) {
        $context->processed = true;
        // Can't easily test raise() with fakes
        // Consider integration test instead
    });
```

## Integration vs Unit Testing

### Unit Testing (with Fakes)

<!-- doctest-attr: ignore -->
```php
// Fast, isolated
ProcessOrderAction::fake();
ValidateOrderGuard::fake();

$machine = OrderMachine::create();
$machine->send(['type' => 'SUBMIT']);

ProcessOrderAction::assertRan();
```

### Integration Testing (without Fakes)

<!-- doctest-attr: ignore -->
```php
// Slower, tests real behavior
$machine = OrderMachine::create();
$machine->send(['type' => 'SUBMIT']);

expect($machine->state->context->orderId)->not->toBeNull();
$this->assertDatabaseHas('orders', ['id' => $machine->state->context->orderId]);
```

## Best Practices

### 1. Reset Fakes Between Tests

<!-- doctest-attr: ignore -->
```php
afterEach(function () {
    EventMachine::resetAllFakes();
});
```

### 2. Be Explicit About Expectations

<!-- doctest-attr: ignore -->
```php
// Good - explicit expectation
ProcessAction::shouldRun()->once();

// Avoid - no expectation
ProcessAction::fake();
```

### 3. Test Both Success and Failure Paths

<!-- doctest-attr: ignore -->
```php
it('processes when valid', function () {
    ValidateGuard::fake()->shouldRun()->andReturn(true);
    // ...
});

it('rejects when invalid', function () {
    ValidateGuard::fake()->shouldRun()->andReturn(false);
    // ...
});
```

### 4. Use Integration Tests for Complex Flows

For complex multi-step flows, consider integration tests without fakes.
