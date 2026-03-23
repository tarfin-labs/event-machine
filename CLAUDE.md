# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Testing
- `composer test` - Run full test suite (rector, pint, phpstan, unit tests in parallel, type coverage)
- `composer quality` - Run pint + rector + test (the standard quality gate)
- `composer test:unit` - Run only unit tests in parallel
- `composer test:types` - Run type coverage (100% minimum enforced)
- `composer test:phpstan` - Run static analysis with PHPStan
- `composer test:coverage` - Run tests with coverage report (80% minimum)
- `composer test:mutation` - Run mutation testing
- `composer test:profile` - Run tests with profiling

### Code Quality
- `composer pint` - Fix code style using Laravel Pint
- `composer rector` - Run Rector refactoring

### Artisan Commands
- `php artisan machine:xstate` - Export machine definition to XState v5 JSON for Stately Studio
- `php artisan machine:validate-config` - Validate machine configuration
- `php artisan machine:process-timers` - Sweep command for after/every timers (auto-registered)
- `php artisan machine:process-scheduled` - Process scheduled events (called by MachineScheduler)
- `php artisan machine:timer-status` - Display timer status for machine instances
- `php artisan machine:cache` - Cache machine class discovery for production
- `php artisan machine:clear` - Clear machine discovery cache
- `php artisan machine:archive-events` - Archive old machine events (fan-out, `--dry-run`, `--sync`, `--dispatch-limit`)
- `php artisan machine:archive-status` - Show archive stats, restore via `--restore=<rootEventId>`

### Local QA Testing
- `composer test:localqa` - Run local QA tests (requires MySQL + Redis + Horizon)
- QA tests live in `tests/LocalQA/` and are excluded from `composer test`
- See `tests/LocalQA/README.md` for setup instructions

## Architecture Overview

EventMachine is a Laravel package for creating event-driven state machines, heavily influenced by XState/SCXML. The core architecture consists of:

### Core Components

**MachineDefinition** (`src/Definition/MachineDefinition.php`): The blueprint for state machines, containing:
- Configuration parsing and validation
- State definitions and transitions (including `@always`, `@done`, `@fail`, `@timeout`)
- Behavior resolution (actions, guards, calculators, events, results)
- Event queue management and `processPostEntryTransitions` (centralized in `enterState()`)
- Context initialization
- Parallel state management (region tracking, dispatch mode)

**Machine** (`src/Actor/Machine.php`): Runtime instance that executes state machines:
- State persistence and restoration
- Event handling and transitions (`send()`, `transition()`)
- `result()` — computes final output via `ResultBehavior` (uses `triggeringEvent` for correct payload)
- `availableEvents()` — returns event types valid from the current state
- Machine delegation (sync/async child machines, fire-and-forget)
- Cross-machine communication (`sendTo`, `dispatchTo`, `sendToParent`, `dispatchToParent`, `raise`)
- Machine faking for tests (`Machine::fake()`)
- Machine identity (`$context->machineId()`, `$context->parentMachineId()`)

**State** (`src/Actor/State.php`): Represents current machine state:
- Current state definition and value array (supports parallel regions)
- Context data management
- `triggeringEvent` — preserves the original external event across the entire macrostep (entry actions, @always chains). Not overwritten by internal events.
- `currentEventBehavior` — tracks the current lifecycle event (overwritten ~49 times per macrostep)
- History maintenance via `EventCollection`

### Behavior System

All machine behaviors extend `InvokableBehavior` (parameter injection by type-hint) and include:
- **Actions** (`ActionBehavior`): Execute side effects during transitions and state entry/exit
- **Guards** (`GuardBehavior` / `ValidationGuardBehavior`): Control transition execution with conditions
- **Calculators** (`CalculatorBehavior`): Pre-compute values before guards/actions in a transition
- **Events** (`EventBehavior`): Define event structure, validation, and payload types
- **Results** (`ResultBehavior`): Compute final state machine outputs (used by `Machine::result()` and HTTP endpoints)

Behaviors can be defined as classes or inline closures. All support parameter injection: `ContextManager`, `EventBehavior`, `State`, `EventCollection`, `ForwardContext`.

### Listener System

The `listen` key in machine config supports lifecycle hooks:
- `entry` — runs when entering a state
- `exit` — runs when exiting a state
- `transition` — runs after a transition completes
- Listeners can be synchronous or queued (`queue: true`) via `ListenerJob`

### HTTP Endpoint Routing

`src/Routing/` provides a full HTTP API layer for machines:
- **`MachineRouter::register()`** — Registers Laravel routes from machine endpoint definitions
- **`EndpointDefinition`** — Parses endpoint config: `uri`, `method`, `action`, `result`, `middleware`, `status`, `contextKeys`
- **`ForwardedEndpointDefinition`** — Auto-generated routes that forward events to child machines
- **`ForwardContext`** — Type-hintable in `ResultBehavior` to access child context in forwarded endpoints
- **`MachineController`** — Handles model-bound, machine-id-bound, stateless, create, and forwarded routes

