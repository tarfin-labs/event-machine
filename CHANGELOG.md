# Changelog

All notable changes to `event-machine` will be documented in this file.

## [Unreleased]

## [6.0.0] - 2026-03-10

### Added
- **Testability Layer**: Comprehensive testing infrastructure for state machines
  - `Machine::test()` — Livewire-style fluent test wrapper with 21+ assertion methods
  - `State::forTesting()` — Lightweight state factory for isolated behavior unit tests
  - `InvokableBehavior::runWithState()` — Isolated testing with engine-identical parameter injection; returns `eventQueue` Collection for raised event capture
  - `EventBehavior::forTesting()` — Test factory for event construction with payload/meta
  - `TestMachine::define()` — Inline machine definitions for quick disposable tests
- **TestMachine assertion methods**: `assertState()`, `assertNotState()`, `assertContext()`, `assertContextHas()`, `assertContextMissing()`, `assertContextIncludes()`, `assertContextMatches()`, `assertHistory()`, `assertHistoryContains()`, `assertHistoryOrder()`, `assertTransitionedThrough()`, `assertGuarded()`, `assertGuardedBy()`, `assertValidationFailed()`, `assertFinished()`, `assertNotFinished()`, `assertPath()`, `assertBehaviorRan()`, `assertBehaviorNotRan()`, `assertRegionState()`, `assertAllRegionsCompleted()`, `assertResult()`
- **TestMachine configuration**: `withoutPersistence()`, `withScenario()`, `withContext()`, `withoutParallelDispatch()`, `faking()`, `tap()`, `debugGuards()`
- **Fakeable enhancements**: `spy()`, `allowToRun()`, `assertRanWith()`, `assertRanTimes()`, `mayReturn()`
- **Constructor DI**: Behaviors can inject typed service dependencies via `__construct()` (resolved by Laravel container)
- Comprehensive testing documentation: overview, isolated testing, fakeable behaviors, constructor DI, TestMachine API, transitions & paths, recipes, persistence testing, parallel testing, migration guide

