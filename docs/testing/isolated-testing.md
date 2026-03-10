# Isolated Behavior Testing

Unit-level testing — the bottom of the testing pyramid. Test individual behaviors without booting a machine or touching the database.

## State::forTesting()

Create lightweight state objects for isolated tests:

<!-- doctest-attr: ignore -->
```php
// Simple — array context
$state = State::forTesting(['count' => 0, 'items' => []]);

// With ContextManager
$ctx = new ContextManager(['amount' => 100]);
$state = State::forTesting($ctx);

// With EventBehavior (for guards/actions that read event data)
$event = AddValueEvent::forTesting(['payload' => ['value' => 42]]);
$state = State::forTesting(['amount' => 100], currentEventBehavior: $event);

// With StateDefinition (for behaviors that inspect current state)
$state = State::forTesting(['count' => 5], currentStateDefinition: $stateDef);
```

## runWithState()

Uses the **exact same** `injectInvokableBehaviorParameters` DI as the engine. What passes `runWithState()` is guaranteed to receive identical parameters during real execution.

### Guards — returns bool

<!-- doctest-attr: ignore -->
```php
$state = State::forTesting(['count' => 5]);
expect(IsCountPositiveGuard::runWithState($state))->toBeTrue();

$state = State::forTesting(['count' => 0]);
expect(IsCountPositiveGuard::runWithState($state))->toBeFalse();
```

### Actions — modifies context

<!-- doctest-attr: ignore -->
```php
$state = State::forTesting(
    new TrafficLightsContext(count: 0, modelA: new \Spatie\LaravelData\Optional())
);
IncrementAction::runWithState($state);
expect($state->context->count)->toBe(1);
```

### Calculators — with arguments

<!-- doctest-attr: ignore -->
```php
$state = State::forTesting(['count' => 10]);
DoubleCountCalculator::runWithState($state);
expect($state->context->get('result'))->toBe(20);
```

### With EventBehavior

<!-- doctest-attr: ignore -->
```php
$state = State::forTesting(
    new TrafficLightsContext(count: 10, modelA: new \Spatie\LaravelData\Optional())
);
$event = AddValueEvent::forTesting(['payload' => ['value' => 5]]);

AddValueAction::runWithState($state, eventBehavior: $event);
expect($state->context->count)->toBe(15);
```

## EventBehavior::forTesting()

Create event instances with sensible defaults:

<!-- doctest-attr: ignore -->
```php
// Base — sensible defaults
$event = IncreaseEvent::forTesting();
expect($event->type)->toBe('INCREASE');
expect($event->payload)->toBe([]);

// Override specific fields
$event = AddValueEvent::forTesting(['payload' => ['value' => 42]]);
expect($event->payload)->toBe(['value' => 42]);

// Use with runWithState
$state = State::forTesting(['count' => 10]);
AddValueAction::runWithState($state, eventBehavior: $event);
```

## When to Use Which

| Test Type | Method | Best For |
|-----------|--------|----------|
| Unit | `runWithState()` | Single behavior logic, fast, no DB |
| Integration | `Machine::test()` | Transition flow, guard interaction |
| E2E | `Machine::create()` + `send()` | Full persistence, real DB |

::: tip Related
See [Fakeable Behaviors](/testing/fakeable-behaviors) for mocking during execution,
[Constructor DI](/testing/constructor-di) for service injection testing,
[TestMachine](/testing/test-machine) for the fluent machine-level wrapper,
and [Migration Guide](/testing/migration-guide) for upgrading from legacy test patterns.
:::
