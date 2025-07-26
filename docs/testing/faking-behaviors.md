# Faking and Mocking Behaviors

EventMachine provides a sophisticated testing system through the `Fakeable` trait, allowing you to mock actions, guards, events, and results during testing. This enables isolated testing of state machine logic without executing side effects.

## Overview

The `Fakeable` trait integrates with Mockery to provide:
- Behavior mocking with expectation management
- Automatic cleanup between tests
- Assertion methods for verification
- Laravel service container integration
- Memory leak prevention

## Basic Faking

### Faking Actions

```php
use Tests\TestCase;
use App\Actions\SendEmailAction;
use App\Machines\OrderWorkflowMachine;

class OrderWorkflowTest extends TestCase
{
    public function test_order_confirmation_sends_email()
    {
        // Fake the email action
        $fake = SendEmailAction::fake();
        
        // Set up expectations
        $fake->shouldReceive('__invoke')
             ->once()
             ->with(\Mockery::type('Tarfinlabs\EventMachine\ContextManager'));
        
        // Create and interact with machine
        $machine = OrderWorkflowMachine::create();
        $machine->send('PAYMENT_RECEIVED');
        
        // Verify the action was called
        SendEmailAction::assertRan();
    }
    
    protected function tearDown(): void
    {
        // Clean up fakes
        SendEmailAction::resetFakes();
        parent::tearDown();
    }
}
```

### Faking Guards

```php
use App\Guards\PaymentValidGuard;

class PaymentTest extends TestCase
{
    public function test_invalid_payment_blocks_transition()
    {
        // Fake guard to return false
        PaymentValidGuard::fake()
            ->shouldReceive('__invoke')
            ->andReturn(false);
        
        $machine = OrderWorkflowMachine::create();
        $machine->send('PAYMENT_RECEIVED');
        
        // Machine should remain in pending state
        $this->assertEquals('pending', $machine->state->value);
        
        PaymentValidGuard::assertRan();
    }
}
```

### Faking Events

```php
use App\Events\PaymentReceivedEvent;

class EventTest extends TestCase
{
    public function test_payment_event_validation()
    {
        // Fake event to control validation
        PaymentReceivedEvent::fake()
            ->shouldReceive('__invoke')
            ->with(\Mockery::type('array'))
            ->andReturn(['amount' => 100.00, 'method' => 'credit_card']);
        
        $machine = OrderWorkflowMachine::create();
        $machine->send('PAYMENT_RECEIVED', ['raw_data' => 'test']);
        
        PaymentReceivedEvent::assertRan();
    }
}
```

## Advanced Faking Patterns

### Return Value Control

```php
class GuardTest extends TestCase
{
    public function test_guard_conditions()
    {
        // Test positive case
        HasInventoryGuard::shouldReturn(true);
        
        $machine = OrderWorkflowMachine::create();
        $machine->send('PROCESS_ORDER');
        
        $this->assertEquals('processing', $machine->state->value);
        
        // Reset and test negative case
        HasInventoryGuard::resetFakes();
        HasInventoryGuard::shouldReturn(false);
        
        $machine2 = OrderWorkflowMachine::create();
        $machine2->send('PROCESS_ORDER');
        
        $this->assertEquals('pending', $machine2->state->value);
    }
}
```

### Complex Expectations

```php
class ActionTest extends TestCase
{
    public function test_action_with_complex_expectations()
    {
        $fake = ProcessPaymentAction::fake();
        
        // Set up detailed expectations
        $fake->shouldReceive('__invoke')
             ->once()
             ->with(\Mockery::on(function (ContextManager $context) {
                 $order = $context->get('order');
                 return $order['total'] > 0 && isset($order['payment_method']);
             }))
             ->andReturnUsing(function (ContextManager $context) {
                 // Simulate successful payment processing
                 $context->set('payment_id', 'txn_123456');
                 $context->set('payment_status', 'completed');
             });
        
        $machine = OrderWorkflowMachine::create([
            'context' => [
                'order' => ['total' => 99.99, 'payment_method' => 'card'],
            ],
        ]);
        
        $machine->send('PROCESS_PAYMENT');
        
        // Verify context was updated
        $this->assertEquals('txn_123456', $machine->state->context->get('payment_id'));
        $this->assertEquals('completed', $machine->state->context->get('payment_status'));
    }
}
```

