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

// 4. Inline faking — fake inline closures during machine execution
OrderMachine::test()
    ->faking(['broadcastAction', 'isValidGuard' => true])
    ->send('SUBMIT')
    ->assertBehaviorRan('broadcastAction');
```

## Test Setup

### Pest / PHPUnit Configuration

Reset all behavior fakes between tests to prevent state leaking across test cases. Without this, a fake registered in one test could silently affect subsequent tests.

<!-- doctest-attr: ignore -->
```php
// tests/Pest.php or tests/TestCase.php
use Illuminate\Foundation\Testing\RefreshDatabase;

afterEach(function (): void {
    // Reset all behavior fakes between tests (also clears inline behavior fakes)
    IncrementAction::resetAllFakes();

    // Reset all machine fakes (child machine short-circuits)
    Machine::resetMachineFakes();
});
```

### In-Memory Database

For fast tests, use SQLite in-memory. This eliminates migration overhead and disk I/O — each test gets a fresh database without touching the filesystem. Combined with RefreshDatabase, tests run in complete isolation.

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
| Inline Faking | Fake inline closures (actions, guards, calculators) | [Fakeable Behaviors — Inline](/testing/fakeable-behaviors#inline-behavior-faking) |
| Constructor DI | Service injection + mocking | [Constructor DI](/testing/constructor-di) |
| Transition (Integration) | Guard pass/fail, state changes, paths | [Transitions & Paths](/testing/transitions-and-paths) |
| Machine (Acceptance) | Full fluent test wrapper | [TestMachine](/testing/test-machine) |
| Parallel | Dispatch verification, region isolation | [Parallel Testing](/testing/parallel-testing) |
| Inter-Machine | Child machine faking, sendTo/dispatchTo assertions | [Inter-Machine Testing](/testing/delegation-testing) |
| Job Actors | Job dispatch verification, fire-and-forget | [Inter-Machine Testing — Job Actors](/testing/delegation-testing#testing-job-actors) |
| Fire-and-Forget | Machine + `queue` (no `@done`) delegation testing | [Inter-Machine Testing — Fire-and-Forget](/testing/delegation-testing#testing-fire-and-forget-machine-delegation) |
| Available Events | Which events current state accepts, forward event availability | [TestMachine — Available Events](/testing/test-machine#available-events-assertions) |
| Scheduled Events | Cron-based batch operations via `runSchedule()`, `assertHasSchedule()` | [Scheduled Testing](/testing/scheduled-testing) |
| Time-Based | `advanceTimers()`, `assertHasTimer()`, timer lifecycle testing | [Time-Based Testing](/testing/time-based-testing) |
| Persistence | DB, restoration, archival | [Persistence Testing](/testing/persistence-testing) |
| Recipes | Common real-world patterns | [Recipes](/testing/recipes) |
| Migration | Upgrading from legacy test patterns | [Migration Patterns](/getting-started/upgrading#testing-migration-patterns) |

## Which Tool to Use?

```
Single behavior in isolation?         → State::forTesting() + runWithState()
Full machine flow?                    → TestMachine::create() or define()
Child delegation?                     → Machine::fake() + send()
Async child completion?               → Queue::fake() + ChildMachineCompletionJob
Timer behavior?                       → advanceTimers() / processTimers()
Forward endpoints?                    → MachineRouter::register() + postJson()
Cross-machine communication?          → Queue::fake() for dispatchTo
Full async pipeline / real infra?     → See "When Fakes Aren't Enough" below
```

## When Fakes Aren't Enough

Most EventMachine testing works fine with fakes. But some scenarios can only be verified with real infrastructure:

| What fakes verify | What fakes DON'T verify |
|-------------------|------------------------|
| Job dispatch (`Queue::assertPushed`) | Job execution → child runs → completion routes back |
| Timer registration (`assertHasTimer`) | `machine:process-timers` command fires correctly |
| Schedule definition (`assertHasSchedule`) | `machine:process-scheduled` runs via scheduler |
| Child invocation (`assertChildInvoked`) | Real child starts, persists, reaches final state |
| Lock exception thrown | Concurrent requests actually block each other |

**Rule of thumb:** If you check "was the right thing dispatched/registered?" → fakes are sufficient. If you need "does the full pipeline complete end-to-end?" → real infrastructure.

**When to invest:**
- Async delegation (`queue`) where parent behavior after `@done` is business-critical
- Timers (`after`/`every`) that must be verified against the sweep command
- Concurrent access with real database locks
- Forward endpoints with full HTTP → child → response chain

See [Recipes](/testing/recipes) for real infrastructure testing patterns.
