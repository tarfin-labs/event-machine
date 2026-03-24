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
    new TrafficLightsContext(count: 0)
);
IncrementAction::runWithState($state);
expect($state->context->count)->toBe(1);
```

### Actions — asserting raised events

Actions that call `$this->raise()` push events onto an internal queue. After `runWithState()`, use static assertions to verify which events were raised:

<!-- doctest-attr: no_run -->
```php
CheckProtocolAction::runWithState($state);

CheckProtocolAction::assertRaised(ProtocolUndecidedEvent::class);
CheckProtocolAction::assertNotRaised(ProtocolRejectedEvent::class);
CheckProtocolAction::assertRaisedCount(1);
```

Supports both FQCN and event type strings:

<!-- doctest-attr: ignore -->
```php
CheckProtocolAction::assertRaised('PROTOCOL_UNDECIDED');
CheckProtocolAction::assertRaised(ProtocolUndecidedEvent::class);
```

For actions that should NOT raise any events:

<!-- doctest-attr: no_run -->
```php
StoreDataAction::runWithState($state);
StoreDataAction::assertNothingRaised();
```

Multiple raised events — assert each individually:

<!-- doctest-attr: no_run -->
```php
MultiStepAction::runWithState($state);
MultiStepAction::assertRaised('STEP_ONE_DONE');
MultiStepAction::assertRaised('STEP_TWO_DONE');
MultiStepAction::assertRaisedCount(2);
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
    new TrafficLightsContext(count: 10)
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

## EventBuilder

When events have complex payloads — many fields, faker-generated values, database-seeded relationships — `forTesting()` becomes verbose. `EventBuilder` provides composable, reusable test data builders with the same fluent API as Laravel's model factories.

### When to Use Which

| Scenario | Tool | Example |
|----------|------|---------|
| Simple event, payload doesn't matter | `forTesting()` | `MyEvent::forTesting()` |
| Simple event, a few field overrides | `forTesting()` | `MyEvent::forTesting(['payload' => ['key' => 'val']])` |
| Complex payload, faker, DB seeding | `EventBuilder` | `MyEvent::builder()->withX()->make()` |
| Validation testing with raw array | `EventBuilder` | `MyEvent::builder()->raw()` → `validateAndCreate()` |

### Naming

Builder class names derive from the event class: `{EventClassName}Builder`.

| Event Class | Builder Class |
|-------------|---------------|
| `OrderSubmittedEvent` | `OrderSubmittedEventBuilder` |
| `ApplicationStartedEvent` | `ApplicationStartedEventBuilder` |

Builder methods that add state follow `with{Description}` and return `static`:

| Method | Description |
|--------|-------------|
| `withOrderItems(int $count)` | Add order items to payload |
| `withFarmerPaymentDate(?CarbonImmutable $date)` | Set farmer payment date |
| `withInvalidAttribute()` | Set deliberately invalid data for validation testing |

### Creating a Builder

Extend `EventBuilder` and implement `eventClass()`. Override `definition()` only when you need faker-generated defaults — omit it for the base defaults (type from `getType()`, empty payload, version 1).

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Testing\EventBuilder;

class OrderSubmittedEventBuilder extends EventBuilder
{
    protected function eventClass(): string
    {
        return OrderSubmittedEvent::class;
    }

    protected function definition(): array
    {
        return [
            'type'    => OrderSubmittedEvent::getType(),
            'payload' => [
                'customer_id' => $this->faker->uuid(),
                'amount'      => $this->faker->numberBetween(100, 10000),
                'currency'    => 'TRY',
            ],
            'version' => 1,
        ];
    }

    public function withAmount(int $amount): static
    {
        return $this->state(['payload' => ['amount' => $amount]]);
    }

    public function withItems(int $count): static
    {
        return $this->state(function (array $attrs) use ($count) {
            $items = [];
            foreach (range(1, $count) as $i) {
                $items[] = [
                    'product_id' => Product::factory()->create()->id,
                    'quantity'   => random_int(1, 10),
                ];
            }
            $attrs['payload']['items'] = $items;
            return $attrs;
        });
    }
}
```

### Connecting Event to Builder — HasBuilder

Add the `HasBuilder` trait to your event class so you can call `Event::builder()` directly. This follows the same pattern as Laravel's `HasFactory`.

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Testing\HasBuilder;

/**
 * @use HasBuilder<OrderSubmittedEventBuilder>
 */
class OrderSubmittedEvent extends EventBehavior
{
    use HasBuilder;

    public static function getType(): string
    {
        return 'ORDER_SUBMITTED';
    }
}
```