### Partial Mocking

```php
class MixedBehaviorTest extends TestCase
{
    public function test_partial_behavior_mocking()
    {
        // Only fake specific behaviors
        SendEmailAction::fake();
        // Leave other actions unfaked
        
        $machine = OrderWorkflowMachine::create();
        $machine->send('PAYMENT_RECEIVED'); // Real behavior except email
        
        // Verify only the faked behavior
        SendEmailAction::assertRan();
        
        // Other behaviors executed normally
        $this->assertEquals('paid', $machine->state->value);
    }
}
```

## Fakeable Trait Deep Dive

### Internal Implementation

The `Fakeable` trait provides several key methods:

```php
trait Fakeable
{
    private static array $fakes = [];
    
    // Create and register a mock
    public static function fake(): MockInterface
    {
        $mock = Mockery::mock(static::class);
        static::$fakes[static::class] = $mock;
        App::bind(static::class, fn () => $mock);
        return $mock;
    }
    
    // Check if behavior is faked
    public static function isFaked(): bool
    {
        return isset(static::$fakes[static::class]);
    }
    
    // Convenience methods for common patterns
    public static function shouldRun(): Expectation
    {
        return static::fake()->shouldReceive('__invoke');
    }
    
    public static function shouldReturn(mixed $value): void
    {
        static::fake()->shouldReceive('__invoke')->andReturn($value);
    }
}
```

### Memory Management

The trait includes sophisticated cleanup mechanisms:

```php
// Clean up Laravel container bindings
protected static function cleanupLaravelContainer(string $class): void
{
    if (App::has($class)) {
        App::forgetInstance($class);
        App::offsetUnset($class);
    }
}

// Reset Mockery expectations
protected static function cleanupMockeryExpectations(MockInterface $mock): void
{
    foreach (array_keys($mock->mockery_getExpectations()) as $method) {
        $mock->mockery_setExpectationsFor(
            $method,
            new ExpectationDirector($method, $mock)
        );
    }
    $mock->mockery_teardown();
}
```

## Assertion Methods

### Basic Assertions

```php
// Assert behavior was executed
SendEmailAction::assertRan();

// Assert behavior was not executed
SendEmailAction::assertNotRan();

// Assert behavior was called specific number of times
$fake = ProcessPaymentAction::fake();
$fake->shouldReceive('__invoke')->twice();

// ... trigger machine events ...

// Assertions are automatic with Mockery
```

### Custom Assertions

```php
class BehaviorTest extends TestCase
{
    public function assertActionCalledWith(string $actionClass, array $expectedContext)
    {
        $this->assertTrue(
            $actionClass::isFaked(),
            "Action {$actionClass} was not faked"
        );
        
        $fake = $actionClass::getFake();
        $fake->shouldHaveReceived('__invoke')
             ->with(\Mockery::on(function (ContextManager $context) use ($expectedContext) {
                 foreach ($expectedContext as $key => $value) {
                     if ($context->get($key) !== $value) {
                         return false;
                     }
                 }
                 return true;
             }));
    }
    
    public function test_payment_processing()
    {
        ProcessPaymentAction::fake();
        
        $machine = OrderWorkflowMachine::create(['context' => ['amount' => 100]]);
        $machine->send('PROCESS_PAYMENT');
        
        $this->assertActionCalledWith(ProcessPaymentAction::class, ['amount' => 100]);
    }
}
```

## Integration with PHPUnit

### Base Test Class

```php
abstract class MachineTestCase extends TestCase
{
    protected array $fakedBehaviors = [];
    
    protected function fakeBehavior(string $behaviorClass): MockInterface
    {
        $fake = $behaviorClass::fake();
        $this->fakedBehaviors[] = $behaviorClass;
        return $fake;
    }
    
    protected function tearDown(): void
    {
        // Clean up all faked behaviors
        foreach ($this->fakedBehaviors as $behaviorClass) {
            $behaviorClass::resetFakes();
        }
        
        // Global cleanup
        if (method_exists('Tarfinlabs\EventMachine\Traits\Fakeable', 'resetAllFakes')) {
            \Tarfinlabs\EventMachine\Traits\Fakeable::resetAllFakes();
        }
        
        parent::tearDown();
    }
}
```