### Changed
- **Breaking:** Behavior resolution via Laravel container (`App::make()`) instead of `new $class()` — enables constructor DI but requires injectable parameters (see [Upgrading to v6.0](/getting-started/upgrading#upgrading-to-v6-0))
- **Breaking:** `InvokableBehavior::run()` always uses `App::make()` (previously used `new static()` for non-faked behaviors)
- **Breaking:** `Fakeable::fake()` uses `App::bind()` with Closure instead of dual storage — `resetFakes()` uses `app()->offsetUnset()` instead of `App::forgetInstance()`
- `TestMachine::define()` auto-disables persistence
- `TestMachine::faking()` uses `spy()` instead of `fake()` for non-intrusive tracking
- `assertTransitionedThrough()` now enforces sequential order (previously only checked presence)

### Fixed
- `TestMachine::assertState()` failure message now shows actual state for parallel states
- `TestMachine::assertNotState()` failure message shows actual state
- `TestMachine::assertHistoryContains()` includes contextual failure message
- `TestMachine::assertRegionState()` uses exact segment matching (prevents false positives from prefix collisions)
- `TestMachine::assertGuarded()` handles unknown events with descriptive error
- `TestMachine::assertValidationFailed()` catches all exceptions (not just specific types)
- `State::matches()` and `matchesAll()` return `false` when `currentStateDefinition` is null (instead of throwing)
- `Fakeable::spy()` after `fake()` now creates proper spy (teardown previous mock first)
- `Fakeable::fake()` tears down previous mock before creating new one (prevents stale bindings)
- `InvokableBehavior::runWithState()` passes `eventQueue` to container resolution
- `TestMachine::withContext()` clones definition to prevent cross-test context leak
- `DoubleCountCalculator` test stub moved to correct `Calculators/` namespace (was incorrectly extending `ActionBehavior`)

## [5.1.2] - 2026-03-10

### Added
- Escape transitions documentation (action timing asymmetry, calculators support, backward-compat notes)
- Tests for exit actions on nested parallel state completion
- Tests for targetless `@done` (compound, parallel, guarded)
- Tests for escape-to-compound-target in parallel states
- Tests for compound `@done` via `ConditionalCompoundOnDoneMachine`
- Tests for `@fail` and compound `@done` validation

### Fixed
- `MachineDefinition`: run branch actions on targetless `@done`/`@fail` transitions (XState semantic — actions without state change)
- `MachineDefinition`: run exit actions on leaf states and nested parallel state in `processNestedParallelCompletion`
- `MachineDefinition`: add delimiter suffix to `str_starts_with` in `areAllRegionsFinal` and `processNestedParallelCompletion` (prevent region ID prefix collision)
- `MachineDefinition`: add `TRANSITION_START`/`FINISH` to `processParallelOnDone` and `processParallelOnFail`
- `ParallelDispatchGuardAbortTest`: unskip benign abort test (`work_was_discarded=false`)

### Removed
- Dead null check in `StateConfigValidator::validateDoneFailConfig`

## [5.1.1] - 2026-03-09

### Fixed
- `MachineDefinition`: handle root/parallel-level `on` events during parallel state (dedup `selectTransitions`, escape via `exitParallelStateAndTransitionToTarget`)

## [5.1.0] - 2026-03-09

### Added
- **Conditional `@done`/`@fail`**: Guard support for `@done` and `@fail` transitions — conditional branching based on context, with fallback support
  - Parallel `@done` with guards and multiple branches
  - Parallel `@fail` with guards (retry pattern support)
  - Compound `@done` with guards (standalone compound states)
  - `resolveOnDoneOrFailBranch()` helper for guard evaluation
  - `onDoneTransition` and `onFailTransition` properties on `StateDefinition`
  - `@done`/`@fail` format validation in `StateConfigValidator` (string, object, conditional array)
- Documentation for conditional `@done`/`@fail` with guards, async context notes

### Changed
- `MachineDefinition`: use `TransitionDefinition` in parallel process methods and `processCompoundOnDone` (conditional guard support)

## [5.0.0] - 2026-03-09

### Added
- **Parallel Dispatch**: Opt-in concurrent execution of parallel region entry actions via Laravel queue jobs (`ParallelRegionJob`)
  - Region entry actions run truly in parallel across queue workers
  - Double-guard pattern (pre-lock + under-lock) prevents stale state transitions
  - Context diff/merge strategy for safe concurrent context updates with deep recursive merge
  - Raised events captured and processed under lock in same job scope
  - `@fail` event support for job failure handling (`processParallelOnFail`)
  - Stall detection: `PARALLEL_REGION_STALLED` event when entry action completes without `raise()`
  - Context conflict detection: `PARALLEL_CONTEXT_CONFLICT` event on shared key overwrites (LWW)
  - Guard abort tracking: `PARALLEL_REGION_GUARD_ABORT` event when under-lock guard discards work
  - Configurable via `config/machine.php` (`parallel_dispatch` section)
- **Region Timeout**: Configurable timeout for stuck parallel states (`region_timeout` config key)
  - `ParallelRegionTimeoutJob` dispatched with delay alongside region jobs
  - When timeout expires and regions haven't completed, triggers `@fail` on the parallel state
  - Records `PARALLEL_REGION_TIMEOUT` internal event with stalled region details
  - Idempotent — no-op if parallel state already completed or machine moved on
  - Disabled by default (`region_timeout: 0`)
- `MachineLockManager` for database-based locking with immediate and blocking modes
- `ArrayUtils` shared utility for `recursiveMerge()` and `recursiveDiff()`
- `processNestedParallelCompletion()` for nested parallel `@done` auto-fire when all sub-regions reach final
- Event resolution fix: `initializeEvent()` now resolves `EventBehavior` instances through machine's event registry (preserves validation rules)
- Configurable job parameters: `job_timeout`, `job_tries`, `job_backoff` in `parallel_dispatch` config
- Comprehensive documentation for parallel dispatch (configuration, requirements, context merge, stall detection, timeout, best practices, limitations)

### Changed
- Internal framework config keys use `@` prefix: `@done`, `@fail` (consistent with existing `@always`). These keys are new in v5.0 — no migration from previous versions needed.
- `Machine::send()` lock acquisition uses `MachineLockManager` with configurable timeout
- `Machine::send()` lock guarded behind `parallel_dispatch.enabled` (no DB lock overhead for non-parallel machines)
- `createEventBehavior()` signature expanded to accept `EventBehavior|array`
- `enterParallelState()` dispatches `ParallelRegionJob` per region when parallel dispatch is enabled
- `dispatchPendingParallelJobs()` dispatches after lock release, guarded behind persist success flag

### Fixed
- `Machine::restoreContext()` double-wrapping bug
- Duplicate `PARALLEL_REGION_ENTER` event in dispatch branch (now recorded by job only)
- `ParallelRegionJob`: null-safe lock release in finally blocks
- `ParallelRegionJob`: reduced `failed()` lock timeout to 5s max (prevent deadlock between concurrent fail handlers)
- `ParallelRegionJob`: critical section wrapped in `DB::transaction()` for atomicity
- Entry action check unified to parsed property across all 3 dispatch paths
- `MachineLockManager`: rate-limited expired lock cleanup to every 5s (prevent thundering herd)
- Missing `STATE_ENTER` events in `processNestedParallelCompletion` and `exitParallelStateAndTransition`
- Ghost job dispatch prevented on persist failure
- `MachineDefinition`: `config()` call wrapped in try-catch for non-Laravel environments

### Removed
- `EventFactory` (unused abstract class)
- `TransitionProperty::Normal`, `TransitionProperty::Guarded` enum cases (zero references)
- `ContextManager::getMorphClass()` (not an Eloquent model)
- `EventDefinition::getMorphClass()` (not an Eloquent model)
- `MachineLockHandle::extend()` (unused method)
- Phantom `use Exception` import in `Machine`
- Dead OR condition in `TransitionBranch` constructor
- `instanceof.alwaysTrue` PHPStan ignore pattern (no longer triggered)

## [4.0.2] - 2026-03-07

### Fixed
- `areAllRegionsFinal()` incorrectly counted deeply nested final states as region-level completion — only direct children of a parallel region now count as region-final
- Added `processCompoundOnDone()` for compound state `onDone` transitions within parallel regions (XState parity):
  - Only the immediate compound parent's `onDone` fires (no grandparent propagation)
  - Compound parent exit actions now run during `onDone` transitions
  - `onDone` actions config supported (`onDone: {target, actions}`)
  - Recursive chaining for auto-final initial states

## [4.0.1] - 2026-03-03

### Fixed
- `@always` guard exception in parallel states — cross-region synchronization guards no longer throw when evaluating to `false`
- Flaky `ArchiveLifecycleTest` — cleared `CompressionManager` static cache in `beforeEach`

## [4.0.0] - 2026-02-26

### Added
- Parallel states support (`StateDefinitionType::PARALLEL`) with validation, event handling, transitions, persistence, restoration, and `onDone` auto-transitions when all regions reach final states
- Parallel state lifecycle events (`InternalEvent`: region enter/exit, done)
- Multi-value state support for parallel regions (`matches()`, `matchesAll()`, `isInParallelState()`)
- Relative state resolution and parallel state transition routing
- DocTest integration for documentation testing (`testflowlabs/doctest`)
- CI consolidated into single tiered workflow (`ci.yml`) with manual Infection trigger
- Documentation overhaul: parallel states guide (split into overview, event handling, persistence), examples section (quick-start, real-world), archival/compression split, style compliance pass across all docs

### Changed
- **Breaking:** Dropped Laravel 10 support - requires Laravel 11+ (Pest v4 and Larastan v3 dependency)
- **Breaking:** Dropped Orchestra Testbench ^8.x - requires ^9.0+
- **Breaking:** Dropped PHP 8.2 support - requires PHP 8.3+ (Pest v4 dependency)
- `StateConfigValidator` uses `InvalidParallelStateDefinitionException` for parallel state validation errors

### Fixed
- `CompressionManager`: cast config compression level to int with fallback (prevent `InvalidArgumentException` when config returns non-int)
- `ArchiveService` and `ArchiveEventsCommand`: added type hints to closures (restored 100% type coverage)
- `phpunit.xml.dist`: disabled `failOnDeprecation` for PHP 8.5 `PDO::MYSQL_ATTR_SSL_CA` deprecation
- `EventBehavior`: explicit parameters in `collect()` method (PHPStan compliance)
- Parallel state initialization for nested/compound states (recursively initialize all regions)
- Parallel state persistence: immediate state value updates during transitions
- `StateDefinition`: entry action comment and `self` instanceof usage cleanup
- Str::ulid() compatibility for older symfony/uid in prefer-lowest CI

## [3.0.2] - 2026-01-27

### Fixed
- `ArchiveService`: cast config values to int (env returns strings)
- `ArchiveSingleMachineJob`: cast compression level to int

## [3.0.1] - 2026-01-27

### Fixed
- `ArchiveEventsCommand`: optimize `findEligibleMachines` query
- `ArchiveService`: optimize `getEligibleInstances` query (400s to 100ms)

## [3.0.0] - 2026-01-27

### Added
- Machine event archival system (`ArchiveService`, `ArchiveEventsCommand`, `ArchiveStatusCommand`)
- `MachineEventArchive` model for compressed event storage
- `ArchiveSingleMachineJob` for parallel archival processing (fan-out pattern)
- `CompressionManager` for configurable data compression with threshold and level settings
- Auto-restore: archived events are transparently restored when new events are created
- `MachineConfigValidatorCommand` for validating machine configuration (`php artisan machine:validate-config`)
- `MachineClassVisitor` for PHP-Parser based class discovery
- `CheckUpgradeCommand`, `MigrateEventsCommand`, and `MigrateMachineEventsJob` for v3.0 upgrade path
- `CompressionBenchmarkCommand` for performance testing
- Migration for `machine_event_archives` table
- Migration for JSON columns and compression upgrade on `machine_events`
- PHPStan/Larastan static analysis (level 5, later raised to max)
- Rector for automated code quality improvements
- PHP 8.5 support in CI matrix
- Event processing order tests (entry/exit actions, raised events)
- Comprehensive documentation site (VitePress)

### Changed
- **Breaking:** `machine_events` table columns `payload`, `context`, and `meta` changed from text to JSON
- **Breaking:** Removed `CompressedJsonCast` in favor of native array casts
- **Breaking:** Removed deprecated compression commands and legacy archival logic
- Archival uses fan-out dispatcher pattern instead of single batch job
- Simplified archival config settings (`dispatch_limit` replaces `batch_size`)
- Renamed `getEligibleMachines` to `getEligibleInstances`
- Composer scripts reorganized with `test:` prefix
- CI: replaced Pint auto-commit with check-only workflow

### Fixed
- `ReflectionNamedType` handling for parameter type resolution
- `formatBytes` float to int cast
- MySQL aggregate results cast to int for strict types
- Prevented archiving of active machines
- Race condition safety in auto-restore
- Null safety for `unpack()` result in compression
- PHPStan false positive suppression

## [2.1.2] - 2025-10-15

### Added
- `InteractsWithData` trait for `EventBehavior` with collection and retrieval methods
- `SimpleEvent` class and tests for versioning and data handling
- Tests for `InvokableBehavior` union types, `eventQueue` initialization, and `hasMissingContext()`
- Tests for `State` sequence number validation
- CLAUDE.md with development and architecture details

### Changed
- `EventBehavior`: enhanced data handling methods

### Fixed
- Cleaned up unused imports and redundant comments in `EventBehavior`

## [2.1.1] - 2025-06-10

### Fixed
- `Fakeable` trait: simplified `shouldRun()` and `shouldReturn()` by removing unnecessary checks
- `InvokableBehaviorFakeTest`: corrected `TestCountGuard` behavior assertion
- Added edge case tests for `shouldRun`/`shouldReturn` without prior `fake()` call

## [2.1.0] - 2025-02-24

### Added
- Laravel 12 support
- Larastan v3 support
- `TransformationContext` parameter to transform method
- `MachineConfigValidatorCommand` with `--all` option for batch validation
- `MachineClassVisitor` for PHP-Parser based class discovery
- `status_events` support in root-level config keys
- Tests for guarded transitions with calculators

### Changed
- Updated PHP and Laravel versions in CI workflow
- `nikic/php-parser` added as dependency

### Fixed
- Pest workflow for Laravel dependency resolution
- Log expectation handling in `StateTest`

## [2.0.1] - 2024-12-24

### Fixed
- `StateConfigValidator`: consolidated default condition validation
- Improved structure and added context to validation methods

## [2.0.0] - 2024-12-17

### Added
- `StateConfigValidator` for machine configuration validation at initialization
- Root-level, state type, final state, transition, guarded transition, and behavior validation
- `CalculatorBehavior` for computing values during transitions with pass/fail events
- `ResolvesBehaviors` trait with `getAction()`, `getGuard()`, `getCalculator()`, `getEvent()` for testing inline behaviors
- `EventMachine` facade (renamed from `MachineFacade`) with `resetAllFakes()`
- `Fakeable` trait: `shouldReturn()` for simple return value mocking, `assertNotRan()`, `shouldRun()`, `isFaked()`, `getFake()`, `resetFakes()`
- Context validation methods made static on `InvokableBehavior`
- `EventCollection` extending Eloquent Collection for `MachineEvent`
- `OrderMachine` test stub

### Changed
- **Breaking:** Facade renamed from `MachineFacade` to `EventMachine`
- **Breaking:** `__invoke()` renamed to `definition()` in behaviors and guards
- **Breaking:** `$requiredContext` made static in guards and action classes
- `InvokableBehavior`: centralized parameter injection with dependency injection support for `EventBehavior`, `ContextManager`, `State`, and `EventCollection`
- Incremental context storage using `arrayRecursiveDiff` and `arrayRecursiveMerge`

## [1.7.0] - 2024-11-04

### Added
- `Fakeable` trait for mocking `InvokableBehavior` in tests
- `make()` and `run()` static methods on `InvokableBehavior`
- `shouldPersist` property on `MachineDefinition` for optional event persistence
- `GenerateUmlCommand` for automatic UML diagram generation (`php artisan machine:generate-uml`)
- `stopOnFirstFailure()` method on `EventBehavior`
- Dependency injection for behaviors: `EventBehavior`, `ContextManager`, `State` injectable into `__invoke()`

### Changed
- Replaced deprecated `nunomaduro/larastan` with `larastan/larastan`
- Removed `__invoke` from `ActionBehavior` (moved to `InvokableBehavior`)

## [1.6.0] - 2024-09-04

### Added
- `machines()` method on models as alternative to `$machines` property for defining machine relationships
- `findMachine()` method on `HasMachines` trait

## [1.5.0] - 2024-08-23

### Fixed
- `InvokableBehavior`: improved type resolution for behavior dependency injection

## [1.4.0] - 2024-08-13

### Fixed
- `MachineCast`: handle uninitialized machines in `set()` method
- Added parentheses to object instantiations

## [1.3.0] - 2024-07-31

### Added
- Laravel 11 support
- PHP 8.3 support
- Updated Pest, Testbench, and Infection versions for compatibility

## [1.2.0] - 2024-07-08

### Added
- Behavior dependency injection: `EventBehavior`, `ContextManager`, `State`, `EventCollection` injectable into action and guard behaviors
- `EventCollection` class for typed MachineEvent collections
- `InvokableBehavior`: `make()` and `run()` static methods
- `shouldPersist` property on `MachineDefinition`
- `GenerateUmlCommand` for UML diagram generation
- `stopOnFirstFailure()` on `EventBehavior`
- Incremental context storage (`arrayRecursiveDiff`, `arrayRecursiveMerge`)
- Calculator machine test stub

### Changed
- Removed `__invoke` from `ActionBehavior` (moved to `InvokableBehavior`)
- Replaced `nunomaduro/larastan` with `larastan/larastan`
- `ResultBehavior` definition changed to invokable method

## [1.1.0] - 2023-12-13

### Changed
- Removed guard start internal event logging (reduced noise in event store)

## [1.0.1] - 2023-12-05

### Fixed
- `MachineDefinition`: `buildState` replaced with `setCurrentStateDefinition`
- `$targetStateDefinition` assignment control improved
- Target state entry actions fix

## [1.0.0] - 2023-11-22

### Added
- Initial release of EventMachine
- `MachineDefinition` for defining state machine blueprints with states, transitions, and behaviors
- `Machine` actor for runtime state machine execution with database persistence
- `State` actor for tracking current state, context, and event history
- `StateDefinition` and `TransitionDefinition` for declarative state/transition configuration
- `InvokableBehavior` base class with `ActionBehavior`, `GuardBehavior`, `EventBehavior`, and `ResultBehavior`
- Hierarchical (nested) states with parent/child relationships
- Always transitions (eventless, condition-based)
- Entry and exit actions on states
- Raised events (internal event queue processing)
- Guard conditions for transition control
- Context management with `ContextManager`
- Scenario testing support
- Transactional transitions with database locking
- `MachineEvent` Eloquent model with `machine_events` migration
- `HasMachines` trait for Eloquent model integration
- `MachineCast` for automatic machine serialization on models
- `MachineServiceProvider` for Laravel service container registration
- Traffic light, elevator, and calculator example machines
