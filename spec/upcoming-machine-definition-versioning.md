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
12. [Completed Instance Reactivation](#12-completed-instance-reactivation)
13. [Version Lifecycle Management](#13-version-lifecycle-management)
14. [Artisan Commands](#14-artisan-commands)
15. [Testing Infrastructure](#15-testing-infrastructure)
16. [HTTP Endpoint Behavior](#16-http-endpoint-behavior)
17. [Interaction with Existing Systems](#17-interaction-with-existing-systems)
18. [Failure Modes and Recovery](#18-failure-modes-and-recovery)
19. [Configuration](#19-configuration)
20. [File Structure](#20-file-structure)
21. [Scenario Matrix](#21-scenario-matrix)
22. [Quality Gate](#22-quality-gate)

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
| **Machine migration** | A developer-defined class (like a Laravel DB migration) mapping source version to target version: state mappings, context transformers, and filters. Lives in `app/Machines/Migrations/`. |
| **Migration event** | A `DEFINITION_MIGRATED` event appended to an instance's history when it is migrated, recording the version transition. |
| **Drained version** | A definition version with zero active (non-final) instances. Safe to remove from the registry. |

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

### 10.3 `MachineMigration` Abstract Class

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
     * State mapping: source state → target state.
     *
     * Only needed for states that are renamed or restructured.
     * States with the same name in both versions are auto-mapped.
     *
     * @return array<string, string> source_state => target_state
     */
    public function stateMapping(): array
    {
        return [];
    }

    /**
     * Context transformer: modify context data during migration.
     *
     * Called after state mapping. Receives the current context and
     * the target state. Returns modified context.
     *
     * @param  array<string, mixed>  $context  Current context data.
     * @param  string  $fromState  The original state before mapping.
     * @param  string  $toState  The target state after mapping.
     *
     * @return array<string, mixed> Transformed context.
     */
    public function transformContext(array $context, string $fromState, string $toState): array
    {
        return $context;
    }

    /**
     * Filter: which instances should be migrated?
     *
     * Return false to skip an instance. Useful for excluding instances
     * in states that should drain naturally on the old version.
     *
     * @param  string  $currentState  The instance's current state.
     * @param  array<string, mixed>  $context  The instance's current context.
     *
     * @return bool Whether to migrate this instance.
     */
    public function shouldMigrate(string $currentState, array $context): bool
    {
        return true;
    }

    /**
     * Post-migration hook: runs after each instance is migrated.
     *
     * Use for logging, notifications, or triggering follow-up events.
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

    /**
     * Whether this migration should include final-state instances.
     * Override to true for reactivation migrations.
     *
     * Execution order: includeFinal() controls the DB query filter
     * (only non-final instances are fetched unless true). Then
     * shouldMigrate() filters the query results per-instance.
     */
    public function includeFinal(): bool
    {
        return false;
    }
}
```

**Instance discovery:** The executor queries `machine_current_states` for instances matching `machine_class` and `definition_version = from()`. When `includeFinal()` is false (default), only non-final state rows are included. When `includeFinal()` is true, all rows are included (final-state instances always retain their `machine_current_states` rows — `syncCurrentStates()` never cleans them up). Archived instances are NOT included — use `machine:archive-status --restore` first if needed.

### 10.4 `make:machine-migration` Scaffold Command

```
$ php artisan make:machine-migration OrderMachine --from=1 --to=2

  Created: app/Machines/Migrations/2026_04_03_143200_order_v1_to_v2.php
```

Generates a timestamped file with the abstract methods pre-filled:

```php
<?php

declare(strict_types=1);

use App\Machines\OrderMachine;
use Tarfinlabs\EventMachine\Versioning\MachineMigration;

return new class extends MachineMigration
{
    public function machine(): string
    {
        return OrderMachine::class;
    }

    public function from(): string
    {
        return '1';
    }

    public function to(): string
    {
        return '2';
    }

    public function stateMapping(): array
    {
        return [
            // 'old_state' => 'new_state',
        ];
    }

    public function transformContext(array $context, string $fromState, string $toState): array
    {
        return $context;
    }
};
```

Note: anonymous class in a file that returns it — exactly like Laravel DB migrations.

### 10.5 Example: `A → B → C` to `A → B1 → B2 → C`

```php
// app/Machines/Migrations/2026_04_01_000000_order_v1_to_v2.php

return new class extends MachineMigration
{
    public function machine(): string { return OrderMachine::class; }
    public function from(): string { return '1'; }
    public function to(): string { return '2'; }

    public function stateMapping(): array
    {
        return [
            'processing' => 'validating',  // B → B1
            // 'pending' and 'done' exist in both — auto-mapped
        ];
    }

    public function transformContext(array $context, string $fromState, string $toState): array
    {
        if ($toState === 'validating') {
            // New version expects this key; old instances don't have it
            $context['validationStatus'] = 'pending_revalidation';
        }

        return $context;
    }

    public function shouldMigrate(string $currentState, array $context): bool
    {
        // Don't migrate completed instances — let them rest
        return $currentState !== 'done';
    }
};
```

### 10.6 Example: Final State Reactivation

```php
// app/Machines/Migrations/2026_04_15_000000_order_v2_to_v3_reactivation.php

return new class extends MachineMigration
{
    public function machine(): string { return OrderMachine::class; }
    public function from(): string { return '2'; }
    public function to(): string { return '3'; }

    public function stateMapping(): array
    {
        return [
            // 'done' was FINAL in v2, now it's ATOMIC with transition to 'post_processing'
            'done' => 'awaiting_review',
        ];
    }

    public function includeFinal(): bool
    {
        return true; // reactivation — include completed instances
    }

    public function shouldMigrate(string $currentState, array $context): bool
    {
        // Only reactivate instances completed after a certain date
        return $currentState === 'done'
            && ($context['completedAt'] ?? '') > '2026-01-01';
    }

    public function transformContext(array $context, string $fromState, string $toState): array
    {
        if ($fromState === 'done') {
            $context['reactivatedAt']    = now()->toIso8601String();
            $context['reactivateReason'] = 'compliance_review_required';
        }

        return $context;
    }
};
```

### 10.7 Migration Tracking

Batch-level tracking uses the `machine_migration_batches` table (defined in Section 6.3). Per-instance audit uses `DEFINITION_MIGRATED` events in `machine_events` (no separate per-instance table needed — avoids data duplication).

`MigrationExecutor::execute()` writes a `machine_migration_batches` row after processing all instances for a migration, recording the aggregate counts.

### 10.8 Pending Migrations

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

### 10.9 Selective Migration

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
4. **Resolve target state** — apply `stateMapping()`. For parallel states, apply mapping per-region independently (see Section 11.8). If current state has no explicit mapping, auto-map: verify the state passes ALL Section 9.1 compatibility criteria (exists, same type, matching event types) in the target definition. If auto-map validation fails, record as failed with `IncompatibleMigrationException`.
5. **Transform context** — call `migration->transformContext()`. If transformer throws, record as failed (exception message in `MigrationFailure::reason`, full exception logged via `Log::error()`).
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
- `stateMapping()` applies **per-region** — each region state is mapped independently
- Compatibility check must pass for **ALL** region states
- If any region state is incompatible and has no mapping, the entire instance fails migration
- The `DEFINITION_MIGRATED` event records the full multi-state `machine_value` array

**Example:**
```php
public function stateMapping(): array
{
    return [
        'region1.processing' => 'region1.validating',
        // region2.waiting stays as-is (auto-mapped)
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

## 12. Completed Instance Reactivation

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

    public function stateMapping(): array
    {
        return [
            'done' => 'awaiting_review',  // FINAL → ATOMIC
        ];
    }

    public function shouldMigrate(string $currentState, array $context): bool
    {
        // Only reactivate completed instances that meet business criteria
        return $currentState === 'done'
            && ($context['completedAt'] ?? '') > '2026-01-01';
    }
}
```

### 12.3 Reactivation Validation

The `MigrationExecutor` validates reactivation safety:
- Target state must exist in target definition
- If source state was FINAL and target state is non-FINAL: the `reactivated` flag in the migration event payload (Section 11.2 step 7a) is set to `true`
- `machine_current_states` row is updated via DELETE+INSERT (rows always exist for final states — `syncCurrentStates()` retains them)

---

## 13. Version Lifecycle Management

### 13.1 Version States

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

### 13.2 Detecting Drained Versions

```php
// Via VersionRegistry
$registry->isDrained('1'); // queries machine_current_states for non-final instances on version '1'
```

### 13.3 Deprecation

When a new active version is set, the previous active version becomes implicitly deprecated. No explicit API needed — the `active: true` flag on the new version is sufficient.

---

## 14. Artisan Commands

### 14.1 `machine:version-status`

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

### 14.2 `machine:compatibility`

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

### 14.3 `machine:migrate`

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

### 14.4 `machine:migrate-status`

Shows which migrations have been executed and which are pending (like `php artisan migrate:status`).

### 14.5 `make:machine-migration`

Scaffold a new migration file (like `php artisan make:migration`):

```
$ php artisan make:machine-migration OrderMachine --from=1 --to=2

  Created: app/Machines/Migrations/2026_04_03_143200_order_v1_to_v2.php
```

### 14.6 `machine:validate` Enhancement

The existing `machine:validate` command gains version-aware checks:

- If `versions()` exists: validate all registered definitions
- Check that exactly one version is marked active
- Check that version strings in definitions match their registry keys
- Check that `definition_version` in `machine_events` references a registered version (warn on orphans — uses `SELECT DISTINCT definition_version`, O(versions) not O(events))

---

## 15. Testing Infrastructure

### 15.1 `TestMachine` Enhancements

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
1. Applies `stateMapping()` directly to the TestMachine's internal state
2. Calls `transformContext()` on the current context
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

### 15.3 Machine Migration Testing

```php
// Unit test a migration in isolation
it('maps processing to validating', function () {
    $migration = new OrderV1ToV2Migration();

    expect($migration->stateMapping())
        ->toHaveKey('processing', 'validating');

    $context = $migration->transformContext(
        context: ['orderId' => 123],
        fromState: 'processing',
        toState: 'validating',
    );

    expect($context)->toHaveKey('validationStatus', 'pending_revalidation');
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

### 15.5 `InteractsWithMachines` Reset

`VersionRegistry` uses a **static cache** keyed by machine class — definitions are resolved once per request/process and reused. The `InteractsWithMachines` trait's `tearDown` calls `VersionRegistry::flush()` to clear this static cache between tests, preventing cross-test contamination.

---

## 16. HTTP Endpoint Behavior

### 16.1 Endpoint Version Awareness

Endpoints operate on the instance's pinned version. When a request hits an endpoint for a restored machine, the correct definition version is used for:
- Available events computation
- Guard evaluation
- Action execution
- Output resolution

### 16.2 Response Envelope

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

### 16.3 Create Endpoint

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

## 17. Interaction with Existing Systems

### 17.1 Child Machine Delegation

When a parent delegates to a child machine:
- **New child instances** use the child machine's **active version** (independent from parent version)
- **`ChildMachineCompletionJob`** restores the **parent** using the parent's own pinned `definition_version` (from parent's `machine_events`), NOT the child's version. The child's version is only relevant for restoring the child.
- Parent and child version lifecycles are **fully independent**
- **Forwarded CREATE endpoints** always use the child machine's active version. `allow_version_override` does not apply to forwarded endpoints.
- **Cross-version output compatibility:** If a child is migrated to a new version with different output shape, the parent's `@done` action must handle both shapes. This is the developer's responsibility — the framework does not validate output shape compatibility across versions.
- **Parent migration while child is in-flight:** Allowed. The child's `ChildMachineCompletionJob` will restore the parent from the parent's latest `machine_events`, which now has the new `definition_version`. The new version's `@done` handler will be used. If the new version doesn't have a `@done` handler for the child, `NoTransitionDefinitionFoundException` is thrown — same as any other missing transition.

### 17.2 Parallel States

Parallel region jobs carry `definition_version`. All regions of an instance use the same definition version (they're part of the same machine).

### 17.3 Timers & Schedules

`machine:process-timers` and `machine:process-scheduled` read from `machine_current_states`. The `definition_version` column ensures the correct definition is loaded when processing timer/schedule events.

### 17.4 Archival

`ArchiveService::archiveMachine()` preserves `definition_version` in compressed events. `restoreMachine()` restores it. `CompressionManager` serializes all `MachineEvent` columns (including the new `definition_version`) — no format changes needed. The new column is included automatically in JSON serialization/deserialization.

### 17.5 Scenarios

Scenarios target the active version by default. A scenario's `$machine` property already identifies the machine class. If a scenario needs to work with a specific version, it can override:

```php
class AtCheckingProtocol extends MachineScenario
{
    protected string $machine = CarSalesMachine::class;
    protected string $definitionVersion = '2'; // optional — defaults to active
}
```

**ScenarioPlayer integration:** When `$definitionVersion` is set, `ScenarioPlayer` passes it to `Machine::create(definitionVersion: $version)` when starting the scenario. The specified version's definition is used for all transitions, overrides, and `@continue` chains. If the version is not found in the registry, `DefinitionVersionNotFoundException` is thrown at scenario activation time. If `$definitionVersion` is not set, the active version is used (default behavior).

### 17.6 Path Coverage Analysis

`PathEnumerator` operates on a `MachineDefinition` instance. Since each version has its own definition, path enumeration is version-scoped. `machine:paths` gains a `--version` flag.

### 17.7 XState Export

`machine:xstate` gains a `--version` flag. Without it, exports the active version. With it, exports the specified version.

### 17.8 Machine Query

`Machine::query()` gains a `->version(string $version)` filter:

```php
OrderMachine::query()
    ->version('1')
    ->inState('processing')
    ->get();
```

---

## 18. Failure Modes and Recovery

| Failure | Behavior | Recovery |
|---------|----------|----------|
| **Restore with unknown version** | `DefinitionVersionNotFoundException` thrown with message: "Version X not found in registry. Re-add the version factory or migrate instances." | Register the missing version in `versions()`, or migrate instances to a known version |
| **Migration: lock unavailable** | Instance recorded as failed in `MigrationResult::failures` with reason `lock_unavailable` | Re-run migration with `--retry-failed` |
| **Migration: incompatible state (no mapping)** | Instance recorded as failed with `IncompatibleMigrationException` | Add mapping to `MachineMigration::stateMapping()` |
| **Migration: target state doesn't exist** | `InvalidMigrationTargetException` thrown | Fix the state mapping |
| **Migration: context transformer fails** | Instance recorded as failed; exception message in `MigrationFailure::reason`, full exception logged via `Log::error()` | Fix transformer, re-run |
| **Migration: batch partially fails** | Successful instances remain migrated (committed), failed instances untouched | Re-run — idempotent (already-migrated instances are skipped via version check in step 3) |
| **Deploy without old version in registry** | Old instances fail to restore | Add old version back to registry, or migrate remaining instances first |
| **Concurrent migration + normal event processing** | Lock prevents conflict — migration records locked instances as failed | Re-run migration with `--retry-failed` |
| **Overlapping version ranges in pending migrations** | Second migration skips instances already migrated by first (version check in step 3 rejects them) | Chain migrations: v1→v2, then v2→v3 — not v1→v2 and v1→v3 simultaneously |

### 18.1 Rollback

Migration is NOT automatically reversible. To "undo" a migration:
1. Write a reverse `MachineMigration` (v2 → v1, with inverse state mappings)
2. Execute it
3. This is intentionally manual — rollback decisions are business decisions

The `DEFINITION_MIGRATED` events in `machine_events` and `machine_migration_batches` records enable tracing what happened.

---

## 19. Configuration

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
    ],
];
```

---

## 20. File Structure

```
src/Versioning/
    VersionRegistry.php                — Multi-version definition registry
    MachineMigration.php               — Abstract base for machine migrations (Laravel migration pattern)
    MigrationExecutor.php              — Discovers + executes machine migrations (batch, lock-aware)
    MigrationResult.php                — VO: migration execution result
    MigrationFailure.php               — VO: per-instance failure detail
    CompatibilityAnalyzer.php          — Analyzes instance compatibility between versions
    CompatibilityReport.php            — VO: compatibility analysis result
src/Versioning/Exceptions/
    DefinitionVersionNotFoundException.php
    InvalidVersionRegistryException.php
    IncompatibleMigrationException.php
    InvalidMigrationTargetException.php
src/Jobs/
    MigrationJob.php                   — Async migration via queue
src/Commands/
    MachineVersionStatusCommand.php    — machine:version-status
    MachineCompatibilityCommand.php    — machine:compatibility
    MachineMigrateCommand.php          — machine:migrate (pending migrations, like artisan migrate)
    MachineMigrateStatusCommand.php    — machine:migrate-status
    MakeMachineMigrationCommand.php    — make:machine-migration (scaffold)
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
tests/Stubs/Versioning/
    VersionedOrderMachine.php          — Test stub: machine with version registry (v1, v2, v3)
    OrderV1ToV2Migration.php           — Test stub: state split migration
    OrderV2ToV3Migration.php           — Test stub: reactivation migration
app/Machines/Migrations/               — User-land migration files (configurable path)
    2026_04_01_000000_order_v1_to_v2.php
    2026_04_15_000000_order_v2_to_v3_reactivation.php
```

---

## 21. Scenario Matrix

Every combination of instance state × definition change × desired behavior:

| # | Instance State | Definition Change | Desired Behavior | Solution |
|---|---------------|-------------------|------------------|----------|
| 1 | Not yet created | Any | Use new definition | Automatic — active version |
| 2 | In non-final state `S` | `S` exists unchanged in new def | Continue on old version | Version-per-definition (default) |
| 3 | In non-final state `S` | `S` exists unchanged in new def | Continue on NEW version | Machine migration (auto-map, no state mapping needed) |
| 4 | In non-final state `S` | `S` removed/renamed to `S'` | Continue on old version | Version-per-definition (default) |
| 5 | In non-final state `S` | `S` removed/renamed to `S'` | Move to new version | Machine migration with `S → S'` mapping |
| 6 | In non-final state `S` | `S` split into `S1, S2` | Continue on old version | Version-per-definition (default) |
| 7 | In non-final state `S` | `S` split into `S1, S2` | Move to `S1` in new version | Machine migration with `S → S1` mapping |
| 8 | In final state `F` | `F` still final in new def | Leave as-is | No action (default) |
| 9 | In final state `F` | `F` still final in new def | Move to new version | Machine migration (auto-map, `shouldMigrate` includes final) |
| 10 | In final state `F` | `F` no longer final in new def | Reactivate on new version | Machine migration with `F → F` or `F → S'` mapping, `shouldMigrate` includes final |
| 11 | In final state `F` | `F` removed in new def | Move to new state in new version | Machine migration with `F → S'` mapping |
| 12 | Any non-final | Context shape changed | Adapt context | Machine migration with `transformContext()` |
| 13 | Any | No change (same version) | Normal operation | No action — zero overhead |

---

## 22. Quality Gate

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
      rows: 8
    - name: "Version States"
      location: "Section 13.1"
      rows: 4
    - name: "Failure Modes"
      location: "Section 18"
      rows: 9
    - name: "Configuration"
      location: "Section 19"
      rows: 3
    - name: "Scenario Matrix"
      location: "Section 21"
      rows: 13
    - name: "Laravel Migration Comparison"
      location: "Section 10.1"
      rows: 12
  code_blocks:
    - name: "VersionRegistry class"
      location: "Section 8.3"
    - name: "MachineMigration abstract class"
      location: "Section 10.3"
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
    - name: "TestMachine enhancements"
      location: "Section 15.1"
    - name: "Response envelope"
      location: "Section 16.2"
  numbered_lists: []
-->
