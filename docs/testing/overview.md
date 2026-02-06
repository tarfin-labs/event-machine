# Testing Overview

EventMachine provides full testing support through the Fakeable trait, state assertions, and database testing utilities.

## Test Setup

### Pest / PHPUnit Configuration

```php
// tests/TestCase.php
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset all fakes between tests
        \Tarfinlabs\EventMachine\Facades\EventMachine::resetAllFakes();
    }
}
```

### In-Memory Database

For fast tests, use SQLite in-memory:

```xml
<!-- phpunit.xml -->
<php>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
</php>
```

## Testing Approaches

### 1. Definition Testing (Stateless)

Test state machine logic without persistence:

```php
it('transitions from pending to processing', function () {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'pending',
            'states' => [
                'pending' => [
                    'on' => ['SUBMIT' => 'processing'],
                ],
                'processing' => [],
            ],
        ],
    );

    $state = $machine->getInitialState();
    expect($state->matches('pending'))->toBeTrue();

    $newState = $machine->transition(['type' => 'SUBMIT']);
    expect($newState->matches('processing'))->toBeTrue();
});
```

### 2. Machine Testing (Stateful)

Test with persistence:

```php
it('persists events to database', function () {
    $machine = OrderMachine::create();

    $machine->send(['type' => 'SUBMIT']);

    expect($machine->state->matches('processing'))->toBeTrue();

    $this->assertDatabaseHas('machine_events', [
        'type' => 'SUBMIT',
        'machine_id' => 'order',
    ]);
});
```

### 3. Faked Behavior Testing

Test with mocked behaviors:

```php
it('uses faked action', function () {
    ProcessOrderAction::fake();

    ProcessOrderAction::shouldRun()
        ->once()
        ->andReturnUsing(fn($ctx) => $ctx->processed = true);

    $machine = OrderMachine::create();
    $machine->send(['type' => 'PROCESS']);

    ProcessOrderAction::assertRan();
});
```

## Basic Assertions

### State Assertions

```php
// Check current state
expect($machine->state->matches('processing'))->toBeTrue();
expect($machine->state->matches('pending'))->toBeFalse();

// Check state value
expect($machine->state->value)->toBe(['order.processing']);

// Check state definition
expect($machine->state->currentStateDefinition->key)->toBe('processing');
```

### Context Assertions

```php
// Check context values
expect($machine->state->context->orderId)->toBe('order-123');
expect($machine->state->context->total)->toBeGreaterThan(0);
expect($machine->state->context->items)->toHaveCount(3);
```

### History Assertions

```php
// Check event history
expect($machine->state->history)->toHaveCount(5);
expect($machine->state->history->pluck('type'))->toContain('SUBMIT');

// Check external events only
$external = $machine->state->history->where('source', 'external');
expect($external)->toHaveCount(2);
```

### Database Assertions

```php
// Check events in database
$this->assertDatabaseHas('machine_events', [
    'type' => 'SUBMIT',
    'source' => 'external',
]);

// Check event count
$this->assertDatabaseCount('machine_events', 10);
```

## Testing Guards

```php
it('blocks transition when guard fails', function () {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'idle',
            'context' => ['count' => 0],
            'states' => [
                'idle' => [
                    'on' => [
                        'SUBMIT' => [
                            'target' => 'submitted',
                            'guards' => 'hasPositiveCount',
                        ],
                    ],
                ],
                'submitted' => [],
            ],
        ],
        behavior: [
            'guards' => [
                'hasPositiveCount' => fn($ctx) => $ctx->count > 0,
            ],
        ],
    );

    // Guard fails - no transition
    $state = $machine->transition(['type' => 'SUBMIT']);
    expect($state->matches('idle'))->toBeTrue();

    // Update context and try again
    $state->context->count = 5;
    $newState = $machine->transition(['type' => 'SUBMIT'], $state);
    expect($newState->matches('submitted'))->toBeTrue();
});
```

## Testing Validation Guards

```php
it('throws validation exception with message', function () {
    $machine = OrderMachine::create();

    expect(fn() => $machine->send([
        'type' => 'SUBMIT',
        'payload' => ['amount' => -100],
    ]))->toThrow(
        MachineValidationException::class,
        'Amount must be positive'
    );
});
```

## Testing Actions

```php
it('executes action and updates context', function () {
    $machine = CounterMachine::create();

    $machine->send(['type' => 'INCREMENT']);
    expect($machine->state->context->count)->toBe(1);

    $machine->send(['type' => 'INCREMENT']);
    expect($machine->state->context->count)->toBe(2);
});
```