The `@use HasBuilder<OrderSubmittedEventBuilder>` annotation gives your IDE full autocomplete on the builder methods.

**Convention:** `HasBuilder` looks for `{EventClass}Builder` in the same namespace. If your builder lives elsewhere, override `resolveBuilderClass()`:

<!-- doctest-attr: ignore -->
```php
class OrderSubmittedEvent extends EventBehavior
{
    use HasBuilder;

    protected static function resolveBuilderClass(): string
    {
        return \Database\Factories\OrderSubmittedEventBuilder::class;
    }
}
```

### Usage

<!-- doctest-attr: ignore -->
```php
// Via event class (recommended — IDE autocomplete)
$event = OrderSubmittedEvent::builder()
    ->withAmount(5000)
    ->withItems(3)
    ->make();

// Via builder directly (also works)
$event = OrderSubmittedEventBuilder::new()
    ->withAmount(5000)
    ->withItems(3)
    ->make();

// Raw array for validation testing
$raw = OrderSubmittedEvent::builder()->withAmount(-1)->raw();
expect(fn () => OrderSubmittedEvent::validateAndCreate($raw))
    ->toThrow(ValidationException::class);

// Immutable — reuse a base builder
$base   = OrderSubmittedEvent::builder()->withItems(3);
$eventA = $base->withAmount(1000)->make();
$eventB = $base->withAmount(5000)->make();
```

::: tip Minimal Builder
If your event has a simple payload and you only need builder methods (not faker defaults), skip `definition()` entirely:

<!-- doctest-attr: ignore -->
```php
class MyEventBuilder extends EventBuilder
{
    protected function eventClass(): string { return MyEvent::class; }

    public function withAmount(int $amount): static
    {
        return $this->state(['payload' => ['amount' => $amount]]);
    }
}
```
:::

### API Reference

**EventBuilder:**

| Method | Returns | Description |
|--------|---------|-------------|
| `::new()` | `static` | Static constructor — creates fresh builder instance |
| `state(Closure\|array)` | `static` | Add state mutation (returns immutable clone) |
| `make(array $overrides)` | `EventBehavior` | Build event instance — overrides take final precedence |
| `raw(array $overrides)` | `array` | Raw attribute array — for `validateAndCreate()` testing |

**HasBuilder (trait on EventBehavior):**

| Method | Returns | Description |
|--------|---------|-------------|
| `::builder()` | `EventBuilder` (concrete via `@template`) | Resolve and return builder instance |
| `::resolveBuilderClass()` | `string` | Override for custom builder location |

::: warning make() skips validation
`make()` calls `EventBehavior::from()` directly — same as `forTesting()`. If you need to test validation rules, use `raw()` → `validateAndCreate()`. Note that `validateAndCreate()` throws `Illuminate\Validation\ValidationException`, not `MachineEventValidationException`.
:::

::: warning Closure vs array state behavior
Array states are **additive** — `array_replace_recursive` preserves sibling keys. Closure states **replace** the entire array — you must return all keys you want to keep. Use array states for simple overrides and closures only when you need computed values or cross-key logic.
:::

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
| Raised events | `assertRaised()` / `assertNothingRaised()` | Unit-level raise testing, no machine needed |
| Integration | `Machine::test()` | Transition flow, guard interaction |
| E2E | `Machine::create()` + `send()` | Full persistence, real DB |

::: tip Related
See [Fakeable Behaviors](/testing/fakeable-behaviors) for mocking during execution,
[Constructor DI](/testing/constructor-di) for service injection testing,
[TestMachine](/testing/test-machine) for the fluent machine-level wrapper,
and [Migration Patterns](/getting-started/upgrading#testing-migration-patterns) for upgrading from legacy test patterns.
:::
