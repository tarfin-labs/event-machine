# Fakeable Behaviors

All behaviors (actions, guards, calculators, results) use the `Fakeable` trait, enabling mock/spy patterns during machine execution.

## How It Works

Fakes are registered via `App::bind($class, fn() => $mock)` — NOT `App::instance()`.

::: warning Why not App::instance()?
Instance bindings are silently bypassed when `App::make()` receives explicit parameters. Since the engine always calls `App::make($class, ['eventQueue' => $queue])`, fakes registered via `App::instance()` would never be returned.
:::

## Creating Fakes

Use `fake()` when you need strict control — the mock will fail if expected calls are never made. Use `spy()` when you want permissive recording — the spy silently captures all invocations and lets you assert afterward without requiring any calls up front.

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

`shouldRun()` and `shouldReturn()` both add an implicit "at least once" expectation on `__invoke` — if the behavior is never called during the test, Mockery will fail at teardown. `mayReturn()` only presets the return value without registering any call expectation, so the test passes cleanly even if the behavior is skipped.

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

These low-level assertions are intended for isolated unit tests where you invoke a behavior directly — outside of `Machine::test()`. When testing through the full machine pipeline, prefer `TestMachine::assertBehaviorRan()` instead, which integrates with the fluent chain and handles spy setup automatically.

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
OrderMachine::test(['orderId' => 1])
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

::: warning Guards need shouldReturn() after faking()
Spies return `null` by default. For guards, `null !== false` means the guard **silently passes** — the engine only blocks on an explicit `false` return. Always call `shouldReturn()` after faking a guard:

```php ignore
->faking([IsValidGuard::class])
// Without this, the guard always passes (null is not false):
IsValidGuard::shouldReturn(false);
```
:::

## Fakes Work During Machine::send()

This matters because behaviors are invoked at five distinct points in the execution pipeline — not just transition actions. Fakes registered before `send()` are correctly intercepted regardless of whether the behavior is called as a guard, calculator, transition action, exit action, or entry action.

All 5 invocation points respect fakes:

| Invocation Point | Where |
|-----------------|-------|
| Guard evaluation | `TransitionDefinition::getFirstValidTransitionBranch()` |
| Calculator execution | `TransitionDefinition::runCalculators()` |
| Transition actions | `TransitionBranch::runActions()` |
| Exit actions | `StateDefinition::runExitActions()` |
| Entry actions | `StateDefinition::runEntryActions()` |

## Inline Behavior Faking

Inline closures (defined in the `behavior` array) can be faked just like class-based behaviors. The `InlineBehaviorFake` class provides a static registry that intercepts inline behaviors at invocation time — the original closure's reflection is still used for parameter injection, only the execution is replaced.

### Via TestMachine (Recommended)

<!-- doctest-attr: ignore -->
```php
// Fake inline action (skip original, record calls)
OrderMachine::test()
    ->faking(['sendEmailAction'])
    ->send('SUBMIT')
    ->assertBehaviorRan('sendEmailAction');

// Fake inline guard with return value (key-value syntax)
OrderMachine::test()
    ->faking(['isValidGuard' => false])
    ->assertGuarded('SUBMIT');

// Custom replacement closure
OrderMachine::test()
    ->faking(['calculateTaxAction' => fn(ContextManager $ctx) => $ctx->set('tax', 0)])
    ->send('SUBMIT')
    ->assertContext('tax', 0);

// Mix class-based and inline in a single faking() call
OrderMachine::test()
    ->faking([
        SendEmailAction::class,         // class-based → spy
        'broadcastAction',               // inline → no-op fake
        'isValidGuard' => true,          // inline → return value
    ]);
```

### Direct API

For advanced use cases outside TestMachine:

<!-- doctest-attr: ignore -->
```php
// Spy mode: record calls, still run original
InlineBehaviorFake::spy('broadcastAction');

// Fake mode: skip original, run no-op
InlineBehaviorFake::fake('broadcastAction');

// Fake with specific return value (guards)
InlineBehaviorFake::shouldReturn('isValidGuard', false);

// Assertions
InlineBehaviorFake::assertRan('broadcastAction');
InlineBehaviorFake::assertNotRan('someAction');
InlineBehaviorFake::assertRanTimes('broadcastAction', 2);
InlineBehaviorFake::assertRanWith('storeAction', fn(array $params) => $params[0]->get('stored') === true);
```

::: warning Guards need explicit return value when faked
When faked without a return value (e.g., `faking(['myGuard'])`), the default replacement returns `null`. Since `null !== false`, the guard **silently passes**. Always use the key-value syntax for guards:

```php ignore
// Wrong — guard passes (null is not false)
->faking(['myGuard'])

// Correct — guard blocks
->faking(['myGuard' => false])
```
:::

::: info assertRanWith: array vs spread
For inline behaviors, `assertBehaviorRanWith()` passes the full parameter array as a **single argument** (not spread). This differs from class-based behaviors which use Mockery's `withArgs()` spread:

```php ignore
// Class-based: callback receives individual args
->assertBehaviorRanWith(SendEmailAction::class, fn($ctx, $event) => $ctx->get('sent'))

// Inline: callback receives array
->assertBehaviorRanWith('sendEmailAction', fn(array $params) => $params[0]->get('sent'))
```
:::

## Inspection

`isFaked()` is useful in shared test helpers or base test classes where you want to conditionally configure a behavior only when it has not already been faked by the calling test — preventing accidental double-setup.

<!-- doctest-attr: ignore -->
```php
// Check if a behavior is currently faked
ProcessOrderAction::isFaked();    // true/false

// Get the underlying mock/spy instance
$mock = ProcessOrderAction::getFake();
```

## Cleanup

Without explicit cleanup, fakes registered in one test persist into the next test in the same process. This causes false positives (a behavior appears to have run when it did not) and false negatives (unexpected calls are silently swallowed by a leftover mock), making test failures intermittent and hard to diagnose.

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
`resetAllFakes()` clears ALL faked behaviors across all classes — including inline behavior fakes — regardless of which class you call it from. The `$fakes` array is shared via `InvokableBehavior`, not per-child-class. You only need one `resetAllFakes()` call in your `afterEach()`.
:::

::: tip Related
See [Isolated Testing](/testing/isolated-testing) for unit-level `runWithState()` tests,
[TestMachine](/testing/test-machine) for the fluent wrapper with `faking()` and `assertBehaviorRan()`,
[Transitions & Paths](/testing/transitions-and-paths) for guard faking patterns,
and [Migration Patterns](/getting-started/upgrading#testing-migration-patterns) for upgrading from legacy test patterns.
:::