### Usage in Tests

```php
class OrderWorkflowTest extends MachineTestCase
{
    public function test_complete_order_flow()
    {
        // Fake multiple behaviors
        $emailAction = $this->fakeBehavior(SendEmailAction::class);
        $paymentGuard = $this->fakeBehavior(PaymentValidGuard::class);
        
        // Set up expectations
        $paymentGuard->shouldReceive('__invoke')->andReturn(true);
        $emailAction->shouldReceive('__invoke')->once();
        
        // Test the flow
        $machine = OrderWorkflowMachine::create();
        $machine->send('PAYMENT_RECEIVED');
        
        // Assertions are handled by Mockery automatically
        $this->assertEquals('paid', $machine->state->value);
    }
    
    // tearDown() automatically cleans up all faked behaviors
}
```

## Test Data Factories

### Behavior Factories

```php
class BehaviorTestFactory
{
    public static function successfulPaymentFlow(): void
    {
        ValidatePaymentGuard::shouldReturn(true);
        ProcessPaymentAction::shouldReturn(['transaction_id' => 'txn_123']);
        SendConfirmationAction::fake()->shouldReceive('__invoke');
    }
    
    public static function failedPaymentFlow(): void
    {
        ValidatePaymentGuard::shouldReturn(false);
        ProcessPaymentAction::shouldNotReceive('__invoke');
        SendErrorEmailAction::fake()->shouldReceive('__invoke');
    }
}

// Usage in tests
class PaymentTest extends MachineTestCase
{
    public function test_successful_payment()
    {
        BehaviorTestFactory::successfulPaymentFlow();
        
        $machine = OrderWorkflowMachine::create();
        $machine->send('PAYMENT_RECEIVED');
        
        $this->assertEquals('paid', $machine->state->value);
    }
}
```

## Performance Testing

### Behavior Execution Tracking

```php
class PerformanceTest extends TestCase
{
    public function test_behavior_execution_count()
    {
        $expensiveAction = ExpensiveCalculationAction::fake();
        $expensiveAction->shouldReceive('__invoke')->once(); // Ensure it's called only once
        
        $machine = CalculatorMachine::create();
        
        // Multiple operations that might trigger the expensive action
        $machine->send('CALCULATE', ['operation' => 'complex']);
        $machine->send('RECALCULATE');
        $machine->send('FINALIZE');
        
        // Mockery will fail if called more than once
    }
}
```

## Best Practices

### Cleanup Strategies

```php
// Option 1: Per-test cleanup
public function test_something()
{
    $fake = MyAction::fake();
    // ... test logic ...
    MyAction::resetFakes();
}

// Option 2: Test class cleanup
protected function tearDown(): void
{
    MyAction::resetAllFakes();
    parent::tearDown();
}

// Option 3: Global cleanup in base test class
abstract class BaseTestCase extends TestCase
{
    protected function tearDown(): void
    {
        // Reset all fakes to prevent test interference
        collect([
            SendEmailAction::class,
            ProcessPaymentAction::class,
            ValidateOrderGuard::class,
        ])->each->resetFakes();
        
        parent::tearDown();
    }
}
```

### Behavior Verification

```php
class BehaviorVerificationTest extends TestCase
{
    public function test_critical_behaviors_are_called()
    {
        // Fake critical behaviors
        $auditAction = AuditLogAction::fake();
        $notificationAction = NotifyAdminAction::fake();
        
        // Set expectations
        $auditAction->shouldReceive('__invoke')
                   ->once()
                   ->with(\Mockery::type(ContextManager::class));
        
        $notificationAction->shouldReceive('__invoke')
                          ->once()
                          ->with(\Mockery::type(ContextManager::class));
        
        // Execute critical path
        $machine = SecurityMachine::create();
        $machine->send('SECURITY_BREACH_DETECTED');
        
        // Verify all critical behaviors executed
        AuditLogAction::assertRan();
        NotifyAdminAction::assertRan();
    }
}
```

The faking system provides comprehensive testing capabilities while maintaining clean separation between test and production code, ensuring your state machine logic is thoroughly tested without side effects.