### Parallel States

States with `'type' => 'parallel'` run multiple concurrent regions:
- Each region has its own state hierarchy and transitions independently
- `@done` fires when all regions reach final states, `@fail` when any region fails
- **Dispatch mode** (`config/machine.php` → `parallel_dispatch`): regions run as separate queue jobs (`ParallelRegionJob`) with locking
- Region timeout support via `ParallelRegionTimeoutJob`

### Machine Delegation

- `machine` key on states — sync or async child machine delegation
- `job` key on states — Laravel job delegation (`ChildJobJob`)
- `@done` / `@done.{finalState}` — route parent based on child's final state
- `@fail` / `@timeout` — handle child failures and timeouts
- Fire-and-forget: omit `@done` with `queue` key — parent continues, child runs independently
- `forward` key — auto-generate HTTP routes that delegate requests to running child machines

### State Management

- **StateDefinition** (`src/Definition/StateDefinition.php`): Defines state behavior, transitions, and hierarchy
- **TransitionDefinition** (`src/Definition/TransitionDefinition.php`): Defines state transitions with conditions
- **ContextManager** (`src/ContextManager.php`): Manages machine context data with validation. Subclasses can define computed methods and `toResponseArray()` for API responses.

### Database Integration

- `machine_events` — persisted via `MachineEvent` model. Incremental context changes optimize storage.
- `machine_current_states` — normalized current state per instance (for timers + schedules)
- `machine_timer_fires` — timer dedup and recurring fire tracking
- `machine_children` — async child machine tracking (delegation)
- `machine_locks` — concurrent state mutation prevention
- `machine_event_archives` — compressed archived events via `MachineEventArchive` model
- State can be restored from any point using root event IDs

### Event Archival System

- **`ArchiveService`** (`src/Services/ArchiveService.php`): `archiveMachine()`, `restoreMachine()`, `batchArchive()`
- **`CompressionManager`** (`src/Support/CompressionManager.php`): gzip compression with configurable levels/thresholds
- Fan-out pattern via `ArchiveSingleMachineJob` — each machine archived as a separate queue job
- Auto-restore: archived machines are transparently restored when new events arrive
- Configured via `config/machine.php` `archival` section: `enabled`, `level`, `threshold`, `days_inactive`, `restore_cooldown_hours`

### Configuration (`config/machine.php`)

Four top-level sections:
- **`archival`** — archive old machine events (enabled, compression level, days inactive, restore cooldown)
- **`parallel_dispatch`** — dispatch parallel regions as queue jobs (enabled, queue, lock timeout/ttl, job timeout/tries/backoff, region timeout)
- **`timers`** — timer resolution, batch size, backpressure threshold
- **`max_transition_depth`** — configurable depth limit (default 100, env `MACHINE_MAX_TRANSITION_DEPTH`)

### Testing Infrastructure

- **`TestMachine`** (`src/Testing/TestMachine.php`): Fluent test API — `Machine::test()`, `Machine::startingAt()`, assertions, timer/schedule helpers
- **`InteractsWithMachines`** trait: Auto-resets all fakes (`Machine`, `CommunicationRecorder`, `InlineBehaviorFake`, `InvokableBehavior`) after each test
- **`CommunicationRecorder`**: Records `sendTo()` and `raise()` calls for assertion without side effects
- **`InlineBehaviorFake`**: Spy/fake support for inline closure behaviors
- **`EventBuilder`**: Abstract base for event test factories (like model factories but for events)
- **Behavior faking**: `fakingAllActions(except:)`, `fakingAllGuards(except:)`, `fakingAllBehaviors(except:)`, `simulateChildDone/Fail/Timeout`
- **Isolated assertions**: `ActionClass::assertRaised()`, `assertNotRaised()`, `assertRaisedCount()`, `assertNothingRaised()`

## Workflow Rules

### Releases
- **NEVER create a release (gh release, git tag) without explicit user approval.** Always ask first.
- **Tag names: NO `v` prefix.** Use `7.0.0`, not `v7.0.0`. All existing tags follow this convention (`6.4.0`, `5.0.0`, etc.).

### Quality Gate
- **Always run `composer quality`** after completing work — this runs pint, rector, and test (which includes phpstan, unit tests in parallel, and type coverage).
- **Never run `vendor/bin/pest` directly.** `composer test` runs tests in parallel with all checks. Running pest alone is slower and incomplete.
- DocTest should pass with 0 failures. If a new code block causes failure, add appropriate doctest attribute (`ignore`, `no_run`, or `bootstrap`). DocTest is NOT included in `composer test` — run separately with `vendor/bin/doctest`.

### Documentation URLs
- **Documentation site domain is `eventmachine.dev`** — always use `https://eventmachine.dev/...` when linking to docs (in release notes, README, PR descriptions, etc.). Never use `tarfin-labs.github.io/event-machine`.

