# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Testing
- `composer test` or `composer pest` - Run all tests using Pest
- `composer testp` - Run tests in parallel  
- `composer coverage` - Run tests with coverage report
- `composer coveragep` - Run tests with coverage in parallel
- `composer type` - Run tests with type coverage
- `composer profile` - Run tests with profiling

### Code Quality
- `composer lint` or `composer pint` - Fix code style using Laravel Pint
- `composer lintc` - Fix code style and commit changes
- `composer larastan` - Run static analysis with PHPStan/Larastan  
- `composer infection` - Run mutation testing

### Artisan Commands
- `php artisan machine:xstate` - Export machine definition to XState v5 JSON for Stately Studio
- `php artisan machine:validate-config` - Validate machine configuration
- `php artisan machine:process-timers` - Sweep command for after/every timers (auto-registered)
- `php artisan machine:process-scheduled` - Process scheduled events (called by MachineScheduler)
- `php artisan machine:timer-status` - Display timer status for machine instances
- `php artisan machine:cache` - Cache machine class discovery for production
- `php artisan machine:clear` - Clear machine discovery cache

### Local QA Testing
- `composer test:localqa` - Run local QA tests (requires MySQL + Redis + Horizon)
- QA tests live in `tests/LocalQA/` and are excluded from `composer test`
- See `tests/LocalQA/README.md` for setup instructions

## Architecture Overview

EventMachine is a Laravel package for creating event-driven state machines, heavily influenced by XState. The core architecture consists of:

### Core Components

**MachineDefinition** (`src/Definition/MachineDefinition.php`): The blueprint for state machines, containing:
- Configuration parsing and validation
- State definitions and transitions  
- Behavior resolution (actions, guards, events)
- Event queue management
- Context initialization

**Machine** (`src/Actor/Machine.php`): Runtime instance that executes state machines:
- State persistence and restoration
- Event handling and transitions
- Database integration with machine_events table
- Validation guard processing
- Machine delegation (sync/async child machines)
- Cross-machine communication (sendTo/dispatchTo)
- Machine faking for tests

**State** (`src/Actor/State.php`): Represents current machine state:
- Current state definition
- Context data management
- Event behavior tracking
- History maintenance

### Behavior System

All machine behaviors extend `InvokableBehavior` and include:
- **Actions** (`src/Behavior/ActionBehavior.php`): Execute side effects during transitions
- **Guards** (`src/Behavior/GuardBehavior.php`): Control transition execution with conditions
- **Events** (`src/Behavior/EventBehavior.php`): Define event structure and validation
- **Results** (`src/Behavior/ResultBehavior.php`): Compute final state machine outputs

### State Management

- **StateDefinition** (`src/Definition/StateDefinition.php`): Defines state behavior, transitions, and hierarchy
- **TransitionDefinition** (`src/Definition/TransitionDefinition.php`): Defines state transitions with conditions
- **ContextManager** (`src/ContextManager.php`): Manages machine context data with validation

### Database Integration

- Machine events are persisted in `machine_events` table via `MachineEvent` model
- State can be restored from any point using root event IDs
- Incremental context changes are stored to optimize database usage
- `machine_current_states`: normalized current state per instance (for timers + schedules)
- `machine_timer_fires`: timer dedup and recurring fire tracking
- `machine_children`: async child machine tracking (delegation)
- `machine_locks`: concurrent state mutation prevention

### v7 Features

- **Machine Delegation**: `machine`/`job` keys on states, `@done`/`@fail`/`@timeout` transitions, fire-and-forget (omit `@done` with `queue`)
- **Cross-Machine Communication**: `sendTo()`, `dispatchTo()`, `sendToParent()`, `dispatchToParent()`, `raise()`
- **Time-Based Events**: `after` (one-shot) and `every` (recurring) timers on transitions via `Timer` VO
- **Scheduled Events**: `schedules` key + `MachineScheduler::register()` for cron-based batch operations
- **Machine Faking**: `Machine::fake()` short-circuits child delegation in tests
- **Machine Identity**: `$context->machineId()`, `$context->parentMachineId()`

## Workflow Rules

### Releases
- **NEVER create a release (gh release, git tag) without explicit user approval.** Always ask first.
- **Tag names: NO `v` prefix.** Use `7.0.0`, not `v7.0.0`. All existing tags follow this convention (`6.4.0`, `5.0.0`, etc.).

### Quality Gate
- **Always run `composer quality`** after completing work — this runs pint, rector, phpstan, tests (parallel), and type coverage in one command.
- **Never run `vendor/bin/pest` directly.** `composer test` (included in `quality`) runs tests in parallel AND includes doctest and other tools. Running pest alone is slower (no parallelism) and incomplete.
- DocTest should pass with 0 failures. If a new code block causes failure, add appropriate doctest attribute (`ignore`, `no_run`, or `bootstrap`).

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
        'states' => [...]
    ],
    behavior: [
        'actions' => [...],
        'guards' => [...],
        'events' => [...]
    ]
)
```

### Invokable Behaviors
All behaviors should extend appropriate base classes:
- Actions extend `ActionBehavior`
- Guards extend `GuardBehavior` or `ValidationGuardBehavior`
- Events extend `EventBehavior`

### Testing Structure
- Test stubs in `tests/Stubs/` provide examples of machine implementations
- Machine examples include TrafficLights, Calculator, Elevator, ChildDelegation, TimerMachines, ScheduledMachines
- Package tests use `RefreshDatabase` trait and in-memory SQLite
- Local QA tests in `tests/LocalQA/` use real MySQL + Redis + Horizon (excluded from `composer test`)
- E2E tests in `tests/E2E/` test full pipeline with real artisan commands

### Code Style
- PHP 8.2+ with strict types enabled
- Laravel Pint with custom alignment rules for `=>` and `=` operators
- PHPStan level 5 analysis
- All classes use declare(strict_types=1)

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
- `src/Behavior/` - Base behavior classes and implementations
- `src/Commands/` - Artisan commands (timers, schedules, cache, xstate)
- `src/Contracts/` - Interfaces (ScheduleResolver, ReturnsResult)
- `src/Definition/` - Machine definition, state, transition, timer, schedule definitions
- `src/Enums/` - Type definitions and constants
- `src/Exceptions/` - Custom exception classes
- `src/Jobs/` - Queue jobs (ChildMachineJob, SendToMachineJob, ChildJobJob, etc.)
- `src/Locks/` - Machine lock manager for concurrent access
- `src/Models/` - Eloquent models (MachineEvent, MachineChild, MachineCurrentState, MachineTimerFire)
- `src/Routing/` - HTTP endpoint routing (MachineRouter, MachineController, EndpointDefinition)
- `src/Scheduling/` - Schedule registration (MachineScheduler)
- `src/Support/` - Value objects (Timer)
- `src/Testing/` - Test helpers (TestMachine)
- `src/Traits/` - Reusable traits like `Fakeable` and `HasMachines`
- `tests/LocalQA/` - Local QA tests requiring real MySQL + Redis + Horizon
- `tests/Stubs/` - Example machine implementations for testing
- `config/machine.php` - Package configuration
- `database/migrations/` - Database schema stubs

The package integrates with Laravel through the `MachineServiceProvider` and provides Eloquent model casting via `MachineCast`.