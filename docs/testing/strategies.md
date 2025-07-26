# Testing Strategies

EventMachine provides comprehensive testing support to ensure your state machines work correctly. This guide covers various testing approaches, from unit tests to integration tests.

## Testing Philosophy

When testing state machines, focus on:

1. **State Transitions** - Verify transitions work as expected
2. **Guard Conditions** - Test that guards properly control transitions
3. **Action Execution** - Ensure actions produce correct side effects
4. **Context Changes** - Verify context is properly modified
5. **Error Handling** - Test error states and recovery paths
6. **Business Logic** - Validate business rules are enforced

## Types of Testing

### 1. Unit Testing

Test individual components in isolation:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Guards\Order\CanRetryPaymentGuard;
use App\Contexts\OrderContext;

class CanRetryPaymentGuardTest extends TestCase
{
    public function test_allows_retry_when_under_limit()
    {
        $context = new OrderContext(paymentRetries: 2);
        $guard = new CanRetryPaymentGuard();
        
        $this->assertTrue($guard($context));
    }

    public function test_blocks_retry_when_at_limit()
    {
        $context = new OrderContext(paymentRetries: 3);
        $guard = new CanRetryPaymentGuard();
        
        $this->assertFalse($guard($context));
    }

    public function test_blocks_retry_when_over_limit()
    {
        $context = new OrderContext(paymentRetries: 5);
        $guard = new CanRetryPaymentGuard();
        
        $this->assertFalse($guard($context));
    }
}
```

### 2. State Machine Testing

Test the machine as a whole:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Machines\OrderProcessingMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrderProcessingMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_order_flow()
    {
        $machine = OrderProcessingMachine::create([
            'customerId' => 1,
            'items' => [
                ['sku' => 'PROD-001', 'quantity' => 2, 'price' => 50.00]
            ],
            'paymentMethod' => 'card_123'
        ]);

        // Initial state
        $this->assertEquals('validating', $machine->state->value);

        // Progress through states
        $machine = $machine->send('VALIDATION_SUCCESS');
        $this->assertEquals('reservingInventory', $machine->state->value);

        $machine = $machine->send('INVENTORY_RESERVED');
        $this->assertEquals('processingPayment', $machine->state->value);

        $machine = $machine->send('PAYMENT_SUCCESS');
        $this->assertEquals('fulfillment.preparing', $machine->state->value);
    }

    public function test_handles_payment_failure_with_retries()
    {
        $machine = OrderProcessingMachine::create(['customerId' => 1]);
        
        // Move to payment state
        $machine = $machine->send('VALIDATION_SUCCESS');
        $machine = $machine->send('INVENTORY_RESERVED');

        // First payment failure - should retry
        $machine = $machine->send('PAYMENT_FAILED');
        $this->assertEquals('paymentRetry', $machine->state->value);
        $this->assertEquals(1, $machine->state->context->paymentRetries);

        // Exhaust retries
        $machine->state->context->paymentRetries = 3;
        $machine = $machine->send('PAYMENT_FAILED');
        $this->assertEquals('paymentFailed', $machine->state->value);
    }

    public function test_order_cancellation_flow()
    {
        $machine = OrderProcessingMachine::create(['customerId' => 1]);
        
        // Move to fulfillment
        $machine = $machine->send('VALIDATION_SUCCESS');
        $machine = $machine->send('INVENTORY_RESERVED');
        $machine = $machine->send('PAYMENT_SUCCESS');

        // Cancel order
        $machine = $machine->send('CANCEL_ORDER', [
            'reason' => 'Customer requested'
        ]);

        $this->assertEquals('cancelling', $machine->state->value);
    }
}
```

### 3. Integration Testing

Test interactions with external services:

