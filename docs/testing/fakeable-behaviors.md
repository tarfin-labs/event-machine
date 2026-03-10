# Fakeable Behaviors

All behaviors (actions, guards, calculators, results) use the `Fakeable` trait, enabling mock/spy patterns during machine execution.

## How It Works

Fakes are registered via `App::bind($class, fn() => $mock)` — NOT `App::instance()`.

::: warning Why not App::instance()?
Instance bindings are silently bypassed when `App::make()` receives explicit parameters. Since the engine always calls `App::make($class, ['eventQueue' => $queue])`, fakes registered via `App::instance()` would never be returned.
:::

## Creating Fakes

<!-- doctest-attr: ignore -->
```php
// Mock — strict, expectations must be set
$mock = ProcessOrderAction::fake();
$mock->shouldReceive('__invoke')->once();

// Spy — permissive, records all calls silently
$spy = ProcessOrderAction::spy();
// ...run machine...
$spy->shouldHaveReceived('__invoke');
```

## Setting Expectations

<!-- doctest-attr: ignore -->
```php
// Expect __invoke to be called (implicitly fakes)
ProcessOrderAction::shouldRun()->once();

// Expect __invoke to NOT be called (implicitly fakes)
SendEmailAction::shouldNotRun();

// Preset return value AND assert called (at least once)
IsValidGuard::shouldReturn(true);

// Preset return value WITHOUT call assertion
// If the guard is never invoked, Mockery will NOT fail
IsValidGuard::mayReturn(false);

// Spy mode — allow all calls, record them
ProcessOrderAction::allowToRun();
```

::: warning shouldReturn() vs mayReturn()
`shouldReturn()` uses `shouldReceive()` which adds an implicit "at least once" expectation. If the behavior is never invoked, `Mockery::close()` will fail. Use `mayReturn()` when you just need a return value but the behavior might not be called.
:::

## Assertions

<!-- doctest-attr: ignore -->
```php
ProcessOrderAction::assertRan();
ProcessOrderAction::assertNotRan();
ProcessOrderAction::assertRanTimes(3);
ProcessOrderAction::assertRanWith(fn($ctx) => $ctx->get('amount') === 100);
```

## Selective Faking — Bus::fake() Style

Only fake what you need — everything else runs with real logic:

<!-- doctest-attr: ignore -->
```php
OrderMachine::test(['order_id' => 1])
    ->faking([SendEmailAction::class])
    ->send('SUBMIT')
    ->assertState('awaiting_payment')
    ->assertBehaviorRan(SendEmailAction::class);
```

::: info faking() uses spy() internally
`TestMachine::faking()` creates spies (not strict mocks) via `spy()`. This means faked behaviors allow all calls silently and record them for assertions. Use `fake()` directly when you need strict expectations.

| Method | Creates | Missing calls | Use when |
|--------|---------|---------------|----------|
| `fake()` | Strict mock | Throws `BadMethodCallException` | You need strict expectations |
| `spy()` | Permissive spy | Silently ignored | You want to verify after the fact |
| `faking([...])` | Spies (via `spy()`) | Silently ignored | Selective faking with TestMachine |
:::

## Fakes Work During Machine::send()

All 5 invocation points respect fakes:

| Invocation Point | Where |
|-----------------|-------|
| Guard evaluation | `TransitionDefinition::getFirstValidTransitionBranch()` |
| Calculator execution | `TransitionDefinition::runCalculators()` |
| Transition actions | `TransitionBranch::runActions()` |
| Exit actions | `StateDefinition::runExitActions()` |
| Entry actions | `StateDefinition::runEntryActions()` |

## Inspection

<!-- doctest-attr: ignore -->
```php
// Check if a behavior is currently faked
ProcessOrderAction::isFaked();    // true/false

// Get the underlying mock/spy instance
$mock = ProcessOrderAction::getFake();
```

## Cleanup

<!-- doctest-attr: ignore -->
```php
// Single behavior
ProcessOrderAction::resetFakes();

// All fakes across ALL behavior classes (can be called from any class)
IncrementAction::resetAllFakes();

// Recommended: auto-cleanup in Pest
afterEach(fn() => IncrementAction::resetAllFakes());
```

::: info resetAllFakes() is global
`resetAllFakes()` clears ALL faked behaviors across all classes, regardless of which class you call it from. The `$fakes` array is shared via `InvokableBehavior`, not per-child-class. You only need one `resetAllFakes()` call in your `afterEach()`.
:::

::: tip Related
See [Isolated Testing](/testing/isolated-testing) for unit-level `runWithState()` tests,
[TestMachine](/testing/test-machine) for the fluent wrapper with `faking()` and `assertBehaviorRan()`,
and [Transitions & Paths](/testing/transitions-and-paths) for guard faking patterns.
:::
