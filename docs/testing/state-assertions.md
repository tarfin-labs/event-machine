# State Assertions

Complete guide to asserting machine state in your tests.

## State Matching

### `matches()`

Check if machine is in a specific state:

<!-- doctest-attr: ignore -->
```php
$machine = OrderMachine::create();

// Simple state check
expect($machine->state->matches('pending'))->toBeTrue();
expect($machine->state->matches('processing'))->toBeFalse();

// Nested state check
expect($machine->state->matches('checkout.payment'))->toBeTrue();
```

### State Value

Check the full state value array:

<!-- doctest-attr: ignore -->
```php
$machine = OrderMachine::create();

// State value includes full path
expect($machine->state->value)->toBe(['order.pending']);

// After transition
$machine->send(['type' => 'SUBMIT']);
expect($machine->state->value)->toBe(['order.processing']);
```

### State Definition

Access the current state definition:

<!-- doctest-attr: ignore -->
```php
$stateDef = $machine->state->currentStateDefinition;

expect($stateDef->id)->toBe('order.processing');
expect($stateDef->key)->toBe('processing');
expect($stateDef->type)->toBe(StateDefinitionType::ATOMIC);
expect($stateDef->description)->toBe('Order is being processed');
```

## Context Assertions

### Direct Access

<!-- doctest-attr: ignore -->
```php
$machine = OrderMachine::create();
$machine->send(['type' => 'ADD_ITEM', 'payload' => ['item' => $item]]);

// Access context properties
expect($machine->state->context->items)->toHaveCount(1);
expect($machine->state->context->total)->toBe(100);
expect($machine->state->context->orderId)->not->toBeNull();
```

### Using `get()`

<!-- doctest-attr: ignore -->
```php
expect($machine->state->context->get('items'))->toHaveCount(1);
expect($machine->state->context->get('user.email'))->toBe('test@example.com');
```

### Using `has()`

<!-- doctest-attr: ignore -->
```php
expect($machine->state->context->has('orderId'))->toBeTrue();
expect($machine->state->context->has('deletedAt'))->toBeFalse();

// With type check
expect($machine->state->context->has('total', 'numeric'))->toBeTrue();
```

### Custom Context Class

<!-- doctest-attr: ignore -->
```php
// With typed context
expect($machine->state->context)->toBeInstanceOf(OrderContext::class);
expect($machine->state->context->isEligible())->toBeTrue();
expect($machine->state->context->calculateTotal())->toBe(150.00);
```

## History Assertions

### Event Count

<!-- doctest-attr: ignore -->
```php
$machine = OrderMachine::create();
$machine->send(['type' => 'SUBMIT']);
$machine->send(['type' => 'APPROVE']);

// Total events (including internal)
expect($machine->state->history)->toHaveCount(10);

// External events only
$external = $machine->state->history->where('source', 'external');
expect($external)->toHaveCount(2);
```

### Event Types

<!-- doctest-attr: ignore -->
```php
$types = $machine->state->history->pluck('type')->toArray();

expect($types)->toContain('SUBMIT');
expect($types)->toContain('APPROVE');
expect($types)->toContain('order.machine.start');
```

### Event Order

<!-- doctest-attr: ignore -->
```php
$external = $machine->state->history
    ->where('source', 'external')
    ->values();

expect($external[0]->type)->toBe('SUBMIT');
expect($external[1]->type)->toBe('APPROVE');
```

### Event Payload

<!-- doctest-attr: ignore -->
```php
$submitEvent = $machine->state->history
    ->firstWhere('type', 'SUBMIT');

expect($submitEvent->payload)->toBe(['express' => true]);
```

### First and Last Events

<!-- doctest-attr: ignore -->
```php
expect($machine->state->history->first()->type)->toBe('order.machine.start');
expect($machine->state->history->last()->type)->toBe('order.state.approved.enter');
```

## Transition Testing

### Guard Pass/Fail

<!-- doctest-attr: ignore -->
```php
it('blocks transition when guard fails', function () {
    $machine = OrderMachine::create();
    $machine->state->context->items = [];

    // Transition blocked by guard
    $machine->send(['type' => 'SUBMIT']);

    // Still in original state
    expect($machine->state->matches('pending'))->toBeTrue();
});

it('allows transition when guard passes', function () {
    $machine = OrderMachine::create();
    $machine->state->context->items = [['id' => 1]];

    $machine->send(['type' => 'SUBMIT']);

    expect($machine->state->matches('processing'))->toBeTrue();
});
```

### Invalid Event

<!-- doctest-attr: ignore -->
```php
it('ignores invalid events', function () {
    $machine = OrderMachine::create();

    // COMPLETE not valid from pending
    $machine->send(['type' => 'COMPLETE']);

    // No change
    expect($machine->state->matches('pending'))->toBeTrue();
});
```

### Multi-Step Transitions

