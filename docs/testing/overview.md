# Testing Overview

EventMachine is designed for testability at every level — from isolated unit tests of individual behaviors to full machine-level acceptance tests.

## Philosophy

- **Layered testing pyramid**: behavior → transition → path
- **Real by default, opt-in faking**: behaviors run with real logic unless you explicitly fake them
- **Container-first architecture**: all behaviors are resolved via `App::make()`, enabling constructor DI and mockability

## Quick Start

Three test levels, one behavior:

<!-- doctest-attr: ignore -->
```php
// 1. Isolated — unit test a single guard
$state = State::forTesting(['count' => 4]);
expect(IsEvenGuard::runWithState($state))->toBeTrue();

// 2. Faked — mock a behavior during machine execution
SendEmailAction::shouldRun()->once();
OrderMachine::test()->send('SUBMIT')->assertBehaviorRan(SendEmailAction::class);

// 3. Fluent — full path test with TestMachine
TrafficLightsMachine::test()
    ->assertState('active')
    ->send('INCREASE')
    ->assertContext('count', 1);
```

## Test Setup

### Pest / PHPUnit Configuration

<!-- doctest-attr: ignore -->
```php
// tests/Pest.php or tests/TestCase.php
use Illuminate\Foundation\Testing\RefreshDatabase;

afterEach(function (): void {
    // Reset all fakes between tests
    IncrementAction::resetAllFakes();
});
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

## Testing Layers

| Layer | What to Test | Guide |
|-------|-------------|-------|
| Behavior (Unit) | Individual guards, actions, calculators | [Isolated Testing](/testing/isolated-testing) |
| Faking | Mock behaviors during execution | [Fakeable Behaviors](/testing/fakeable-behaviors) |
| Constructor DI | Service injection + mocking | [Constructor DI](/testing/constructor-di) |
| Transition (Integration) | Guard pass/fail, state changes, paths | [Transitions & Paths](/testing/transitions-and-paths) |
| Machine (Acceptance) | Full fluent test wrapper | [TestMachine](/testing/test-machine) |
| Parallel | Dispatch verification, region isolation | [Parallel Testing](/testing/parallel-testing) |
| Persistence | DB, restoration, archival | [Persistence Testing](/testing/persistence-testing) |
| Recipes | Common real-world patterns | [Recipes](/testing/recipes) |