### Pre-Commit Checks
- **Always run `composer quality`** before committing. This replaces the old `composer pint && composer rector && composer test` workflow.
- After Rector runs, **review what it changed** — Rector may apply refactorings (e.g., `instanceof` checks, type narrowing) that need verification. Check the diff before committing Rector's changes.

## Key Development Patterns

### Machine Definition Structure
```php
MachineDefinition::define(
    config: [
        'id' => 'machine_name',
        'initial' => 'initial_state',
        'context' => [...],
        'states' => [...],
    ],
    behavior: [
        'actions' => [...],
        'guards' => [...],
        'calculators' => [...],
        'events' => [...],
        'results' => [...],
    ],
    endpoints: [...],
    schedules: [...],
)
```

### Invokable Behaviors
All behaviors should extend appropriate base classes:
- Actions extend `ActionBehavior`
- Guards extend `GuardBehavior` or `ValidationGuardBehavior`
- Calculators extend `CalculatorBehavior`
- Events extend `EventBehavior`
- Results extend `ResultBehavior`

### Testing Patterns
- **Entry points**: `MyMachine::test()`, `MyMachine::startingAt('state')` — NOT `TestMachine::create()` (deprecated)
- **Trait**: Always use `InteractsWithMachines` — auto-resets all fakes between tests
- Test stubs in `tests/Stubs/` provide examples: TrafficLights, Calculator, Elevator, ChildDelegation, Parallel, Endpoint, JobActors, ListenerMachines, AlwaysEventPreservation, and more
- Package tests use `RefreshDatabase` trait and in-memory SQLite
- Local QA tests in `tests/LocalQA/` use real MySQL + Redis + Horizon (excluded from `composer test`)
- E2E tests in `tests/E2E/` test full pipeline with real artisan commands

### Code Style
- PHP 8.2+ with strict types enabled
- Laravel Pint with custom alignment rules for `=>` and `=` operators
- PHPStan level 5 analysis
- All classes use declare(strict_types=1)
- 100% type coverage enforced

### Naming Conventions
All code, tests, and documentation **must** follow the naming conventions defined in [`docs/building/conventions.md`](docs/building/conventions.md). Key rules:
- **Event types**: `SCREAMING_SNAKE_CASE`, no abbreviations (`ORDER_SUBMITTED`, not `ORD_SUB`)
- **State names**: `snake_case` adjective/participle, must pass the "is" test (`awaiting_payment`, not `awaitPayment`)
- **Machine IDs**: `snake_case` (`order_workflow`, not `orderWorkflow`)
- **Inline behavior keys**: `camelCase` with type suffix (`sendEmailAction`, `isValidGuard`, `orderTotalCalculator`)
- **Context array keys**: `snake_case` (`total_amount`, not `totalAmount`)
- **Class names**: PascalCase with type suffix (`SendEmailAction`, `IsPaymentValidGuard`)

## Package Structure

- `src/Actor/` - Runtime machine and state classes
- `src/Behavior/` - Base behavior classes (Action, Guard, Calculator, Event, Result)
- `src/Commands/` - Artisan commands (timers, schedules, cache, xstate, archive)
- `src/Contracts/` - Interfaces (ScheduleResolver, ReturnsResult, ProvidesFailureContext)
- `src/Definition/` - Machine definition, state, transition, timer, schedule definitions
- `src/Enums/` - Type definitions and constants
- `src/Exceptions/` - Custom exception classes
- `src/Jobs/` - Queue jobs (ChildMachineJob, SendToMachineJob, ChildJobJob, ParallelRegionJob, ListenerJob, ArchiveSingleMachineJob, etc.)
- `src/Locks/` - Machine lock manager for concurrent access
- `src/Models/` - Eloquent models (MachineEvent, MachineChild, MachineCurrentState, MachineTimerFire, MachineEventArchive)
- `src/Routing/` - HTTP endpoint routing (MachineRouter, MachineController, EndpointDefinition, ForwardedEndpointDefinition, ForwardContext)
- `src/Scheduling/` - Schedule registration (MachineScheduler)
- `src/Services/` - Business logic services (ArchiveService)
- `src/Support/` - Value objects and utilities (Timer, CompressionManager, MachineDiscovery, ArrayUtils)
- `src/Testing/` - Test helpers (TestMachine, InteractsWithMachines, CommunicationRecorder, InlineBehaviorFake, EventBuilder)
- `src/Traits/` - Reusable traits (Fakeable, HasMachines)
- `src/Transformers/` - Spatie LaravelData transformers (ModelTransformer)
- `tests/LocalQA/` - Local QA tests requiring real MySQL + Redis + Horizon
- `tests/Stubs/` - Example machine implementations for testing
- `config/machine.php` - Package configuration (archival, parallel dispatch, timers, max transition depth)
- `database/migrations/` - Database schema stubs
- `spec/` - Implementation specs and plans (version-prefixed, e.g., `8.5.3-centralize-post-entry-transitions.md`)

The package integrates with Laravel through the `MachineServiceProvider` and provides Eloquent model casting via `MachineCast`.