```php
<?php

namespace Tests\Integration;

use Tests\TestCase;
use App\Machines\OrderProcessingMachine;
use App\Services\PaymentService;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Http;

class OrderProcessingIntegrationTest extends TestCase
{
    public function test_payment_service_integration()
    {
        // Mock external payment API
        Http::fake([
            'https://payment-api.example.com/charge' => Http::response([
                'id' => 'ch_1234567890',
                'status' => 'succeeded'
            ])
        ]);

        $machine = OrderProcessingMachine::create([
            'customerId' => 1,
            'total' => 100.00,
            'paymentMethod' => 'card_123'
        ]);

        // Move to payment processing
        $machine = $machine->send('VALIDATION_SUCCESS');
        $machine = $machine->send('INVENTORY_RESERVED');

        // Process payment
        $machine = $machine->send('PAYMENT_SUCCESS');

        // Verify payment was recorded
        $this->assertEquals('ch_1234567890', $machine->state->context->paymentId);
        $this->assertNotNull($machine->state->context->processedAt);
    }

    public function test_inventory_service_integration()
    {
        $this->app->bind(InventoryService::class, function () {
            $mock = \Mockery::mock(InventoryService::class);
            $mock->shouldReceive('reserve')
                ->with('PROD-001', 2, \Mockery::type('string'))
                ->andReturn((object)['successful' => true, 'id' => 'res_123']);
            return $mock;
        });

        $machine = OrderProcessingMachine::create([
            'customerId' => 1,
            'items' => [
                ['sku' => 'PROD-001', 'quantity' => 2, 'price' => 50.00]
            ]
        ]);

        $machine = $machine->send('VALIDATION_SUCCESS');
        $machine = $machine->send('INVENTORY_RESERVED');

        $this->assertNotEmpty($machine->state->context->inventoryReservations);
    }
}
```

## Testing with Fakes

EventMachine provides built-in faking capabilities for behaviors:

### Faking Actions

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Actions\Order\SendOrderConfirmationAction;
use App\Actions\Order\ProcessPaymentAction;
use App\Machines\OrderProcessingMachine;

class OrderActionFakingTest extends TestCase
{
    public function test_order_processing_without_side_effects()
    {
        // Fake actions to prevent side effects
        SendOrderConfirmationAction::fake();
        ProcessPaymentAction::fake();

        $machine = OrderProcessingMachine::create(['customerId' => 1]);
        
        // Process order without actually sending emails or charging cards
        $machine = $machine->send('VALIDATION_SUCCESS');
        $machine = $machine->send('INVENTORY_RESERVED');
        $machine = $machine->send('PAYMENT_SUCCESS');

        // Assert actions were invoked
        SendOrderConfirmationAction::assertInvoked();
        ProcessPaymentAction::assertInvoked();
        
        // Assert they were called with correct parameters
        SendOrderConfirmationAction::assertInvokedWith(function ($context, $event) {
            return $context->customerId === 1;
        });
    }

    public function test_action_invocation_count()
    {
        SendOrderConfirmationAction::fake();

        $machine = OrderProcessingMachine::create(['customerId' => 1]);
        $machine = $machine->send('VALIDATION_SUCCESS');
        $machine = $machine->send('INVENTORY_RESERVED');
        $machine = $machine->send('PAYMENT_SUCCESS');

        // Assert action was called exactly once
        SendOrderConfirmationAction::assertInvokedTimes(1);
    }

    public function test_action_not_invoked()
    {
        SendOrderConfirmationAction::fake();

        $machine = OrderProcessingMachine::create(['customerId' => 1]);
        // Don't trigger payment success

        SendOrderConfirmationAction::assertNotInvoked();
    }
}
```

### Faking Guards

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Guards\Order\CanRetryPaymentGuard;
use App\Machines\OrderProcessingMachine;

class OrderGuardFakingTest extends TestCase
{
    public function test_guard_behavior_override()
    {
        // Override guard to always return false
        CanRetryPaymentGuard::shouldReturn(false);

        $machine = OrderProcessingMachine::create(['customerId' => 1]);
        
        // Move to payment state
        $machine = $machine->send('VALIDATION_SUCCESS');
        $machine = $machine->send('INVENTORY_RESERVED');

        // Even with 0 retries, should go to failed state due to fake
        $machine = $machine->send('PAYMENT_FAILED');
        $this->assertEquals('paymentFailed', $machine->state->value);
    }

    public function test_conditional_guard_faking()
    {
        // Set up conditional guard behavior
        CanRetryPaymentGuard::fake()
            ->shouldReceive('__invoke')
            ->andReturnUsing(function ($context) {
                return $context->paymentRetries < 2; // Custom logic
            });

        $machine = OrderProcessingMachine::create([
            'customerId' => 1,
            'paymentRetries' => 1
        ]);

        $machine = $machine->send('VALIDATION_SUCCESS');
        $machine = $machine->send('INVENTORY_RESERVED');
        $machine = $machine->send('PAYMENT_FAILED');

        $this->assertEquals('paymentRetry', $machine->state->value);
    }
}
```

## Advanced Testing Patterns

### Testing State Persistence

```php
public function test_machine_state_persistence()
{
    $machine = OrderProcessingMachine::create(['customerId' => 1]);
    $originalId = $machine->id;
    
    $machine = $machine->send('VALIDATION_SUCCESS');
    
    // Retrieve from database
    $restoredMachine = OrderProcessingMachine::find($originalId);
    
    $this->assertEquals('reservingInventory', $restoredMachine->state->value);
    $this->assertEquals(1, $restoredMachine->state->context->customerId);
}
```

