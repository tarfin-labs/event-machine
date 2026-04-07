# Machine Definition Versioning — Hybrid Version-Per-Definition + Optional Migration

**Status:** Draft
**Date:** 2026-04-03

---

## Table of Contents

1. [Problem](#1-problem)
2. [Design Principles](#2-design-principles)
3. [Industry Context](#3-industry-context)
4. [Terminology](#4-terminology)
5. [Version Identity and Resolution](#5-version-identity-and-resolution)
6. [Database Schema Changes](#6-database-schema-changes)
7. [Version-Aware Restore](#7-version-aware-restore)
8. [Version Registry](#8-version-registry)
9. [Compatibility Analysis](#9-compatibility-analysis)
10. [Machine Migrations (Laravel Migration Pattern)](#10-machine-migrations-laravel-migration-pattern)
11. [Migration Execution](#11-migration-execution)
12. [Gradual Migration](#12-gradual-migration)
13. [Completed Instance Reactivation](#13-completed-instance-reactivation)
14. [Event Upcasting](#14-event-upcasting)
15. [Version Lifecycle Management](#15-version-lifecycle-management)
16. [Artisan Commands](#16-artisan-commands)
17. [Testing Infrastructure](#17-testing-infrastructure)
18. [HTTP Endpoint Behavior](#18-http-endpoint-behavior)
19. [Interaction with Existing Systems](#19-interaction-with-existing-systems)
20. [Failure Modes and Recovery](#20-failure-modes-and-recovery)
21. [Configuration](#21-configuration)
22. [File Structure](#22-file-structure)
23. [Scenario Matrix](#23-scenario-matrix)
24. [Quality Gate](#24-quality-gate)

---

## 1. Problem

Machine definitions evolve. States are added, removed, renamed, split, or merged. Transitions change. New business logic introduces new paths. Today, EventMachine has no mechanism to handle this evolution safely for in-flight instances.

### Real-World Scenarios

**Scenario A — State Split (additive):**
A machine `A → B → C` is refactored to `A → B1 → B2 → C`. New instances are fine. But what happens to instances currently waiting in state `B`? State `B` no longer exists in the definition. Restore fails.

**Scenario B — Side-by-Side Versions:**
Business wants old instances (`A → B → C`) to complete on their original flow, while new instances follow the new flow (`A → B1 → B2 → C`). Two definitions must coexist.

**Scenario C — Forced Migration:**
Business wants ALL instances (old and new) to follow the new flow. Instances in `B` must be moved to `B1` in the new definition.

**Scenario D — Final State Reactivation:**
A machine that was in final state `C` must now continue — a post-launch business decision requires additional processing. The definition is updated: `C` is no longer final, a new transition `C → D → E` is added. Already-completed instances must resume.

**Scenario E — Backward-Incompatible Context Change:**
New flow `A → B1 → B2 → C` requires a context key `intermediateResult` that doesn't exist in old instances. Migration must supply a default or compute it.

### Current State

- `MachineDefinition::$version` — metadata-only string, used solely for XState export description
- `EventBehavior::$version` — per-event version integer, persisted in `machine_events.version`, but not used functionally
- `machine_events` table has no `definition_version` column
- Restore (`restoreStateFromRootEventId`) always uses the current (latest) definition
- No mechanism to keep old definitions available at runtime
- No mechanism to detect or handle definition incompatibility

---

## 2. Design Principles

| Principle | Rationale |
|-----------|-----------|
| **Safe by default** | Without explicit migration, old instances continue on old definitions. No silent breakage. |
| **Version-per-definition is the foundation** | Every persisted instance remembers which definition version created it. Restore uses the correct definition. |
| **Migration is opt-in** | In-flight migration requires explicit developer intent — a MachineMigration class with state mappings and optional context transformers. Never automatic. |
| **Compatibility is analyzable** | Before migration, developers can check which instances are compatible with the new definition and which need mapping. |
| **Event history is immutable** | Migration never rewrites event history. A migration event is appended, recording the version transition. |
| **Replay is not migration** | Replaying old events against a new definition is fundamentally different from migrating state. Replay may produce different outcomes, lose side effects, or fail on removed transitions. Migration operates on current state, not history. |
| **Completed instances are inert by default** | Final-state instances are not affected by definition changes unless explicitly reactivated via migration. |
| **Zero overhead when unused** | If a machine has only one version (the common case), the system behaves exactly as today. No extra queries, no registry lookups. |

---

## 3. Industry Context

This design is informed by how major workflow engines solve the same problem:

| System | Approach | In-Flight Handling | Migration |
|--------|----------|-------------------|-----------|
| **SCXML** | No versioning in spec | N/A | N/A |
| **XState** | `version` metadata-only; community `xstate-migrate` uses JSON Patch | Manual | JSON Patch transforms |
| **Temporal** | Patching API (in-code branching), Worker Versioning (pin workers to versions), Continue-as-New upgrade | Pinned to version or patched | Version-aware code branching |
| **AWS Step Functions** | Immutable versions + aliases with traffic routing | Pinned to start version | No in-flight migration; drain old |
| **Camunda/Zeebe** | Version-per-deployment + Migration Plans (REST API, state mapping, batch) | Pinned to version | Explicit migration plans with validation |
| **Netflix Conductor** | Integer version per definition; snapshot-at-start | Pinned to version | No migration |
| **Cadence** | GetVersion() branching (like Temporal) | Patched in-code | Version-aware code branching |
| **Airflow 3.0** | Automatic DAG versioning; bundle pinning | Pinned to version | Opt-in latest bundle |

**Academic (Rinderle-Ma & Reichert):** Formalized "compliance" — an instance is compliant with a new schema if its execution trace is reproducible. Non-compliant instances require: Wait, Skip, Undo, or Defer strategies.

**Our approach maps closest to Camunda:** version-per-definition as default, explicit migration plans for in-flight instances. But unlike Camunda (BPMN XML), our definitions are PHP code — we can leverage type safety, closures, and Laravel's container.

---

## 4. Terminology

| Term | Definition |
|------|------------|
| **Definition version** | A string identifier for a specific configuration of a machine definition (states, transitions, behaviors). Follows semver convention but is opaque to the framework. |
| **Active version** | The definition version used for new instances. Exactly one per machine class. |
| **Pinned version** | The definition version an in-flight instance was created with and will continue to use until migration or completion. |
| **Version registry** | A machine class's collection of all available definition versions, keyed by version string. |
| **Compatible instance** | An instance whose current state exists in the target definition, has the same state type, and (if non-final) has at least one outgoing transition with a matching event type. See Section 9.1 for full criteria. Can be migrated without mapping. |
| **Incompatible instance** | An instance whose current state does not exist in the target definition. Requires a machine migration with state mapping. |
| **Machine migration** | A developer-defined class (like a Laravel DB migration) with an exhaustive `plan()` declaring what happens to every source state: `auto()`, `to(target)`, or `skip()`. Lives in `app/Machines/Migrations/`. |
| **Migration event** | A `DEFINITION_MIGRATED` event appended to an instance's history when it is migrated, recording the version transition. |
| **Drained version** | A definition version with zero active (non-final) instances. Safe to remove from the registry. |
| **JIT migration** | Just-in-time migration: when an event arrives for an un-migrated instance, the migration is applied inline before (or after) event processing. |
| **Background sweep** | A scheduled job that gradually migrates dormant instances in configurable batches, respecting backpressure. |
| **Event upcasting** | Transforming an old-format event payload to the current format via `upcastToV{N}` methods on the EventBehavior class. |
| **Breaking change** | An event version transition that cannot be automatically upcasted — the sender must provide new required fields. Declared via `BreakingChange` return type. |

---

## 5. Version Identity and Resolution

### 5.1 Version String Format

Version strings are developer-defined and opaque to the framework. Recommended convention is semver (`1.0.0`, `2.1.0`), but any non-empty string is valid. The framework performs string equality comparison, not semver parsing.

**Important:** The default version for machines without explicit versioning is `'1'`. When adding a version registry, the first version entry **must** use the string `'1'` (not `'1.0.0'`) to match existing persisted events. `machine:validate` warns if the registry's first version string doesn't match persisted `definition_version` values.

```php
MachineDefinition::define(
    config: [
        'id'      => 'order_workflow',
        'version' => '2',  // ← now functional, not just metadata
        'initial' => 'pending',
        'states'  => [...],
    ],
    behavior: [...],
)
```

### 5.2 Default Version

If no `version` is specified in config, the framework assigns `'1'` as the default version. This ensures backward compatibility — existing machines without explicit versions are treated as version `'1'`.

### 5.3 Version in MachineDefinition

The existing `$version` property on `MachineDefinition` becomes functional:

```php
// Before: metadata only, nullable
public ?string $version;

// After: required, defaults to '1'
public string $version;
```

### 5.4 Active Version Resolution

**New parameter:** `Machine::create()` gains an optional `definitionVersion` parameter:

```php
public static function create(
    MachineDefinition|array|null $definition = null,
    State|string|null $state = null,
    ?string $definitionVersion = null,  // NEW
): self;
```

**Resolution rules:**

When `Machine::create()` is called without a `state` parameter (new instance), the **active version** is resolved:

1. If `definitionVersion` is explicitly passed → use that version from the registry. Throws `DefinitionVersionNotFoundException` if not found.
2. If the machine class defines a `versions()` registry → use the version marked as `active: true`
3. If no registry exists (single-version machine) → use the definition from `definition()`
4. The resolved version's definition is used for the new instance

When `Machine::create(state: $rootEventId)` is called (restore), the **pinned version** is resolved from the persisted `definition_version`. The `definitionVersion` parameter is ignored in restore mode.

This parameter is used by:
- HTTP create endpoints (when `allow_version_override` is enabled, Section 16.3)
- `ScenarioPlayer` (when `$definitionVersion` is set on the scenario, Section 17.5)
- Direct API usage for testing or gradual rollout

---

## 6. Database Schema Changes

### 6.1 `machine_events` — Add `definition_version`

```php
// New migration
Schema::table('machine_events', function (Blueprint $table) {
    $table->string('definition_version', 50)
          ->after('version')
          ->default('1')
          ->index();
});
```

Every event persisted going forward includes the definition version that produced it. Existing rows default to `'1'`.

**Large table caveat:** For production tables with millions of rows, adding a non-nullable column with a default may lock the table. Recommended approach: (1) add as `nullable()` first, (2) backfill in batches, (3) alter to non-nullable with default. For MySQL, consider `pt-online-schema-change` or `gh-ost`. The published migration stub uses the simple approach; consumers should adapt for their scale.

### 6.2 `machine_current_states` — Add `definition_version`

```php
Schema::table('machine_current_states', function (Blueprint $table) {
    $table->string('definition_version', 50)
          ->after('machine_class')
          ->default('1');
});
```

Enables efficient querying: "which instances are on version X?" without scanning `machine_events`.

### 6.3 New Table: `machine_migration_batches`

Tracks which machine migrations have been executed (analogous to Laravel's `migrations` table). Batch-level summary, NOT per-instance.

```php
Schema::create('machine_migration_batches', function (Blueprint $table) {
    $table->id();
    $table->string('migration');            // filename without path
    $table->string('machine_class');
    $table->string('from_version', 50);
    $table->string('to_version', 50);
    $table->unsignedInteger('batch');       // batch number (like Laravel)
    $table->unsignedInteger('migrated');    // count of migrated instances
    $table->unsignedInteger('skipped');     // count of skipped instances
    $table->unsignedInteger('failed');      // count of failed instances
    $table->dateTime('executed_at');
});
```

**Per-instance audit** is NOT stored in a separate table. It is already captured by the `DEFINITION_MIGRATED` event in `machine_events` (see Section 11.2 step 7), which records `from_version`, `to_version`, `from_state`, `to_state`, and `migration_class` in its payload. Querying migration history per instance: `WHERE type = 'DEFINITION_MIGRATED' AND root_event_id = ?`.

---

## 7. Version-Aware Restore

### 7.1 Current Behavior (Problem)

`restoreStateFromRootEventId()` always uses `$this->definition` — the current code's definition. If the definition has changed since the instance was created, restore may fail or produce incorrect state.

### 7.2 New Behavior

Restore reads `definition_version` from the last `machine_event` (highest `sequence_number` for the given `root_event_id`, consistent with existing restore behavior) and resolves the correct definition:

```php
public function restoreStateFromRootEventId(string $key): State
{
    $machineEvents    = $this->loadEvents($key);
    $lastMachineEvent = $machineEvents->last();

    // Resolve definition for this instance's pinned version
    $pinnedVersion = $lastMachineEvent->definition_version;
    $definition    = $this->resolveDefinition($pinnedVersion);

    // Use $definition for state/context restoration
    $state = new State(
        context: $this->restoreContext($lastMachineEvent->context, $definition),
        currentStateDefinition: $this->restoreCurrentStateDefinition(
            $lastMachineEvent->machine_value,
            $definition, // ← version-aware lookup
        ),
        currentEventBehavior: $this->restoreCurrentEventBehavior($lastMachineEvent, $definition),
        history: $machineEvents,
    );

    // ...rest unchanged
    return $state;
}
```

### 7.3 Definition Resolution

```php
protected function resolveDefinition(string $version): MachineDefinition
{
    // Fast path: if only one version exists and it matches, skip registry
    if ($this->definition->version === $version) {
        return $this->definition;
    }

    // Slow path: look up in version registry
    return static::versionRegistry()->get($version)
        ?? throw DefinitionVersionNotFoundException::build(static::class, $version);
}
```

### 7.4 Backward Compatibility

For machines that never define a version:
- `$version` defaults to `'1'`
- `definition_version` column defaults to `'1'`
- `resolveDefinition('1')` returns the single definition
- Zero behavior change

---

## 8. Version Registry

### 8.1 Single-Version Machines (Default)

Most machines have one version. They override `definition()` as today:

```php
class OrderMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'order',
                'version' => '1',
                // ...
            ],
        );
    }
}
```

No registry needed. `resolveDefinition()` fast-paths.

### 8.2 Multi-Version Machines (Registry)

When a machine evolves, the developer adds a `versions()` method:

```php
class OrderMachine extends Machine
{
    public static function versions(): VersionRegistry
    {
        return VersionRegistry::for(static::class)
            ->version('1', fn () => MachineDefinition::define(
                config: [
                    'id'      => 'order',
                    'version' => '1',
                    'initial' => 'pending',
                    'states'  => [
                        'pending'    => ['on' => ['SUBMIT' => 'processing']],
                        'processing' => ['on' => ['COMPLETE' => 'done']],
                        'done'       => ['type' => 'final'],
                    ],
                ],
            ))
            ->version('2', fn () => MachineDefinition::define(
                config: [
                    'id'      => 'order',
                    'version' => '2',
                    'initial' => 'pending',
                    'states'  => [
                        'pending'      => ['on' => ['SUBMIT' => 'validating']],
                        'validating'   => ['on' => ['VALID' => 'processing', 'INVALID' => 'rejected']],
                        'processing'   => ['on' => ['COMPLETE' => 'done']],
                        'rejected'     => ['type' => 'final'],
                        'done'         => ['type' => 'final'],
                    ],
                ],
            ), active: true);
    }

    // definition() is auto-derived from versions() — returns the active version.
    // Developers do NOT override both.
}
```

### 8.3 `VersionRegistry` Class

```php
class VersionRegistry
{
    /** @var array<string, Closure(): MachineDefinition> */
    private array $versions = [];

    private ?string $activeVersion = null;

    /** @var array<string, MachineDefinition> Lazy-loaded cache */
    private array $resolved = [];

    public static function for(string $machineClass): self;

    /**
     * Register a definition version.
     *
     * @param  string  $version  The version string.
     * @param  Closure(): MachineDefinition  $factory  Lazy factory — definition only built on first access.
     * @param  bool  $active  Whether this is the active version for new instances.
     */
    public function version(string $version, Closure $factory, bool $active = false): self;

    /** Resolve a specific version's definition, building it lazily. */
    public function get(string $version): ?MachineDefinition;

    /** Get the active version string. */
    public function activeVersion(): string;

    /** Get the active version's definition. */
    public function activeDefinition(): MachineDefinition;

    /** List all registered version strings. */
    public function versions(): array;

    /** Check if a version exists. */
    public function has(string $version): bool;
}
```

Key design decisions:
- **Lazy factories** — old definitions are only built when needed (restore of an old instance). Zero cost if no old instances exist.
- **Exactly one active version** — enforced at build time. If zero or more than one version is marked `active: true`, `InvalidVersionRegistryException` is thrown. No implicit fallback.
- **Cache** — once a definition is built from its factory, it's cached for the request lifecycle.

### 8.4 `definition()` Auto-Derivation

When `versions()` is defined, `definition()` is automatically provided by the base `Machine` class:

```php
// In Machine base class
public static function definition(): MachineDefinition
{
    if (method_exists(static::class, 'versions')) {
        // Runtime guard: if subclass overrides BOTH, throw
        $defMethod = new \ReflectionMethod(static::class, 'definition');
        if ($defMethod->getDeclaringClass()->getName() !== Machine::class) {
            throw new InvalidVersionRegistryException(
                static::class . ' overrides both definition() and versions(). Use only one.'
            );
        }

        return static::versions()->activeDefinition();
    }

    throw MachineDefinitionNotFoundException::build();
}
```

Developers override EITHER `definition()` (single version) OR `versions()` (multi-version), never both. This is enforced at runtime — overriding both throws `InvalidVersionRegistryException`.

---

## 9. Compatibility Analysis

Before migrating instances, developers need to know which instances are compatible with the target definition.

### 9.1 Compatibility Check

An instance is **compatible** with a target definition if:
1. Its current state ID exists in the target definition
2. The state has the same type (ATOMIC, COMPOUND, PARALLEL, FINAL) in both definitions
3. For non-final states: at least one outgoing transition exists in the target definition
4. For non-final states: at least one outgoing event type from the source definition's transitions also exists as an outgoing event type in the target definition (ensures the instance can actually progress, not just structurally present)

An instance is **incompatible** if any of the above fail.

### 9.2 `CompatibilityReport` Value Object

```php
class CompatibilityReport
{
    public function __construct(
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public readonly int $totalInstances,
        public readonly int $compatibleCount,
        public readonly int $incompatibleCount,
        /** @var array<string, int> Incompatible instance counts grouped by current state */
        public readonly array $incompatibleByState,
        /** @var list<string> States that exist in source but not in target */
        public readonly array $removedStates,
        /** @var list<string> States that exist in target but not in source */
        public readonly array $addedStates,
        /** @var list<string> States that exist in both but changed type */
        public readonly array $changedTypeStates,
    ) {}

    public function isFullyCompatible(): bool;
    public function summary(): string;
}
```

### 9.3 `CompatibilityAnalyzer` Service

```php
class CompatibilityAnalyzer
{
    public function analyze(
        string $machineClass,
        string $fromVersion,
        string $toVersion,
        bool $includeFinal = false, // true for reactivation analysis
    ): CompatibilityReport;
}
```

Implementation:
1. Load both definitions from `VersionRegistry`
2. Query `machine_current_states` for instances of `$machineClass` with `definition_version = $fromVersion`. By default, only non-final instances. When `$includeFinal = true`, include all instances (final-state rows are always present in `machine_current_states` — `syncCurrentStates()` retains them).
3. For each unique `state_id`, check compatibility against target definition (Section 9.1 criteria)
4. Build and return `CompatibilityReport`

---

## 10. Machine Migrations (Laravel Migration Pattern)

### 10.1 Design Philosophy — Why Laravel Migrations?

EventMachine is a Laravel package. Its users already understand `database/migrations/` — timestamped, sequential, tracked in a `migrations` table, discoverable by artisan. Machine definition migrations follow the **exact same mental model**:

| Laravel DB Migrations | EventMachine Migrations |
|----------------------|------------------------|
| `database/migrations/` | `app/Machines/Migrations/` (configurable) |
| `migrations` table (tracks which ran) | `machine_migration_batches` table |
| `php artisan migrate` | `php artisan machine:migrate` |
| `php artisan make:migration` | `php artisan make:machine-migration` |
| `php artisan migrate --pretend` | `php artisan machine:migrate --dry-run` |
| `php artisan migrate:status` | `php artisan machine:migrate-status` |
| Timestamp-prefixed filenames | Timestamp-prefixed filenames |
| Runs pending migrations in order | Runs pending migrations in order |
| Reversible via `down()` | Reversible via reverse migration class |

The key difference: Laravel DB migrations transform **schema**. Machine migrations transform **instance state** within the schema.

### 10.2 File Location & Discovery

```
app/Machines/Migrations/
    2026_04_01_000000_order_v1_to_v2.php
    2026_04_15_000000_order_v2_to_v3_reactivation.php
    2026_05_01_000000_car_sales_v1_to_v2.php
```

The path is configurable via `config/machine.php`:

```php
'versioning' => [
    'migrations_path' => app_path('Machines/Migrations'),
],
```

Discovery: the framework scans this directory, sorts by filename (timestamp order), and checks the `machine_migration_batches` tracking table for which have already been executed.

### 10.3 `StateMapping` Value Object

Each state in the source definition must be explicitly accounted for in the migration plan. `StateMapping` is a fluent VO that declares what happens to a state:

```php
class StateMapping
{
    /**
     * Keep the state as-is (exists in both versions, no transform needed).
     */
    public static function auto(): self;

    /**
     * Map to a different state in the target definition.
     */
    public static function to(string $target): self;

    /**
     * Skip this state — instances in this state are NOT migrated.
     * They continue on the old version until they drain naturally.
     */
    public static function skip(): self;

    /**
     * Attach a context transformer to this state mapping.
     * Called after state resolution, receives current context, returns modified context.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $transformer
     */
    public function transformContext(Closure $transformer): self;

    /**
     * Attach a per-instance filter. Return false to skip this specific instance.
     * Useful for conditional migration within a state (e.g., only instances created after a date).
     *
     * @param  Closure(array<string, mixed>): bool  $filter  Receives context, returns whether to migrate.
     */
    public function when(Closure $filter): self;
}
```

### 10.4 `MachineMigration` Abstract Class

```php
abstract class MachineMigration
{
    /** The machine class this migration targets. */
    abstract public function machine(): string;

    /** Source definition version. */
    abstract public function from(): string;

    /** Target definition version. */
    abstract public function to(): string;

    /**
     * Per-state migration plan.
     *
     * EVERY state in the source definition must be declared here.
     * The framework validates completeness at execution time —
     * unmapped states throw InvalidMigrationPlanException.
     *
     * @return array<string, StateMapping>
     */
    abstract public function plan(): array;

    /**
     * Whether this migration should include final-state instances.
     * Override to true for reactivation migrations.
     *
     * Execution order: includeFinal() controls the DB query filter
     * (only non-final instances are fetched unless true). Then
     * each StateMapping's when() filter is evaluated per-instance.
     */
    public function includeFinal(): bool
    {
        return false;
    }

    /**
     * Post-migration hook: runs after each instance is migrated.
     */
    public function afterMigrate(string $rootEventId, string $fromState, string $toState): void
    {
        // no-op by default
    }

    /**
     * Batch size for this migration.
     * Override to change from the default (500).
     */
    public function batchSize(): int
    {
        return 500;
    }
}
```

**Key design: `plan()` is exhaustive.** Every state in the source definition must appear. This prevents "forgotten state" bugs — the framework loads the source definition, extracts all state IDs, and validates that `plan()` covers them all. Missing states throw `InvalidMigrationPlanException` at execution time (not at file load — allows scaffolding with TODOs).

**Instance discovery:** The executor queries `machine_current_states` for instances matching `machine_class` and `definition_version = from()`. When `includeFinal()` is false (default), only non-final state rows are included. When `includeFinal()` is true, all rows are included (final-state instances always retain their `machine_current_states` rows — `syncCurrentStates()` never cleans them up). Archived instances are NOT included — use `machine:archive-status --restore` first if needed.

### 10.5 `make:machine-migration` — Definition-Diff-Aware Scaffold

The scaffold command compares the two definitions and generates a pre-filled migration with all states accounted for:

```
$ php artisan make:machine-migration OrderMachine --from=1 --to=2

 Analyzing OrderMachine v1 → v2...

 v1 states: pending, processing, done
 v2 states: pending, validating, processing, rejected, done

 ┌──────────────┬───────────────┬──────────────────────────────┐
 │ v1 State     │ Status        │ Suggested Action             │
 ├──────────────┼───────────────┼──────────────────────────────┤
 │ pending      │ ✓ in both     │ auto()                       │
 │ processing   │ ✓ in both     │ auto()                       │
 │ done         │ ✓ in both     │ auto() — final in both       │
 └──────────────┴───────────────┴──────────────────────────────┘

 New in v2 (no migration needed): validating, rejected

 Created: app/Machines/Migrations/2026_04_03_143200_order_v1_to_v2.php
```

Generated file — all states pre-filled with `auto()`:

```php
<?php

declare(strict_types=1);

use App\Machines\OrderMachine;
use Tarfinlabs\EventMachine\Versioning\MachineMigration;
use Tarfinlabs\EventMachine\Versioning\StateMapping;

return new class extends MachineMigration
{
    public function machine(): string { return OrderMachine::class; }
    public function from(): string { return '1'; }
    public function to(): string { return '2'; }

    public function plan(): array
    {
        return [
            // ✓ Exists in both v1 and v2
            'pending'    => StateMapping::auto(),
            'processing' => StateMapping::auto(),
            'done'       => StateMapping::auto(),
        ];
    }
};
```

When states are **removed** in the target, the scaffold flags them with `TODO`:

```
$ php artisan make:machine-migration OrderMachine --from=2 --to=3

 Analyzing OrderMachine v2 → v3...

 v2 states: pending, validating, processing, rejected, done
 v3 states: pending, review, approved, rejected, done

 ┌──────────────┬───────────────┬──────────────────────────────────┐
 │ v2 State     │ Status        │ Suggested Action                 │
 ├──────────────┼───────────────┼──────────────────────────────────┤
 │ pending      │ ✓ in both     │ auto()                           │
 │ validating   │ ✗ REMOVED     │ must map to v3 state or skip()   │
 │ processing   │ ✗ REMOVED     │ must map to v3 state or skip()   │
 │ rejected     │ ✓ in both     │ auto()                           │
 │ done         │ ✓ in both     │ auto() — final in both           │
 └──────────────┴───────────────┴──────────────────────────────────┘

 New in v3 (no migration needed): review, approved
 Type changed: (none)

 Created: app/Machines/Migrations/2026_04_03_150000_order_v2_to_v3.php
```

Generated file — removed states have `TODO` targets:

```php
return new class extends MachineMigration
{
    public function machine(): string { return OrderMachine::class; }
    public function from(): string { return '2'; }
    public function to(): string { return '3'; }

    public function plan(): array
    {
        return [
            // ✓ Exists in both v2 and v3
            'pending'    => StateMapping::auto(),
            'rejected'   => StateMapping::auto(),
            'done'       => StateMapping::auto(),

            // ✗ REMOVED in v3 — must map to a v3 state or skip()
            // Available v3 targets: pending, review, approved, rejected, done
            'validating' => StateMapping::to('TODO'),
            'processing' => StateMapping::to('TODO'),
        ];
    }
};
```

The framework validates `TODO` targets at execution time — `InvalidMigrationTargetException` is thrown if a `to()` target doesn't exist in the target definition. This forces developers to resolve all TODOs before running.

**Additional diff detection:**

| Diff Type | Scaffold Behavior |
|-----------|-------------------|
| State exists in both, same type | `auto()` pre-filled |
| State exists in both, **type changed** (e.g., ATOMIC → FINAL) | `auto()` with `// ⚠ type changed: atomic → final` comment |
| State removed in target | `to('TODO')` with available targets listed |
| State new in target | Listed as informational comment (no migration needed) |

### 10.6 Example: `A → B → C` to `A → B1 → B2 → C`

```php
// app/Machines/Migrations/2026_04_01_000000_order_v1_to_v2.php

return new class extends MachineMigration
{
    public function machine(): string { return OrderMachine::class; }
    public function from(): string { return '1'; }
    public function to(): string { return '2'; }

    public function plan(): array
    {
        return [
            'pending' => StateMapping::auto(),
            'processing' => StateMapping::to('validating')
                ->transformContext(fn (array $ctx) => [
                    ...$ctx,
                    'validationStatus' => 'pending_revalidation',
                ]),
            'done' => StateMapping::skip(),  // don't migrate completed instances
        ];
    }
};
```

### 10.7 Example: Final State Reactivation

```php
// app/Machines/Migrations/2026_04_15_000000_order_v2_to_v3_reactivation.php

return new class extends MachineMigration
{
    public function machine(): string { return OrderMachine::class; }
    public function from(): string { return '2'; }
    public function to(): string { return '3'; }

    public function includeFinal(): bool { return true; }

    public function plan(): array
    {
        return [
            'pending'    => StateMapping::auto(),
            'validating' => StateMapping::to('review'),
            'processing' => StateMapping::to('review'),
            'rejected'   => StateMapping::auto(),
            'done'       => StateMapping::to('awaiting_review')
                ->transformContext(fn (array $ctx) => [
                    ...$ctx,
                    'reactivatedAt'    => now()->toIso8601String(),
                    'reactivateReason' => 'compliance_review_required',
                ])
                ->when(fn (array $ctx) =>
                    ($ctx['completedAt'] ?? '') > '2026-01-01'
                ),
        ];
    }
};
```

### 10.8 Migration Tracking

Batch-level tracking uses the `machine_migration_batches` table (defined in Section 6.3). Per-instance audit uses `DEFINITION_MIGRATED` events in `machine_events` (no separate per-instance table needed — avoids data duplication).

`MigrationExecutor::execute()` writes a `machine_migration_batches` row after processing all instances for a migration, recording the aggregate counts.

### 10.9 Pending Migrations

```
$ php artisan machine:migrate-status

 Machine Migration Status
 ┌───────────────────────────────────────────────┬────────────┬───────────┐
 │ Migration                                     │ Machine    │ Status    │
 ├───────────────────────────────────────────────┼────────────┼───────────┤
 │ 2026_04_01_000000_order_v1_to_v2              │ Order      │ Ran (B1)  │
 │ 2026_04_15_000000_order_v2_to_v3_reactivation │ Order      │ Pending   │
 │ 2026_05_01_000000_car_sales_v1_to_v2          │ CarSales   │ Pending   │
 └───────────────────────────────────────────────┴────────────┴───────────┘
```

Running `php artisan machine:migrate` executes all pending migrations in timestamp order, exactly like `php artisan migrate`.

### 10.10 Selective Migration

```bash
# Run all pending migrations
php artisan machine:migrate

# Run only migrations for a specific machine
php artisan machine:migrate --machine=OrderMachine

# Run a specific migration file
php artisan machine:migrate --path=app/Machines/Migrations/2026_04_15_000000_order_v2_to_v3_reactivation.php

# Dry run
php artisan machine:migrate --dry-run

# Async (dispatch as queue jobs)
php artisan machine:migrate --async
```

---

## 11. Migration Execution

### 11.1 `MigrationExecutor` Service

```php
class MigrationExecutor
{
    /**
     * Execute a single machine migration.
     *
     * @return MigrationResult
     */
    public function execute(
        MachineMigration $migration,
        bool $dryRun = false,
        ?Closure $onProgress = null,
    ): MigrationResult;

    /**
     * Execute all pending migrations (like Laravel's Migrator).
     *
     * @return list<MigrationResult>
     */
    public function runPending(
        ?string $machineClass = null,
        bool $dryRun = false,
        ?Closure $onProgress = null,
    ): array;
}
```

### 11.2 Execution Steps (Per Instance)

For each instance matching the migration's filter:

1. **Acquire lock** — same lock mechanism as `Machine::send()`, using the configured lock TTL (`versioning.migration_lock_ttl`, default 30s). If lock unavailable, record as failed with reason `lock_unavailable`. Lock-failed instances are retried on subsequent `machine:migrate --retry-failed` invocations (NOT automatically retried within the same `execute()` call).
2. **Load current state** — read `machine_current_states` for the instance's state + `definition_version` (fast lookup), then load only the **last event** from `machine_events` for authoritative context data. Full event history restore is NOT needed — migration only requires current state and context, not replay. For parallel-dispatch machines where `machine_current_states` may be stale, the last event's `machine_value` is authoritative.
3. **Validate version** — confirm instance is on `migration->from()` version (from last event's `definition_version`)
4. **Resolve state mapping** — look up the instance's current state in `migration->plan()`. If the state is not in `plan()`, throw `InvalidMigrationPlanException` (exhaustive coverage required). If `StateMapping::skip()`, skip this instance. If `StateMapping::auto()`, verify the state passes ALL Section 9.1 compatibility criteria in the target definition. If `StateMapping::to($target)`, verify target exists in target definition. For parallel states, apply per-region independently (see Section 11.8). Evaluate `StateMapping::when()` filter if present — if it returns false, skip this instance.
5. **Transform context** — call `StateMapping::transformContext()` closure if attached. If transformer throws, record as failed (exception message in `MigrationFailure::reason`, full exception logged via `Log::error()`).
6. **Validate target state** — confirm mapped state exists in target definition, type is correct
7. **Begin DB transaction** — steps 7a-7c are atomic:

   7a. **Append migration event** — persist a `DEFINITION_MIGRATED` event to `machine_events`:

```php
[
    'type'               => 'DEFINITION_MIGRATED',
    'source'             => 'internal:migration',
    'machine_value'      => $targetMachineValue, // array — single or multi for parallel
    'version'            => 1,
    'definition_version' => $migration->to(),
    'context'            => $transformedContext,
    'payload'            => [
        'from_version'    => $migration->from(),
        'to_version'      => $migration->to(),
        'from_state'      => $originalState,
        'to_state'        => $targetState,
        'migration_class' => get_class($migration),
        'reactivated'     => $wasReactivated, // true when source was FINAL, target is non-FINAL
    ],
]
```

   7b. **Update `machine_current_states`** — DELETE old composite-PK row(s) + INSERT new row(s) with updated `state_id` and `definition_version` (composite PK requires DELETE+INSERT, not UPDATE).

   7c. **Commit transaction**

8. **Call `afterMigrate()`** — post-migration hook (outside transaction — failure here does not roll back the migration)
9. **Release lock**

After all instances in a migration are processed, `MigrationExecutor` writes a summary row to `machine_migration_batches` with aggregate counts (migrated, skipped, failed).

### 11.8 Parallel State Migration

Parallel state instances have multi-element `machine_value` arrays (e.g., `['region1.stateA', 'region2.stateB']`).

**Rules:**
- `plan()` applies **per-region** — each region state is looked up independently
- Compatibility check must pass for **ALL** region states
- If any region state is incompatible and has no mapping, the entire instance fails migration
- The `DEFINITION_MIGRATED` event records the full multi-state `machine_value` array

**Example:**
```php
public function plan(): array
{
    return [
        'region1.processing' => StateMapping::to('region1.validating'),
        'region2.waiting'    => StateMapping::auto(),
    ];
}
```

**`transformContext()` for parallel states:** Called once with `fromState` and `toState` as the **serialized** state value (e.g., `'region1.processing,region2.waiting'` → `'region1.validating,region2.waiting'`). The transformer receives the full comma-joined state strings, not individual region states. This matches how `machine_value` is stored as a JSON array.

**Restriction:** Migration is only allowed when the instance is in a stable state (not mid-transition). The lock acquisition in step 1 ensures this — if the machine is actively processing events, the lock will be held by `Machine::send()`.

### 11.3 `MigrationResult` Value Object

```php
class MigrationResult
{
    public function __construct(
        public readonly string $machineClass,
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public readonly bool $dryRun,
        public readonly int $migrated,
        public readonly int $skipped,    // shouldMigrate() returned false
        public readonly int $failed,     // lock unavailable, incompatible, etc.
        public readonly int $alreadyOnTarget, // already on target version
        /** @var array<string, int> Migrated count grouped by state transition (e.g., "processing → validating": 42) */
        public readonly array $migratedByTransition,
        /** @var list<MigrationFailure> Details of failed instances */
        public readonly array $failures,
        public readonly float $durationSeconds,
    ) {}

    public function summary(): string;
}
```

### 11.4 `MigrationFailure` Value Object

```php
class MigrationFailure
{
    public function __construct(
        public readonly string $rootEventId,
        public readonly string $currentState,
        public readonly string $reason,
    ) {}
}
```

### 11.5 Dry Run

When `$dryRun = true`:
- All steps execute except persistence (no events written, no state updated)
- `MigrationResult` reflects what WOULD happen
- Allows developers to validate the migration before committing
- No locks acquired in dry run

**Concurrency caveat:** Dry-run results are point-in-time snapshots. Without locks, concurrent event processing may change instance states between dry-run analysis and actual migration execution. Dry-run counts are advisory — actual migration results may differ for high-throughput machines.

### 11.6 Batch Processing

Large machines (10k+ instances) are migrated in batches:
- Default batch size: 500
- Each batch is a separate DB transaction
- `$onProgress` closure called after each batch with `(int $processed, int $total)`
- If a batch fails, previously migrated instances remain migrated (no rollback of successful batches)
- Failed instances are retried in subsequent `execute()` calls

### 11.7 Async Migration via Job

For very large migrations, a queue job is provided:

```php
class MigrationJob implements ShouldQueue
{
    public function __construct(
        public readonly string $migrationFilePath, // file path, not class name
    ) {}

    public function handle(MigrationExecutor $executor): void
    {
        // Resolve anonymous class from file (like Laravel's Migrator)
        $migration = require $this->migrationFilePath;
        $executor->execute($migration);
    }
}
```

Anonymous-class migrations (the Laravel pattern) cannot be referenced by class name. `MigrationJob` accepts the **file path** instead and resolves the migration instance at runtime — exactly how Laravel's `Migrator` handles anonymous-class DB migrations.

Dispatched via artisan command:

```bash
php artisan machine:migrate --async
```

---

## 12. Gradual Migration

Batch migration (`machine:migrate`) is suitable for planned, one-time migrations. But for production systems with millions of instances, a gradual approach avoids DB pressure and downtime.

### 12.1 Two-Layer Architecture

| Layer | When | How | Instances |
|-------|------|-----|-----------|
| **Background sweep** | Scheduled job, runs every N seconds | Picks batch of un-migrated instances, migrates them | Dormant instances |
| **Just-in-time (JIT)** | When an event arrives for an un-migrated instance | Inline migration inside `Machine::send()` before event processing | Active instances |

Together, these ensure:
- **Active instances** are migrated instantly on first event (zero delay)
- **Dormant instances** are gradually migrated by the sweep (no rush)
- **Archived instances** are migrated when auto-restored (JIT on restore)
- **No big-bang required** — migration happens organically over time

### 12.2 Background Sweep Job

A scheduled job that runs periodically and migrates a configurable number of instances per tick:

```php
class MigrationSweepJob implements ShouldQueue
{
    public function handle(MigrationExecutor $executor): void
    {
        $pendingMigrations = $executor->discoverPending();

        foreach ($pendingMigrations as $migration) {
            $result = $executor->execute(
                migration: $migration,
                batchSize: config('machine.versioning.sweep.batch_size', 100),
            );

            // Backpressure: stop if system is under load
            if ($this->isBackpressured()) {
                return; // continue next tick
            }
        }
    }

    private function isBackpressured(): bool
    {
        $queueDepth = Queue::size('default');
        return $queueDepth > config('machine.versioning.sweep.backpressure_threshold', 1000);
    }
}
```

Registered via `MachineScheduler` (like timer sweep):

```php
// In MachineServiceProvider or MachineScheduler
$schedule->job(new MigrationSweepJob())
    ->everyMinute()
    ->when(fn () => config('machine.versioning.sweep.enabled', false));
```

**Key behaviors:**
- Processes one pending migration at a time (timestamp order)
- Respects `batchSize()` per migration
- Stops processing if queue backpressure exceeds threshold
- Idempotent — already-migrated instances are skipped (version check)
- Does NOT process archived instances — they are migrated on restore via JIT

### 12.3 Just-in-Time Migration in `Machine::send()`

When an event arrives for an instance that has a pending migration, the migration is applied **inline before event processing**:

```php
// Inside Machine::send() — after restore, before event processing
public function send(string|EventBehavior $event, ...): State
{
    // 1. Acquire lock
    // 2. Restore state (pinned version, e.g., v1)

    // 3. JIT migration check
    $pinnedVersion = $this->state->definitionVersion;
    $pendingMigration = $this->findPendingMigration($pinnedVersion);

    if ($pendingMigration !== null) {
        $this->applyJitMigration($pendingMigration);
        // Instance is now on v2 — definition swapped
    }

    // 4. Process event against current (possibly migrated) definition
    // 5. Persist
    // 6. Release lock
}
```

**`applyJitMigration()` flow:**

1. Look up instance's current state in `migration->plan()`
2. If `StateMapping::skip()` → do NOT migrate, process event on old version
3. If `StateMapping::auto()` or `StateMapping::to()`:
   a. Apply state mapping
   b. Apply context transform (if any)
   c. Evaluate `when()` filter (if any) — if false, skip migration
   d. Append `DEFINITION_MIGRATED` event
   e. Update `machine_current_states` (DELETE+INSERT)
   f. Swap `$this->definition` to the target version
4. Event processing continues with the new definition

**All within the same lock** — no race conditions. The lock is already held by `Machine::send()`.

### 12.4 JIT Migration for Archived Instances

When an archived instance receives a new event, the existing auto-restore path (`ArchiveService::restoreMachine()`) fires. After restore, the JIT migration check in `Machine::send()` detects the pending migration and applies it inline.

No special handling needed — the existing auto-restore + JIT migration chain covers this naturally:

```
Event arrives for archived instance
→ restoreStateFromRootEventId()
  → Events not found in active table
  → restoreFromArchive() (auto-restore)
  → Events now in active table
→ JIT migration check → pending migration found → apply
→ Process event on new version
```

### 12.5 "Process First, Migrate After" — Event Compatibility

A critical edge case: the incoming event may not be valid in the target version (different payload schema, removed transition). In this case, JIT migration would break event processing.

**Rule: process first, migrate after** when event compatibility is uncertain:

```
1. Event arrives
2. Restore (v1)
3. Pending migration exists
4. Check: does the event type exist in v2 for the mapped target state?
5. YES → JIT migrate, then process event on v2
6. NO → process event on v1 first, then check if resulting state is migratable
   → If migratable → append DEFINITION_MIGRATED after event processing
   → If not → leave on v1, sweep will handle later
```

This ensures events are never rejected due to JIT migration. The instance migrates when safe, stays on old version when not.

### 12.6 Migration Progress Tracking

The sweep job and JIT migration both write to `machine_migration_batches`. Progress can be monitored:

```
$ php artisan machine:migrate-status

 ┌──────────────────────────────────┬──────────┬───────────┬─────────┬──────────┐
 │ Migration                        │ Machine  │ Migrated  │ Pending │ Progress │
 ├──────────────────────────────────┼──────────┼───────────┼─────────┼──────────┤
 │ 2026_04_01_order_v1_to_v2        │ Order    │ 847,231   │ 12,769  │ 98.5%    │
 │ 2026_04_15_order_v2_to_v3        │ Order    │ 0         │ 860,000 │ 0.0%     │
 └──────────────────────────────────┴──────────┴───────────┴─────────┴──────────┘
```

---

## 13. Completed Instance Reactivation

### 12.1 Problem

When a final-state instance needs to resume (Scenario D), it's not enough to just remap the state. The machine must be able to accept events again. This means:

1. The target state in the new definition must NOT be final (or must have outgoing transitions)
2. `machine_current_states` must be updated so timers/schedules can find the instance
3. The machine must be "unlocked" for new events

### 12.2 Reactivation via Migration

Reactivation is a special case of migration where `shouldMigrate()` returns `true` for final-state instances and the target state is non-final:

```php
class OrderReactivation extends MachineMigration
{
    public function machine(): string { return OrderMachine::class; }
    public function from(): string { return '2'; }
    public function to(): string { return '3'; }

    public function includeFinal(): bool { return true; }

    public function plan(): array
    {
        return [
            // Other states auto-mapped or skipped as needed...
            'done' => StateMapping::to('awaiting_review')  // FINAL → ATOMIC
                ->when(fn (array $ctx) =>
                    ($ctx['completedAt'] ?? '') > '2026-01-01'
                ),
        ];
    }
}
```

### 12.3 Reactivation Validation

The `MigrationExecutor` validates reactivation safety:
- Target state must exist in target definition
- If source state was FINAL and target state is non-FINAL: the `reactivated` flag in the migration event payload (Section 11.2 step 7a) is set to `true`
- `machine_current_states` row is updated via DELETE+INSERT (rows always exist for final states — `syncCurrentStates()` retains them)

---

## 14. Event Upcasting

### 14.1 Problem

Events are the machine's public API. When an event's payload contract changes across definition versions, external systems sending old-format events will break. Event upcasting provides backward compatibility by transforming old payloads to the current format.

### 14.2 `_eventVersion` Convention

Event payloads can include an optional `_eventVersion` field:

| `_eventVersion` value | Meaning |
|---|---|
| Absent | Treated as version `1` (backward compatible — all existing events work unchanged) |
| `1` | Explicitly v1 format |
| `2`, `3`, ... | Explicitly that version's format |

For HTTP endpoints, `_eventVersion` is a top-level field in the request body:

```json
POST /api/orders/01JQKX.../submit
{
    "orderId": 1,
    "amount": 100,
    "_eventVersion": 1
}
```

For programmatic event sending (`Machine::send()`, `sendTo()`, `dispatchTo()`), the event version comes from the `EventBehavior::$version` property on the event class being sent.

### 14.3 Upcast Chain — `upcastToV{N}` Methods

Each version transition is defined as a separate method on the `EventBehavior` class. The method MUST exist for every version from `2` to the current `$version`. Missing methods throw `MissingUpcastDefinitionException` at definition validation time.

```php
class SubmitEvent extends EventBehavior
{
    public int $version = 3; // current version

    public string $orderId;
    public float $amount;
    public string $currency;   // added in v2
    public string $taxNumber;  // added in v3

    // v1 → v2: currency added, can be defaulted
    protected static function upcastToV2(array $payload): array
    {
        $payload['currency'] ??= 'TRY';
        return $payload;
    }

    // v2 → v3: taxNumber added, CANNOT be computed
    protected static function upcastToV3(array $payload): BreakingChange
    {
        return BreakingChange::requires('taxNumber');
    }
}
```

**Return type determines behavior:**

| Return type | Meaning |
|---|---|
| `array` | Upcast successful — transformed payload returned |
| `BreakingChange` | Cannot upcast — sender must upgrade to this version |

### 14.4 `BreakingChange` Value Object

```php
class BreakingChange
{
    /**
     * The transition requires specific fields that the sender must provide.
     */
    public static function requires(string ...$fields): self;

    /**
     * The transition is breaking for a custom reason.
     */
    public static function because(string $reason): self;

    /** @return list<string> */
    public function missingFields(): array;

    public function reason(): string;
}
```

### 14.5 Chain Execution

When an event arrives with `_eventVersion` (or absent = 1) lower than the EventBehavior's current `$version`, the framework executes the upcast chain sequentially:

```
Event: {orderId: 1, amount: 100}, _eventVersion absent (= v1)
EventBehavior current version: 3

Step 1: upcastToV2({orderId: 1, amount: 100})
  → returns {orderId: 1, amount: 100, currency: 'TRY'} ✓

Step 2: upcastToV3({orderId: 1, amount: 100, currency: 'TRY'})
  → returns BreakingChange::requires('taxNumber') ✗

Result: EventUpcastException
  event: SubmitEvent
  fromVersion: 1
  blockedAtVersion: 3
  successfulUpcastsUpTo: 2
  missingFields: ['taxNumber']
  message: "SubmitEvent v1 cannot be upcasted to v3.
            Successfully upcasted: v1 → v2 (automatic).
            Blocked at v3: taxNumber must be provided by sender."
```

If the full chain succeeds (all steps return `array`), the final payload is validated against the current EventBehavior class. If validation still fails, it's a normal validation error (not version-related).

### 14.6 Chain Execution Examples

**v2 event arrives at v3 instance:**
```
upcastToV3({orderId: 1, amount: 100, currency: 'EUR'})
  → BreakingChange::requires('taxNumber')

Error: "SubmitEvent v2 → v3: taxNumber must be provided."
```

**v3 event arrives at v3 instance:**
```
No upcast needed. Validate directly against SubmitEvent.
Missing taxNumber → normal validation error (not version-related).
```

**v1 event arrives at v2 instance (no breaking changes):**
```
upcastToV2({orderId: 1, amount: 100})
  → {orderId: 1, amount: 100, currency: 'TRY'} ✓

Full chain succeeded. Validate against SubmitEvent v2. Passes. Process event.
```

### 14.7 Zero Overhead for v1-Only Events

Most events never evolve. For these:
- `$version = 1` (default, already exists in EventBehavior)
- No `upcastToV{N}` methods defined (none needed)
- No `_eventVersion` in payload (absent = v1)
- Zero overhead — no upcast chain executed
- Everything works exactly as today

### 14.8 HTTP Error Response

When upcast fails, the HTTP response includes version-aware information:

```json
{
    "error": "event_version_mismatch",
    "status": 422,
    "definitionVersion": "2",
    "event": "SUBMIT",
    "eventVersion": {
        "sent": 1,
        "current": 3,
        "upcastedTo": 2,
        "blockedAt": 3
    },
    "missingFields": ["taxNumber"],
    "message": "SubmitEvent v1 cannot be upcasted to v3. taxNumber must be provided."
}
```

### 14.9 Interaction with JIT Migration

Event upcasting and JIT migration are independent but complementary:

1. **JIT migration** changes the machine's **definition version** (state mapping, context transform)
2. **Event upcasting** changes the event's **payload format** (field additions, defaults)

They can both happen in the same `Machine::send()` call:

```
1. Event arrives: SUBMIT v1 payload, instance pinned to definition v1
2. JIT migration: instance migrated from definition v1 → v2
3. Event upcast: SUBMIT payload upcasted from event v1 → v2
4. Event processed against definition v2 with v2 payload
```

The order matters: **JIT migration first, then event upcast.** The event is upcasted to the version expected by the (possibly migrated) definition.

### 14.10 Event Version in Persisted Events

The `machine_events.version` column (already exists, currently unused) stores the **original event version as sent by the sender**, not the upcasted version. This preserves the audit trail — you can see "this event was sent as v1 and upcasted to v2."

The upcasted payload is what gets stored in `machine_events.payload` — the actual data that was processed.

### 14.11 Pure Event Version Bump (States Unchanged)

When only the event contract changes (no state changes), a new definition version is still required:

```
v1: pending → (SUBMIT {orderId, amount})    → processing → done
v2: pending → (SUBMIT {orderId, amount, currency}) → processing → done
```

The migration is a pure version bump with all `auto()` states:

```php
return new class extends MachineMigration
{
    public function machine(): string { return OrderMachine::class; }
    public function from(): string { return '1'; }
    public function to(): string { return '2'; }

    public function plan(): array
    {
        return [
            'pending'    => StateMapping::auto(),
            'processing' => StateMapping::auto(),
            'done'       => StateMapping::auto(),
        ];
    }
};
```

`make:machine-migration` detects this pattern and suggests adding upcast methods:

```
 State changes: (none — all states identical)
 Event changes:
   SUBMIT: +currency (required in v2, not in v1)

 This is a pure version bump (no state changes).
 Consider adding upcastToV2() to SubmitEvent for backward compatibility.
```

---

## 15. Version Lifecycle Management

### 15.1 Version States

A definition version progresses through a lifecycle:

```
active → deprecated → drained → removed
```

| State | Meaning |
|-------|---------|
| **active** | Used for new instances. Exactly one per machine. |
| **deprecated** | No new instances. Existing instances continue. Target for migration. |
| **drained** | Zero non-final instances remain. Safe to remove. |
| **removed** | Definition factory removed from `versions()`. Cannot restore instances (data remains in DB for audit). |

### 15.2 Detecting Drained Versions

```php
// Via VersionRegistry
$registry->isDrained('1'); // queries machine_current_states for non-final instances on version '1'
```

### 15.3 Deprecation

When a new active version is set, the previous active version becomes implicitly deprecated. No explicit API needed — the `active: true` flag on the new version is sufficient.

---

## 16. Artisan Commands

### 16.1 `machine:version-status`

Shows versioning status for a machine class.

```
$ php artisan machine:version-status OrderMachine

 Order Machine — Version Status
 ┌─────────┬──────────┬────────────┬───────────┬─────────────┐
 │ Version │ Status   │ Active     │ In-Flight │ Final       │
 ├─────────┼──────────┼────────────┼───────────┼─────────────┤
 │ 1       │ drained  │            │ 0         │ 1,247       │
 │ 2       │ active   │ ●          │ 342       │ 8,531       │
 └─────────┴──────────┴────────────┴───────────┴─────────────┘
```

### 16.2 `machine:compatibility`

Analyzes compatibility between two versions.

```
$ php artisan machine:compatibility OrderMachine --from=1 --to=2

 Compatibility: Order Machine v1 → v2
 ┌──────────────────────────────┬───────┐
 │ Total instances (non-final)  │ 0     │
 │ Compatible                   │ 0     │
 │ Incompatible                 │ 0     │
 ├──────────────────────────────┼───────┤
 │ Removed states               │ 1     │
 │   processing                 │       │
 │ Added states                 │ 2     │
 │   validating, rejected       │       │
 └──────────────────────────────┴───────┘
```

### 16.3 `machine:migrate`

Executes pending machine migrations — works exactly like `php artisan migrate`:

```
$ php artisan machine:migrate

 Running machine migrations...

 2026_04_01_000000_order_v1_to_v2
 Migrating Order Machine: v1 → v2
 ⠸ Processing batch 1/3...
 ⠸ Processing batch 2/3...
 ⠸ Processing batch 3/3...
 ✓ Migrated: 1,247 | Skipped: 203 | Failed: 2 | Duration: 4.2s

 2026_04_15_000000_order_v2_to_v3_reactivation
 Migrating Order Machine: v2 → v3
 ⠸ Processing batch 1/1...
 ✓ Migrated: 89 | Skipped: 8,442 | Failed: 0 | Duration: 0.8s
```

Options:
- `--dry-run` — simulate without persisting
- `--machine=OrderMachine` — run only migrations for a specific machine
- `--path=...` — run a specific migration file
- `--retry-failed` — re-run the same migration idempotently (step 3 version check skips already-migrated instances; previously locked instances are retried). No per-instance failure tracking needed — the migration simply re-scans all eligible instances.
- `--async` — dispatch as queue jobs instead of running synchronously

### 16.4 `machine:migrate-status`

Shows which migrations have been executed and which are pending (like `php artisan migrate:status`).

### 16.5 `make:machine-migration`

Scaffold a new migration file (like `php artisan make:migration`):

```
$ php artisan make:machine-migration OrderMachine --from=1 --to=2

  Created: app/Machines/Migrations/2026_04_03_143200_order_v1_to_v2.php
```

### 16.6 `machine:validate` Enhancement

The existing `machine:validate` command gains version-aware checks:

- If `versions()` exists: validate all registered definitions
- Check that exactly one version is marked active
- Check that version strings in definitions match their registry keys
- Check that `definition_version` in `machine_events` references a registered version (warn on orphans — uses `SELECT DISTINCT definition_version`, O(versions) not O(events))

---

## 17. Testing Infrastructure

### 17.1 `TestMachine` Enhancements

```php
// Test with a specific version
OrderMachine::test(version: '1')
    ->send(SubmitEvent::class)
    ->assertState('processing');

// Test migration
OrderMachine::test(version: '1')
    ->send(SubmitEvent::class)
    ->assertState('processing')
    ->migrate(OrderV1ToV2Migration::class)
    ->assertState('validating')
    ->assertContext('validationStatus', 'pending_revalidation');
```

**`TestMachine::migrate()` semantics:** This is a lightweight test helper, NOT a full `MigrationExecutor` invocation. It:
1. Applies `plan()` state mappings directly to the TestMachine's internal state
2. Calls `StateMapping::transformContext()` closure if attached
3. Swaps the pinned definition to the target version
4. Does NOT acquire locks, persist events, or write to DB
5. Does NOT evaluate `shouldMigrate()` — always applies (test is explicitly requesting migration)
6. Allows fluent chaining for verifying migration correctness in unit tests

### 15.2 `assertVersion()`

```php
OrderMachine::test()
    ->send(SubmitEvent::class)
    ->assertVersion('2');  // confirms instance is on expected definition version
```

### 17.3 Machine Migration Testing

```php
// Unit test a migration plan in isolation
it('maps processing to validating with context transform', function () {
    $migration = new OrderV1ToV2Migration();
    $plan      = $migration->plan();

    expect($plan['processing'])->toBeInstanceOf(StateMapping::class);
    expect($plan['processing']->target())->toBe('validating');
    expect($plan['pending']->isAuto())->toBeTrue();
    expect($plan['done']->isSkipped())->toBeTrue();

    // Test context transformer
    $ctx = $plan['processing']->applyTransform(['orderId' => 123]);
    expect($ctx)->toHaveKey('validationStatus', 'pending_revalidation');
});
```

### 15.4 `Machine::fake()` and Versioning

`Machine::fake()` operates on the **active version** by default. For version-specific faking:

```php
// Fake the active version (default)
OrderMachine::fake();

// Fake a specific version
OrderMachine::fake(version: '1');
```

Faked machines respect the same version resolution as real machines. `fakingAllActions()`, `fakingAllGuards()`, and `simulateChildDone/Fail/Timeout` work per-version — the faked definition determines which behaviors are available.

### 17.5 `InteractsWithMachines` Reset

`VersionRegistry` uses a **static cache** keyed by machine class — definitions are resolved once per request/process and reused. The `InteractsWithMachines` trait's `tearDown` calls `VersionRegistry::flush()` to clear this static cache between tests, preventing cross-test contamination.

---

## 18. HTTP Endpoint Behavior

### 18.1 Endpoint Version Awareness

Endpoints operate on the instance's pinned version. When a request hits an endpoint for a restored machine, the correct definition version is used for:
- Available events computation
- Guard evaluation
- Action execution
- Output resolution

### 18.2 Response Envelope

The response envelope gains a `definitionVersion` field:

```json
{
    "id": "01JQKX...",
    "machineId": "order",
    "definitionVersion": "2",
    "state": ["validating"],
    "availableEvents": ["VALID", "INVALID"],
    "output": null,
    "isProcessing": false
}
```

### 18.3 Create Endpoint

The create endpoint always uses the active version. Optionally, a `definitionVersion` field in the request body can pin to a specific version (useful for testing or gradual rollout):

```json
POST /api/orders
{
    "event": "START",
    "definitionVersion": "1"
}
```

If omitted, the active version is used. Behavior when specified:
- If `allow_version_override` is `false` (default): the field is **silently ignored** — active version is used. No error.
- If `allow_version_override` is `true` and version exists: the specified version is used.
- If `allow_version_override` is `true` and version NOT found in registry: `422 Unprocessable Entity` with validation error.

---

## 19. Interaction with Existing Systems

### 19.1 Child Machine Delegation

When a parent delegates to a child machine:
- **New child instances** use the child machine's **active version** (independent from parent version)
- **`ChildMachineCompletionJob`** restores the **parent** using the parent's own pinned `definition_version` (from parent's `machine_events`), NOT the child's version. The child's version is only relevant for restoring the child.
- Parent and child version lifecycles are **fully independent**
- **Forwarded CREATE endpoints** always use the child machine's active version. `allow_version_override` does not apply to forwarded endpoints.
- **Cross-version output compatibility:** If a child is migrated to a new version with different output shape, the parent's `@done` action must handle both shapes. This is the developer's responsibility — the framework does not validate output shape compatibility across versions.
- **Parent migration while child is in-flight:** Allowed. The child's `ChildMachineCompletionJob` will restore the parent from the parent's latest `machine_events`, which now has the new `definition_version`. The new version's `@done` handler will be used. If the new version doesn't have a `@done` handler for the child, `NoTransitionDefinitionFoundException` is thrown — same as any other missing transition.

### 19.2 Parallel States

Parallel region jobs carry `definition_version`. All regions of an instance use the same definition version (they're part of the same machine).

### 19.3 Timers & Schedules

`machine:process-timers` and `machine:process-scheduled` read from `machine_current_states`. The `definition_version` column ensures the correct definition is loaded when processing timer/schedule events.

### 19.4 Archival

`ArchiveService::archiveMachine()` preserves `definition_version` in compressed events. `restoreMachine()` restores it. `CompressionManager` serializes all `MachineEvent` columns (including the new `definition_version`) — no format changes needed. The new column is included automatically in JSON serialization/deserialization.

**Archived instances and migration:** Archived instances are NOT processed by the background sweep or `machine:migrate` command. When an archived instance is auto-restored (new event arrives, or `ChildMachineCompletionJob` triggers restore), the JIT migration in `Machine::send()` detects the pending migration and applies it inline. This is the most efficient approach — no need to decompress/recompress archives just to bump a version.

### 19.5 Scenarios

Scenarios target the active version by default. A scenario's `$machine` property already identifies the machine class. If a scenario needs to work with a specific version, it can override:

```php
class AtCheckingProtocol extends MachineScenario
{
    protected string $machine = CarSalesMachine::class;
    protected string $definitionVersion = '2'; // optional — defaults to active
}
```

**ScenarioPlayer integration:** When `$definitionVersion` is set, `ScenarioPlayer` passes it to `Machine::create(definitionVersion: $version)` when starting the scenario. The specified version's definition is used for all transitions, overrides, and `@continue` chains. If the version is not found in the registry, `DefinitionVersionNotFoundException` is thrown at scenario activation time. If `$definitionVersion` is not set, the active version is used (default behavior).

### 19.6 Path Coverage Analysis

`PathEnumerator` operates on a `MachineDefinition` instance. Since each version has its own definition, path enumeration is version-scoped. `machine:paths` gains a `--version` flag.

### 19.7 XState Export

`machine:xstate` gains a `--version` flag. Without it, exports the active version. With it, exports the specified version.

### 19.8 Machine Query

`Machine::query()` gains a `->version(string $version)` filter:

```php
OrderMachine::query()
    ->version('1')
    ->inState('processing')
    ->get();
```

---

## 20. Failure Modes and Recovery

| Failure | Behavior | Recovery |
|---------|----------|----------|
| **Restore with unknown version** | `DefinitionVersionNotFoundException` thrown with message: "Version X not found in registry. Re-add the version factory or migrate instances." | Register the missing version in `versions()`, or migrate instances to a known version |
| **Migration: lock unavailable** | Instance recorded as failed in `MigrationResult::failures` with reason `lock_unavailable` | Re-run migration with `--retry-failed` |
| **Migration: plan() doesn't cover all source states** | `InvalidMigrationPlanException` thrown at execution start (before any instance is processed) | Add missing states to `plan()` — use `make:machine-migration` to scaffold with all states |
| **Migration: incompatible state (auto() but removed)** | Instance recorded as failed with `IncompatibleMigrationException` | Change `auto()` to `to('target')` or `skip()` in `plan()` |
| **Migration: target state doesn't exist** | `InvalidMigrationTargetException` thrown | Fix the state mapping |
| **Migration: context transformer fails** | Instance recorded as failed; exception message in `MigrationFailure::reason`, full exception logged via `Log::error()` | Fix transformer, re-run |
| **Migration: batch partially fails** | Successful instances remain migrated (committed), failed instances untouched | Re-run — idempotent (already-migrated instances are skipped via version check in step 3) |
| **Deploy without old version in registry** | Old instances fail to restore | Add old version back to registry, or migrate remaining instances first |
| **Concurrent migration + normal event processing** | Lock prevents conflict — migration records locked instances as failed | Re-run migration with `--retry-failed` |
| **Overlapping version ranges in pending migrations** | Second migration skips instances already migrated by first (version check in step 3 rejects them) | Chain migrations: v1→v2, then v2→v3 — not v1→v2 and v1→v3 simultaneously |
| **JIT migration: event incompatible with target version** | "Process first, migrate after" — event processed on old version, migration deferred | Sweep job will migrate later, or next compatible event triggers JIT |
| **Event upcast: breaking change in chain** | `EventUpcastException` with details (upcasted-to, blocked-at, missing fields) | Sender must upgrade to current event version |
| **Event upcast: missing upcastToV{N} method** | `MissingUpcastDefinitionException` at definition validation time | Add the missing `upcastToV{N}` method to the EventBehavior |
| **Sweep: backpressure threshold exceeded** | Sweep pauses, resumes on next tick | Automatic — no intervention needed |

### 20.1 Rollback

Migration is NOT automatically reversible. To "undo" a migration:
1. Write a reverse `MachineMigration` (v2 → v1, with inverse state mappings)
2. Execute it
3. This is intentionally manual — rollback decisions are business decisions

The `DEFINITION_MIGRATED` events in `machine_events` and `machine_migration_batches` records enable tracing what happened.

---

## 21. Configuration

```php
// config/machine.php
return [
    // ...existing sections (archival, parallel_dispatch, timers, max_transition_depth)

    'versioning' => [
        // Path where machine migration files are stored
        'migrations_path' => app_path('Machines/Migrations'),

        // Whether to include definitionVersion in HTTP response envelopes
        'expose_in_response' => true,

        // Whether to allow explicit version selection in create endpoints
        'allow_version_override' => false, // true in staging/testing

        // Default batch size for migration commands (overridden by MachineMigration::batchSize())
        'migration_batch_size' => 500,

        // Lock TTL for migration operations (seconds)
        'migration_lock_ttl' => 30,

        // Just-in-time migration in Machine::send()
        'jit_migration' => true,

        // Background sweep job settings
        'sweep' => [
            'enabled'                => false, // enable in production when migrations are pending
            'frequency'              => 60,    // seconds between sweep ticks
            'batch_size'             => 100,   // instances per tick
            'backpressure_threshold' => 1000,  // max queue depth before pausing
        ],
    ],
];
```

---

## 22. File Structure

```
src/Versioning/
    VersionRegistry.php                — Multi-version definition registry
    StateMapping.php                   — VO: per-state migration action (auto, to, skip + transform + when)
    MachineMigration.php               — Abstract base for machine migrations (Laravel migration pattern)
    MigrationExecutor.php              — Discovers + executes machine migrations (batch, lock-aware, JIT support)
    MigrationResult.php                — VO: migration execution result
    MigrationFailure.php               — VO: per-instance failure detail
    CompatibilityAnalyzer.php          — Analyzes instance compatibility between versions
    CompatibilityReport.php            — VO: compatibility analysis result
    BreakingChange.php                 — VO: non-upcastable event version transition
src/Versioning/Exceptions/
    DefinitionVersionNotFoundException.php
    InvalidVersionRegistryException.php
    IncompatibleMigrationException.php
    InvalidMigrationPlanException.php
    InvalidMigrationTargetException.php
    EventUpcastException.php           — Thrown when event upcast chain hits a BreakingChange
    MissingUpcastDefinitionException.php — Thrown when upcastToV{N} method is missing
src/Jobs/
    MigrationJob.php                   — Async migration via queue
    MigrationSweepJob.php             — Background sweep: gradual migration with backpressure
src/Commands/
    MachineVersionStatusCommand.php    — machine:version-status
    MachineCompatibilityCommand.php    — machine:compatibility
    MachineMigrateCommand.php          — machine:migrate (pending migrations, like artisan migrate)
    MachineMigrateStatusCommand.php    — machine:migrate-status
    MakeMachineMigrationCommand.php    — make:machine-migration (definition-diff-aware scaffold)
database/migrations/
    add_definition_version_to_machine_events_table.php.stub
    add_definition_version_to_machine_current_states_table.php.stub
    create_machine_migration_batches_table.php.stub
tests/Versioning/
    VersionRegistryTest.php
    CompatibilityAnalyzerTest.php
    MigrationExecutorTest.php
    MachineMigrationTest.php
    VersionAwareRestoreTest.php
    JitMigrationTest.php
    MigrationSweepTest.php
    EventUpcastTest.php
tests/Stubs/Versioning/
    VersionedOrderMachine.php          — Test stub: machine with version registry (v1, v2, v3)
    OrderV1ToV2Migration.php           — Test stub: state split migration
    OrderV2ToV3Migration.php           — Test stub: reactivation migration
app/Machines/Migrations/               — User-land migration files (configurable path)
    2026_04_01_000000_order_v1_to_v2.php
    2026_04_15_000000_order_v2_to_v3_reactivation.php
```

---

## 23. Scenario Matrix

Every combination of instance state × definition change × desired behavior:

| # | Instance State | Definition Change | Desired Behavior | Solution |
|---|---------------|-------------------|------------------|----------|
| 1 | Not yet created | Any | Use new definition | Automatic — active version |
| 2 | In non-final state `S` | `S` exists unchanged in new def | Continue on old version | Version-per-definition (default) |
| 3 | In non-final state `S` | `S` exists unchanged in new def | Continue on NEW version | Machine migration with `StateMapping::auto()` |
| 4 | In non-final state `S` | `S` removed/renamed to `S'` | Continue on old version | Version-per-definition (default) |
| 5 | In non-final state `S` | `S` removed/renamed to `S'` | Move to new version | Machine migration with `StateMapping::to('S'')` |
| 6 | In non-final state `S` | `S` split into `S1, S2` | Continue on old version | Version-per-definition (default) |
| 7 | In non-final state `S` | `S` split into `S1, S2` | Move to `S1` in new version | Machine migration with `StateMapping::to('S1')` |
| 8 | In final state `F` | `F` still final in new def | Leave as-is | No action (default) |
| 9 | In final state `F` | `F` still final in new def | Move to new version | Machine migration with `auto()`, `includeFinal: true` |
| 10 | In final state `F` | `F` no longer final in new def | Reactivate on new version | Machine migration with `to('S')` or `auto()`, `includeFinal: true` |
| 11 | In final state `F` | `F` removed in new def | Move to new state in new version | Machine migration with `to('S')`, `includeFinal: true` |
| 12 | Any non-final | Context shape changed | Adapt context | Machine migration with `StateMapping::auto()->transformContext(...)` or `to(...)->transformContext(...)` |
| 13 | Any | No change (same version) | Normal operation | No action — zero overhead |
| 14 | Any non-final | States unchanged, event payload changed | Accept old-format events | Pure version bump migration + `upcastToV{N}` on EventBehavior |
| 15 | Any non-final | States unchanged, event payload changed (breaking) | Reject old-format events with clear error | Pure version bump migration + `BreakingChange` in `upcastToV{N}` |
| 16 | Active (receives events) | Any pending migration | Migrate on next event | JIT migration in `Machine::send()` (Section 12.3) |
| 17 | Dormant (no events) | Any pending migration | Migrate gradually | Background sweep job (Section 12.2) |
| 18 | Archived | Any pending migration | Migrate on restore | Auto-restore + JIT migration chain (Section 12.4) |

---

## 24. Quality Gate

```
composer quality
```

All existing tests must continue to pass. New tests must achieve:
- 100% type coverage for new classes
- PHPStan level 6 clean
- Mutation testing for core logic (`MigrationExecutor`, `CompatibilityAnalyzer`, `VersionRegistry`)

---

<!-- structured_elements:
  tables:
    - name: "Design Principles"
      location: "Section 2"
      rows: 8
    - name: "Industry Context"
      location: "Section 3"
      rows: 8
    - name: "Terminology"
      location: "Section 4"
      rows: 13
    - name: "Gradual Migration Layers"
      location: "Section 12.1"
      rows: 2
    - name: "Event Upcast Return Types"
      location: "Section 14.3"
      rows: 2
    - name: "Version States"
      location: "Section 15.1"
      rows: 4
    - name: "Failure Modes"
      location: "Section 20"
      rows: 14
    - name: "Configuration"
      location: "Section 21"
      rows: 3
    - name: "Scenario Matrix"
      location: "Section 23"
      rows: 18
    - name: "Laravel Migration Comparison"
      location: "Section 10.1"
      rows: 12
  code_blocks:
    - name: "VersionRegistry class"
      location: "Section 8.3"
    - name: "StateMapping VO"
      location: "Section 10.3"
    - name: "MachineMigration abstract class"
      location: "Section 10.4"
    - name: "MigrationExecutor service"
      location: "Section 11.1"
    - name: "CompatibilityReport VO"
      location: "Section 9.2"
    - name: "MigrationResult VO"
      location: "Section 11.3"
    - name: "Migration event structure"
      location: "Section 11.2 step 7"
    - name: "make:machine-migration scaffold output"
      location: "Section 10.4"
    - name: "MigrationSweepJob"
      location: "Section 12.2"
    - name: "JIT migration in Machine::send()"
      location: "Section 12.3"
    - name: "EventBehavior upcast chain"
      location: "Section 14.3"
    - name: "BreakingChange VO"
      location: "Section 14.4"
    - name: "TestMachine enhancements"
      location: "Section 17.1"
    - name: "Response envelope"
      location: "Section 18.2"
  numbered_lists: []
-->