## Testing Event Payloads

```php
it('uses event payload in action', function () {
    $machine = CalculatorMachine::create();

    $machine->send([
        'type' => 'ADD',
        'payload' => ['value' => 10],
    ]);

    expect($machine->state->context->result)->toBe(10);

    $machine->send([
        'type' => 'ADD',
        'payload' => ['value' => 5],
    ]);

    expect($machine->state->context->result)->toBe(15);
});
```

## Testing State Restoration

```php
it('restores state from root event id', function () {
    $machine = OrderMachine::create();

    $machine->send(['type' => 'SUBMIT']);
    $machine->send(['type' => 'APPROVE']);

    $rootId = $machine->state->history->first()->root_event_id;
    $originalState = $machine->state;

    // Restore from root event ID
    $restored = OrderMachine::create(state: $rootId);

    expect($restored->state->value)->toEqual($originalState->value);
    expect($restored->state->context->toArray())
        ->toEqual($originalState->context->toArray());
});
```

## Test Helpers

### Reset Fakes

```php
afterEach(function () {
    ProcessOrderAction::resetFakes();
    ValidateOrderGuard::resetFakes();
    // Or reset all at once
    EventMachine::resetAllFakes();
});
```

### `ResolvesBehaviors` Trait

Access behavior definitions directly for testing and debugging:

```php
use Tarfinlabs\EventMachine\Traits\ResolvesBehaviors;

class OrderMachine extends Machine
{
    use ResolvesBehaviors;
    // ...
}
```

Available methods:

```php
// Get any behavior by path
$behavior = OrderMachine::getBehavior('guards.hasItems');
$behavior = OrderMachine::getBehavior('actions.processOrder');

// Shorthand methods
$guard = OrderMachine::getGuard('hasItems');
$action = OrderMachine::getAction('processOrder');
$calculator = OrderMachine::getCalculator('calculateTotal');
$event = OrderMachine::getEvent('SUBMIT');
```

Useful for testing behaviors in isolation:

```php
it('guard checks items correctly', function () {
    $guard = OrderMachine::getGuard('hasItems');

    $context = new OrderContext(items: []);
    expect($guard($context))->toBeFalse();

    $context = new OrderContext(items: [['id' => 1]]);
    expect($guard($context))->toBeTrue();
});
```

::: tip
The `getBehavior()` method throws `BehaviorNotFoundException` if the behavior doesn't exist, making it easy to catch configuration errors in tests.
:::

### Create Machine with Context

```php
it('starts with custom context', function () {
    $machine = MachineDefinition::define(
        config: [
            'initial' => 'active',
            'context' => ['count' => 100],
            'states' => [...],
        ],
    );

    $state = $machine->getInitialState();
    expect($state->context->count)->toBe(100);
});
```

## Best Practices

### 1. Test State Transitions

```php
it('follows expected state flow', function () {
    $machine = OrderMachine::create();

    expect($machine->state->matches('pending'))->toBeTrue();

    $machine->send(['type' => 'SUBMIT']);
    expect($machine->state->matches('processing'))->toBeTrue();

    $machine->send(['type' => 'COMPLETE']);
    expect($machine->state->matches('completed'))->toBeTrue();
});
```

### 2. Test Guard Conditions

```php
it('requires valid data to proceed', function () {
    $machine = OrderMachine::create();

    // Invalid - no items
    $machine->send(['type' => 'SUBMIT']);
    expect($machine->state->matches('pending'))->toBeTrue();

    // Add items
    $machine->state->context->items = [['id' => 1]];

    // Now it works
    $machine->send(['type' => 'SUBMIT']);
    expect($machine->state->matches('processing'))->toBeTrue();
});
```

### 3. Test Context Updates

```php
it('updates context correctly', function () {
    $machine = CartMachine::create();

    $machine->send([
        'type' => 'ADD_ITEM',
        'payload' => ['item' => ['id' => 1, 'price' => 100]],
    ]);

    expect($machine->state->context->items)->toHaveCount(1);
    expect($machine->state->context->total)->toBe(100);
});
```

### 4. Test Error Cases

```php
it('handles invalid transitions gracefully', function () {
    $machine = OrderMachine::create();

    // COMPLETE is not valid from pending state
    $machine->send(['type' => 'COMPLETE']);

    // Should still be in pending (no transition occurred)
    expect($machine->state->matches('pending'))->toBeTrue();
});
```