### Testing Event History

```php
public function test_event_history_is_recorded()
{
    $machine = OrderProcessingMachine::create(['customerId' => 1]);
    
    $machine = $machine->send('VALIDATION_SUCCESS');
    $machine = $machine->send('INVENTORY_RESERVED');
    
    // Check event history
    $events = MachineEvent::where('machine_id', $machine->id)->get();
    
    $this->assertCount(3, $events); // Initial + 2 events
    $this->assertEquals('VALIDATION_SUCCESS', $events[1]->type);
    $this->assertEquals('INVENTORY_RESERVED', $events[2]->type);
}
```

### Testing Error Scenarios

```php
public function test_handles_unexpected_events()
{
    $machine = OrderProcessingMachine::create(['customerId' => 1]);
    
    // Try to send invalid event
    $this->expectException(MachineValidationException::class);
    $machine = $machine->send('INVALID_EVENT');
}

public function test_handles_action_failures()
{
    // Mock an action to throw an exception
    ProcessPaymentAction::fake()
        ->shouldReceive('__invoke')
        ->andThrow(new \Exception('Payment service unavailable'));

    $machine = OrderProcessingMachine::create(['customerId' => 1]);
    
    $machine = $machine->send('VALIDATION_SUCCESS');
    $machine = $machine->send('INVENTORY_RESERVED');
    
    $this->expectException(\Exception::class);
    $machine = $machine->send('PAYMENT_SUCCESS');
}
```

### Testing Context Validation

```php
public function test_context_validation()
{
    $this->expectException(MachineContextValidationException::class);
    
    OrderProcessingMachine::create([
        'customerId' => 'invalid', // Should be integer
        'customerEmail' => 'not-an-email' // Should be valid email
    ]);
}
```

### Testing with Time Travel

```php
public function test_time_sensitive_behavior()
{
    // Travel to specific time
    $this->travelTo(now()->setHour(14)); // 2 PM
    
    $machine = TrafficLightMachine::create(['intersectionId' => 'test-001']);
    
    // Should be in normal operation during day
    $this->assertEquals('operational.red', $machine->state->value);
    
    // Travel to night time
    $this->travelTo(now()->setHour(2)); // 2 AM
    
    $machine = $machine->send('TIMER_EXPIRED');
    
    // Should switch to night mode
    $this->assertEquals('nightMode', $machine->state->value);
}
```

## Test Data Factories

Create factories for consistent test data:

```php
<?php

namespace Tests\Factories;

class OrderContextFactory
{
    public static function make(array $overrides = []): array
    {
        return array_merge([
            'customerId' => fake()->randomNumber(5),
            'customerEmail' => fake()->email(),
            'customerName' => fake()->name(),
            'items' => [
                [
                    'sku' => 'PROD-001',
                    'quantity' => 2,
                    'price' => 50.00,
                    'name' => 'Test Product'
                ]
            ],
            'shippingAddress' => [
                'line1' => fake()->streetAddress(),
                'city' => fake()->city(),
                'state' => fake()->stateAbbr(),
                'zip' => fake()->postcode(),
                'country' => 'US'
            ],
            'paymentMethod' => 'card_' . fake()->randomNumber(8),
            'shippingMethod' => 'standard'
        ], $overrides);
    }

    public static function withPremiumCustomer(): array
    {
        return self::make([
            'customerId' => 100, // Premium customer ID
            'total' => 1000.00
        ]);
    }

    public static function withMultipleItems(int $itemCount = 3): array
    {
        $items = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $items[] = [
                'sku' => 'PROD-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                'quantity' => fake()->numberBetween(1, 5),
                'price' => fake()->randomFloat(2, 10, 100),
                'name' => fake()->words(3, true)
            ];
        }

        return self::make(['items' => $items]);
    }
}
```

Usage:

```php
public function test_order_with_multiple_items()
{
    $context = OrderContextFactory::withMultipleItems(5);
    $machine = OrderProcessingMachine::create($context);
    
    $this->assertCount(5, $machine->state->context->items);
}
```

## Database Testing

