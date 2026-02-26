# Changelog

All notable changes to `event-machine` will be documented in this file.

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
