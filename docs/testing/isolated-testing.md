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

Guards return `true` to allow a transition or `false` to block it. Test them by creating a state with the context your guard depends on.

<!-- doctest-attr: ignore -->
```php
$state = State::forTesting(['count' => 5]);
expect(IsCountPositiveGuard::runWithState($state))->toBeTrue();

$state = State::forTesting(['count' => 0]);
expect(IsCountPositiveGuard::runWithState($state))->toBeFalse();
```

### Actions — modifies context

Actions perform side effects, typically modifying context values. Since they return void, assert on the context changes after calling `runWithState()`.

<!-- doctest-attr: ignore -->
```php
$state = State::forTesting(
    new TrafficLightsContext(count: 0, modelA: new \Spatie\LaravelData\Optional())
);
IncrementAction::runWithState($state);
expect($state->context->count)->toBe(1);
```

### Calculators — with arguments

Calculators run before guards to compute derived values. Unlike actions, they only modify context — no side effects. The third parameter passes colon-separated arguments from the machine definition (e.g., `'myCalculator:7'` passes `['7']`).

<!-- doctest-attr: ignore -->
```php
$state = State::forTesting(['count' => 10]);
DoubleCountCalculator::runWithState($state);
expect($state->context->get('result'))->toBe(20);
```

### With EventBehavior

When an action reads event payload (e.g., values submitted by the user), pass an `EventDefinition` as the second parameter to simulate the event data.

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

`EventBehavior` subclasses often have validation rules and required fields. `forTesting()` creates a valid instance with sensible defaults, so you don't have to manually construct the full event structure.

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

## Child Machine Event Factories

When testing guards or actions that handle `@done`/`@fail` events, use the child event factories to avoid boilerplate:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Behavior\ChildMachineDoneEvent;
use Tarfinlabs\EventMachine\Behavior\ChildMachineFailEvent;

// Only provide the data you care about — identity fields are defaulted
$event = ChildMachineDoneEvent::forTesting(['result' => ['statusCode' => 3]]);
$state = State::forTesting(['attempt_count' => 2], currentEventBehavior: $event);
expect(IsStatusSuccessGuard::runWithState($state))->toBeTrue();

// Fail event for error-handling guards
$event = ChildMachineFailEvent::forTesting(['error_message' => 'Gateway timeout']);
$state = State::forTesting([], currentEventBehavior: $event);
expect(IsRetryableErrorGuard::runWithState($state))->toBeTrue();

// With final state (for @done.{state} routing guards)
$event = ChildMachineDoneEvent::forTesting([
    'result'      => ['status' => 'ok'],
    'final_state' => 'approved',
]);

// Zero config — all defaults (machine_id: 'test', machine_class: 'TestMachine')
$event = ChildMachineDoneEvent::forTesting();
$event = ChildMachineFailEvent::forTesting();
```

::: tip Use concrete event type-hints
When a guard handles `@done` events, type-hint `ChildMachineDoneEvent $event` — not `EventBehavior $event`. The injection system (`injectInvokableBehaviorParameters`) resolves the correct subclass automatically. Concrete type-hints give you IDE autocompletion, PHPStan safety, and clear method availability (`result()`, `output()`, `finalState()`).
:::

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
and [Migration Patterns](/getting-started/upgrading#testing-migration-patterns) for upgrading from legacy test patterns.
:::