<!-- doctest-attr: ignore -->
```php
it('completes full order flow', function () {
    $machine = OrderMachine::create();
    $machine->state->context->items = [['id' => 1, 'price' => 100]];

    // Track states through flow
    $states = ['pending'];

    $machine->send(['type' => 'SUBMIT']);
    $states[] = $machine->state->currentStateDefinition->key;

    $machine->send(['type' => 'PAY']);
    $states[] = $machine->state->currentStateDefinition->key;

    $machine->send(['type' => 'SHIP']);
    $states[] = $machine->state->currentStateDefinition->key;

    expect($states)->toBe(['pending', 'processing', 'paid', 'shipped']);
});
```

## Validation Assertions

### Validation Exception

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;

it('throws on invalid input', function () {
    $machine = OrderMachine::create();

    expect(fn() => $machine->send([
        'type' => 'SET_AMOUNT',
        'payload' => ['amount' => -100],
    ]))->toThrow(MachineValidationException::class);
});
```

### Error Message

<!-- doctest-attr: ignore -->
```php
it('provides helpful error message', function () {
    $machine = OrderMachine::create();

    try {
        $machine->send([
            'type' => 'SET_AMOUNT',
            'payload' => ['amount' => -100],
        ]);
        $this->fail('Expected exception');
    } catch (MachineValidationException $e) {
        expect($e->getMessage())->toContain('Amount must be positive');
    }
});
```

## Final State Assertions

### Check Final State

<!-- doctest-attr: ignore -->
```php
it('reaches final state', function () {
    $machine = OrderMachine::create();
    // ... send events ...

    $machine->send(['type' => 'COMPLETE']);

    expect($machine->state->currentStateDefinition->type)
        ->toBe(StateDefinitionType::FINAL);
});
```

### Check Result

<!-- doctest-attr: ignore -->
```php
it('returns correct result', function () {
    $machine = OrderMachine::create();
    // ... complete order flow ...

    $result = $machine->result();

    expect($result)->toHaveKeys(['orderId', 'total', 'status']);
    expect($result['status'])->toBe('completed');
});
```

## Complex Assertions

### Multiple Conditions

<!-- doctest-attr: ignore -->
```php
it('updates order correctly', function () {
    $machine = OrderMachine::create();

    $machine->send([
        'type' => 'ADD_ITEM',
        'payload' => ['item' => ['id' => 1, 'price' => 50, 'quantity' => 2]],
    ]);

    $ctx = $machine->state->context;

    expect($ctx)
        ->items->toHaveCount(1)
        ->total->toBe(100)
        ->itemCount->toBe(2);
});
```

### State and Context Together

<!-- doctest-attr: ignore -->
```php
it('processes payment correctly', function () {
    $machine = OrderMachine::create();
    // ... setup ...

    $machine->send(['type' => 'PAY', 'payload' => ['method' => 'card']]);

    expect($machine->state->matches('paid'))->toBeTrue();
    expect($machine->state->context->paymentMethod)->toBe('card');
    expect($machine->state->context->paidAt)->not->toBeNull();
});
```

### Nested State Assertions

<!-- doctest-attr: ignore -->
```php
it('handles checkout flow', function () {
    $machine = CheckoutMachine::create();

    // Starts in checkout.shipping
    expect($machine->state->matches('checkout.shipping'))->toBeTrue();

    $machine->send(['type' => 'CONTINUE']);
    expect($machine->state->matches('checkout.payment'))->toBeTrue();

    $machine->send(['type' => 'CONTINUE']);
    expect($machine->state->matches('checkout.review'))->toBeTrue();

    $machine->send(['type' => 'CONFIRM']);
    expect($machine->state->matches('completed'))->toBeTrue();
});
```

## Helper Functions

Create test helpers for common assertions:

<!-- doctest-attr: ignore -->
```php
// tests/Helpers.php
function expectState($machine, string $state): void
{
    expect($machine->state->matches($state))->toBeTrue(
        "Expected state '{$state}', got '{$machine->state->currentStateDefinition->key}'"
    );
}

function expectContext($machine, array $values): void
{
    foreach ($values as $key => $value) {
        expect($machine->state->context->get($key))->toBe($value);
    }
}

// Usage
expectState($machine, 'processing');
expectContext($machine, [
    'orderId' => 'order-123',
    'total' => 100,
]);
```

## Best Practices

### 1. Test State Transitions

<!-- doctest-attr: ignore -->
```php
// Always verify state after sending events
$machine->send(['type' => 'SUBMIT']);
expect($machine->state->matches('submitted'))->toBeTrue();
```

### 2. Test Context Changes

<!-- doctest-attr: ignore -->
```php
// Verify context is updated correctly
$before = $machine->state->context->count;
$machine->send(['type' => 'INCREMENT']);
expect($machine->state->context->count)->toBe($before + 1);
```

### 3. Test Guard Behavior

<!-- doctest-attr: ignore -->
```php
// Test both pass and fail cases
it('guards prevent invalid transitions', function () { ... });
it('guards allow valid transitions', function () { ... });
```

### 4. Test Error Cases

<!-- doctest-attr: ignore -->
```php
// Verify validation errors
expect(fn() => $machine->send([...]))->toThrow(MachineValidationException::class);
```