### Testing with Transactions

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class OrderDatabaseTest extends TestCase
{
    use RefreshDatabase; // or DatabaseTransactions

    public function test_machine_events_are_stored()
    {
        $machine = OrderProcessingMachine::create(['customerId' => 1]);
        
        // Check initial event is stored
        $this->assertDatabaseHas('machine_events', [
            'machine_id' => $machine->id,
            'type' => '__INITIAL__'
        ]);
        
        $machine = $machine->send('VALIDATION_SUCCESS');
        
        // Check transition event is stored
        $this->assertDatabaseHas('machine_events', [
            'machine_id' => $machine->id,
            'type' => 'VALIDATION_SUCCESS'
        ]);
    }

    public function test_machine_state_restoration()
    {
        $machine = OrderProcessingMachine::create(['customerId' => 1]);
        $originalId = $machine->id;
        
        // Progress through several states
        $machine = $machine->send('VALIDATION_SUCCESS');
        $machine = $machine->send('INVENTORY_RESERVED');
        
        // Clear from memory and restore
        unset($machine);
        $restoredMachine = OrderProcessingMachine::find($originalId);
        
        $this->assertEquals('processingPayment', $restoredMachine->state->value);
        $this->assertEquals(1, $restoredMachine->state->context->customerId);
    }
}
```

## Performance Testing

Test machine performance under load:

```php
public function test_machine_performance_under_load()
{
    $startTime = microtime(true);
    
    for ($i = 0; $i < 100; $i++) {
        $machine = OrderProcessingMachine::create(['customerId' => $i]);
        $machine = $machine->send('VALIDATION_SUCCESS');
        $machine = $machine->send('INVENTORY_RESERVED');
        $machine = $machine->send('PAYMENT_SUCCESS');
    }
    
    $endTime = microtime(true);
    $executionTime = $endTime - $startTime;
    
    // Assert reasonable performance (adjust threshold as needed)
    $this->assertLessThan(5.0, $executionTime, 'Machine processing took too long');
}
```

## Custom Test Assertions

Create custom assertions for better test readability:

```php
<?php

namespace Tests\Concerns;

trait MachineAssertions
{
    protected function assertMachineInState(string $expectedState, $machine): void
    {
        $this->assertEquals($expectedState, $machine->state->value, 
            "Expected machine to be in state '{$expectedState}', but was in '{$machine->state->value}'"
        );
    }

    protected function assertMachineCanTransition(string $event, $machine): void
    {
        $initialState = $machine->state->value;
        
        try {
            $machine->send($event);
            $this->assertTrue(true, "Machine successfully transitioned from '{$initialState}' with event '{$event}'");
        } catch (\Exception $e) {
            $this->fail("Machine could not transition from '{$initialState}' with event '{$event}': " . $e->getMessage());
        }
    }

    protected function assertMachineCannotTransition(string $event, $machine): void
    {
        $initialState = $machine->state->value;
        
        try {
            $machine->send($event);
            $this->fail("Machine should not have been able to transition from '{$initialState}' with event '{$event}'");
        } catch (\Exception $e) {
            $this->assertTrue(true, "Machine correctly rejected transition from '{$initialState}' with event '{$event}'");
        }
    }

    protected function assertContextEquals(array $expected, $machine): void
    {
        $actual = $machine->state->context->toArray();
        
        foreach ($expected as $key => $value) {
            $this->assertEquals($value, $actual[$key], 
                "Context key '{$key}' expected to be '{$value}', but was '{$actual[$key]}'"
            );
        }
    }
}
```

Usage:

```php
class OrderTest extends TestCase
{
    use MachineAssertions;

    public function test_order_transitions()
    {
        $machine = OrderProcessingMachine::create(['customerId' => 1]);
        
        $this->assertMachineInState('validating', $machine);
        $this->assertMachineCanTransition('VALIDATION_SUCCESS', $machine);
        $this->assertMachineCannotTransition('INVALID_EVENT', $machine);
        
        $this->assertContextEquals([
            'customerId' => 1,
            'paymentRetries' => 0
        ], $machine);
    }
}
```

## Best Practices

1. **Test State Transitions**: Focus on testing the business logic, not implementation details
2. **Use Fakes Liberally**: Avoid side effects in tests by faking actions and external services
3. **Test Error Paths**: Don't just test the happy path - test error handling and edge cases
4. **Keep Tests Fast**: Use fakes and mocks to avoid slow external calls
5. **Test Context Changes**: Verify that context is modified correctly during transitions
6. **Use Factories**: Create reusable test data factories for consistency
7. **Test Persistence**: Verify that machine state persists correctly to the database
8. **Test Business Rules**: Ensure guards properly enforce business logic
9. **Document Test Intent**: Use descriptive test names that explain what's being verified
10. **Test Invariants**: Verify that your machine's invariants hold true across all states

## Next Steps

- [Unit Testing](./unit-testing.md) - Deep dive into testing individual components
- [Integration Testing](./integration-testing.md) - Testing with external systems
- [Mocking and Faking](./mocking.md) - Advanced testing techniques