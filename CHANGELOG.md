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
