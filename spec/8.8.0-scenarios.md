# Machine Scenarios Plan

> Pre-scripted event replay sequences that bring a machine to a desired state in staging environments — enabling product teams, QA, and non-technical stakeholders to test new features without manually navigating complex state flows.

## Table of Contents

1. [Problem](#1-problem)
2. [Current Scenario System — Why It Must Go](#2-current-scenario-system--why-it-must-go)
3. [Design Principles](#3-design-principles)
4. [Core Architecture](#4-core-architecture)
5. [MachineScenario Abstract Class](#5-machinescenario-abstract-class)
6. [Arrange: Stubbing External Dependencies](#6-arrange-stubbing-external-dependencies)
7. [Steps & Models](#7-steps--models)
8. [Parametrization](#8-parametrization)
9. [Composition — Extending Scenarios](#9-composition--extending-scenarios)
10. [Mid-Flight Scenarios — Playing on Existing Machines](#10-mid-flight-scenarios--playing-on-existing-machines)
11. [Endpoint Integration — Scenario-Aware Responses](#11-endpoint-integration--scenario-aware-responses)
12. [Child Machine Scenarios](#12-child-machine-scenarios)
13. [Async Dispatch, Timers & Job Actors](#13-async-dispatch-timers--job-actors)
14. [ScenarioPlayer — Runtime Engine](#14-scenarioplayer--runtime-engine)
15. [Environment Gating](#15-environment-gating)
16. [Execution: Artisan Command & HTTP Endpoint](#16-execution-artisan-command--http-endpoint)
17. [Discovery & Registration](#17-discovery--registration)
18. [What NOT to Include](#18-what-not-to-include)
19. [Migration: Removing the Old Scenario System](#19-migration-removing-the-old-scenario-system)
20. [Implementation Checklist](#20-implementation-checklist)

---

## 1. Problem

Complex machines like `CarSalesMachine` have deep state hierarchies with parallel child delegations, API integrations, and multi-step guard chains. To test a new feature that affects behavior at state `checking_protocol`, a human must:

1. Trigger `START_APPLICATION` with valid retailer/farmer models
2. Grant consent → pass eligibility check
3. Wait for `FindeksMachine` to complete (phones → report → PIN → save — 6+ steps, external API calls)
4. Wait for `TurmobMachine` to complete (external API call)
5. Both parallel regions must finish → guard check → exit to `checking_protocol`

This requires 10+ manual steps, 2 child machines, multiple API calls, and deep domain knowledge. Product teams and QA cannot do this in staging without developer assistance.

**Scenarios solve this:** define the steps once, replay them on demand, arrive at the desired state with a fully functional machine (real event history, real context, real models).

---

## 2. Current Scenario System — Why It Must Go

The existing `scenarios` system (v7) does something entirely different — it **overrides state behavior at runtime**:

| Aspect | Current System | New System |
|--------|---------------|------------|
| **Purpose** | Change transitions/actions/guards at runtime | Bring machine to a desired state via event replay |
| **Mechanism** | State definition swap in `idMap` | Sequential `Machine::send()` calls |
| **Audience** | Developers (unit tests) | Product teams, QA, developers |
| **Environment** | Test suite | Staging / test environments |
| **Event history** | No events replayed | Full real event history |
| **Name** | "Scenarios" (misnomer — actually "variants") | Scenarios (actual meaning) |

**Why remove it:**

1. **Wrong abstraction** — Runtime A/B testing and feature flags should be handled by dedicated feature flag systems (LaunchDarkly, Pennant), not embedded in machine definitions.
2. **Naming collision** — Two systems called "scenarios" is confusing. The new system deserves the name.
3. **Minimal adoption** — 1 test stub, 1 feature test, no known production usage in consumer projects.
4. **New system subsumes it** — If you genuinely need to test alternative flows, define them as separate scenarios with different event sequences.

See [Section 17](#17-migration-removing-the-old-scenario-system) for the full removal checklist.

---

## 3. Design Principles

| Principle | Rationale |
|-----------|-----------|
| **Machine behavior is real** | Machine transitions, event history, context mutations, entry/exit actions — all real. The resulting machine is indistinguishable from one that arrived at that state organically. Infrastructure concerns (queue dispatch, timers) may be adjusted to run synchronously during replay. |
| **Stub, don't fake** | External dependencies (APIs, third-party services) return predetermined responses via `arrange()`. The behavior still runs — it just gets stubbed data instead of calling the real service. Guards return predetermined boolean values. |
| **Timers suspended** | `after`/`every` timers are disabled during scenario replay. The scenario controls progression via explicit steps, not wall-clock time. Timer fire records are not created during replay. |
| **Models are first-class** | Scenarios create real Eloquent models via Laravel factories. The machine operates on real database records. |
| **Composable** | Scenarios can extend other scenarios. A `CarSaleApproved` scenario extends `CarSaleAtProtocolCheck` and adds more steps. |
| **Child-aware** | Parent scenarios can trigger child machine scenarios. The child machine runs its own scenario (with its own `arrange` and `steps`), producing real child machine state. |
| **Parametrizable** | Scenarios have defaults but accept runtime overrides: `CarSaleAtProtocolCheck::play(['farmer_tckn' => '99887766655'])`. |
| **Environment-gated** | Scenarios are disabled by default. Only active when `MACHINE_SCENARIOS_ENABLED=true`. Zero overhead in production. |
| **Production-quality code** | Scenario classes live in the application codebase (not test directories). They're well-structured, reviewed, and maintained — they just don't run in production. |

---

## 4. Core Architecture

```
MachineScenario (abstract)
│
├── machine(): string               → which machine this scenario is for
├── description(): string            → human-readable description
├── parent(): ?class-string          → parent scenario for composition
├── from(): ?string                  → expected starting state (null = new machine)
│
├── defaults(): array                → default parameters (overridable at runtime)
├── arrange(): array                 → stub definitions for guards/actions/services
├── models(): array                  → Eloquent model factory definitions
├── steps(): array<ScenarioStep|ChildScenarioStep>
│
├── param(key): mixed                → access resolved parameters
├── model(name): Model               → access created models
│
├── play(params): ScenarioResult     → static entry point (new machine)
└── playOn(machineId, params): ScenarioResult → static entry point (existing machine)
    │
    └── ScenarioPlayer (engine)
        ├── validateEnvironment()      → check scenarios enabled
        ├── resolveParameters()        → merge defaults + runtime params
        ├── resolveParentChain()       → if parent(), play parent first
        ├── createModels()             → run model factories, store on scenario
        ├── registerStubs()            → bind arrange() into container
        ├── createOrContinueMachine()  → new machine or continue from parent
        ├── replaySteps()              → send events / play child scenarios
        ├── buildResult()              → ScenarioResult VO
        └── cleanupStubs()             → unbind stubs from container

ScenarioResult (value object)
├── $machineId: string          → machine ULID
├── $rootEventId: string        → root event for state restoration
├── $currentState: string       → state the machine landed on
├── $models: array              → created models (keyed by name)
├── $stepsExecuted: int         → number of steps played
├── $duration: float            → execution time in ms
└── $childResults: array        → child ScenarioResults (keyed by machine class)
```

---

## 5. MachineScenario Abstract Class

**Location:** `src/Scenarios/MachineScenario.php`

```php
<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

abstract class MachineScenario
{
    /** The machine class this scenario targets. */
    abstract protected function machine(): string;

    /** Human-readable description shown in artisan --list and HTTP UI. */
    abstract protected function description(): string;

    /** Ordered sequence of events to replay. */
    abstract protected function steps(): array;

    /**
     * Parent scenario to extend.
     * When set, the parent scenario plays first, then this scenario's steps continue.
     */
    protected function parent(): ?string
    {
        return null;
    }

    /**
     * Expected starting state for mid-flight scenarios.
     * When set, playOn() validates the machine is in this state before replaying.
     * When null (default), the scenario creates a new machine from initial state.
     */
    protected function from(): ?string
    {
        return null;
    }

    /** Default parameters — overridable at runtime via play(['key' => 'value']). */
    protected function defaults(): array
    {
        return [];
    }

    /**
     * Stub definitions for external dependencies.
     *
     * Keys are behavior/service class names.
     * Values depend on the stub type:
     *   Guard class    => bool (predetermined return value)
     *   Action class   => array (data injected instead of external call)
     *   Service class  => array<method => return_value>
     */
    protected function arrange(): array
    {
        return [];
    }

    /**
     * Eloquent model factories to run before event replay.
     * Keys are reference names (used in steps via $this->model('name')).
     * Values are Factory instances or closures that return a Model.
     * Created in declaration order — later models can reference earlier ones.
     */
    protected function models(): array
    {
        return [];
    }

    // ─── Step Builders (used inside steps()) ────────────────────────

    protected function send(string $eventType, array $payload = []): ScenarioStep
    {
        return ScenarioStep::send($eventType, $payload);
    }

    protected function child(string $machineClass): ChildScenarioStep
    {
        return new ChildScenarioStep($machineClass);
    }

    // ─── Hydration (called by ScenarioPlayer) ───────────────────────

    private array $resolvedParams = [];
    private array $createdModels  = [];

    /** @internal Called by ScenarioPlayer before models() and steps(). */
    public function hydrate(array $params, array $models = []): void
    {
        $this->resolvedParams = array_merge($this->defaults(), $params);
        $this->createdModels  = $models;
    }

    /** @internal Called by ScenarioPlayer after each model is created. */
    public function addModel(string $name, mixed $model): void
    {
        $this->createdModels[$name] = $model;
    }

    // ─── Parameter & Model Access ───────────────────────────────────

    protected function param(string $key, mixed $default = null): mixed
    {
        return $this->resolvedParams[$key] ?? $default;
    }

    protected function model(string $name): mixed
    {
        return $this->createdModels[$name]
            ?? throw new \RuntimeException("Model [{$name}] not found. Define it in models().");
    }

    // ─── Execution ──────────────────────────────────────────────────

    public static function play(array $params = []): ScenarioResult
    {
        $scenario = new static();

        return app(ScenarioPlayer::class)->play($scenario, $params);
    }

    /**
     * Play this scenario on an existing machine instance (mid-flight).
     * Validates from() state if defined.
     */
    public static function playOn(string $machineId, array $params = []): ScenarioResult
    {
        $scenario = new static();

        return app(ScenarioPlayer::class)->play($scenario, $params, machineId: $machineId);
    }

    // ─── Introspection (used by artisan --list, HTTP describe) ──────

    public function getMachine(): string { return $this->machine(); }
    public function getDescription(): string { return $this->description(); }
    public function getParent(): ?string { return $this->parent(); }
    public function getFrom(): ?string { return $this->from(); }
    public function getDefaults(): array { return $this->defaults(); }
}
```

**Design decisions:**

| Decision | Why |
|----------|-----|
| `parent()` not `extends()` | `extends` is a PHP reserved keyword — cannot be used as method name |
| Methods not properties for `machine()`, `description()`, `parent()` | Allows computed values. Consistent with Laravel's `Factory::definition()` pattern. |
| `hydrate()` + `addModel()` are public `@internal` | ScenarioPlayer must set params before `models()`/`steps()` are called. Marked `@internal` to discourage external use. |
| `param()` fallback does NOT re-read `defaults()` | Params are merged once in `hydrate()`. Avoids repeated `defaults()` calls and surprising behavior. |
| `play()` is static | Clean DX: `MyScenario::play()`. Instance is created internally. |
| `playOn()` separate from `play()` | Explicit intent — `play()` creates new machine, `playOn()` continues existing. No ambiguous optional params. |
| `from()` returns `?string` | `null` = scenario creates new machine. Non-null = mid-flight, validated when `machineId` provided. |
| Introspection getters | Artisan command and HTTP endpoint need to read metadata without playing the scenario. |

---

## 6. Arrange: Stubbing External Dependencies

`arrange()` returns a map of class names to predetermined responses. The `ScenarioPlayer` registers these as container bindings before event replay begins.

### Stub Mechanism: How It Works

All EventMachine behaviors are resolved through Laravel's service container via `MachineDefinition::getInvokableBehavior()` which calls `App::make($behaviorClass)`. This is the same mechanism that makes `Fakeable::fake()` work for tests.

Scenario stubs use a similar approach but with a critical difference: **stubs decorate the real behavior, fakes replace it entirely.**

```
Fakeable::fake()     → App::bind(Guard, fn() => mock that skips execution)     ❌ No real run
Scenario arrange()   → App::bind(Guard, fn() => wrapper that runs but returns stub value)  ✅ Real run, stubbed result
```

### Guard Stubs

```php
protected function arrange(): array
{
    return [
        HasConsentGuard::class          => true,
        IsFarmerNotEligibleGuard::class => false,
    ];
}
```

**Mechanism:** ScenarioPlayer binds a `ScenarioGuardStub` into the container:

```php
// Inside ScenarioPlayer::registerStubs()
foreach ($guardStubs as $guardClass => $returnValue) {
    App::bind($guardClass, function ($app, $params) use ($guardClass, $returnValue) {
        $stub = new ScenarioGuardStub(
            original: $guardClass,
            returnValue: $returnValue,
            eventQueue: $params['eventQueue'] ?? null,
        );
        return $stub;
    });
}
```

`ScenarioGuardStub` extends `GuardBehavior` and overrides `__invoke()` to return the stubbed boolean. The guard still appears in event history as if it ran normally.

### Action Stubs

```php
protected function arrange(): array
{
    return [
        FetchFindeksPhonesAction::class => ['phones' => ['+905551234567']],
        CheckProtocolAction::class      => ['protocol_eligible' => true],
    ];
}
```

**Mechanism:** ScenarioPlayer binds a `ScenarioActionStub` that extends `ActionBehavior`. When invoked, the stub:

1. Receives `$state` normally via `__invoke(State $state)`
2. Instead of calling external APIs, reads stub data from its constructor
3. Sets context values from stub data: `$state->context->set('phones', $stubData['phones'])`
4. The action "ran" — entry in event history, context mutations applied — but no external call was made

**Important:** Action stubs need to know *which context keys to set* from the stub data. Two approaches:

- **Convention:** stub array keys map directly to context keys. `['phones' => [...]]` → `$state->context->set('phones', [...])`
- **Explicit mapping in the action class:** Action implements a `ScenarioStubContract` interface with `applyStub(State $state, array $data): void`

The explicit mapping approach is recommended — the action author knows exactly how stub data maps to context:

```php
class FetchFindeksPhonesAction extends ActionBehavior implements ScenarioStubContract
{
    public function __invoke(State $state): void
    {
        // Real implementation: call Findeks API
        $phones = $this->findeksClient->queryPhones($state->context->get('tckn'));
        $state->context->set('phones', $phones);
    }

    public function applyStub(State $state, array $data): void
    {
        // Stub: skip API call, use provided data
        $state->context->set('phones', $data['phones']);
    }
}
```

When ScenarioPlayer detects an action in `arrange()`, it binds a wrapper that calls `applyStub()` instead of `__invoke()` if the action implements `ScenarioStubContract`. If the action does NOT implement the contract, the stub data is applied as direct context key-value pairs (convention-based fallback).

### Service Stubs

For actions that depend on injected services (via constructor DI):

```php
protected function arrange(): array
{
    return [
        FindeksApiClient::class => [
            'queryPhones'   => ['+905551234567'],
            'requestReport' => ['request_id' => 'RPT-001'],
            'checkStatus'   => ['status' => 'ready'],
            'getReport'     => ['score' => 1400, 'risk' => 'low'],
        ],
    ];
}
```

**Mechanism:** ScenarioPlayer creates a Mockery partial mock of the service class, stubs each listed method, and binds it into the container:

```php
foreach ($serviceStubs as $serviceClass => $methodMap) {
    $mock = Mockery::mock($serviceClass)->makePartial();

    foreach ($methodMap as $method => $returnValue) {
        $mock->shouldReceive($method)->andReturn($returnValue);
    }

    App::instance($serviceClass, $mock);
}
```

When the action resolves `FindeksApiClient` via constructor DI, it gets the mock. The action runs normally — its logic executes — but API calls return predetermined data.

**Mockery dependency note:** `Mockery` is typically a `require-dev` dependency. Staging environments that run `composer install --no-dev` won't have it. Two options: (a) ensure staging installs dev dependencies, or (b) implement service stubs as simple anonymous classes that extend the service and override methods — no Mockery needed. Option (b) is preferred for zero external dependency:

```php
foreach ($serviceStubs as $serviceClass => $methodMap) {
    $stub = new class($methodMap) extends $serviceClass {
        public function __construct(private array $returns) { /* skip parent */ }
        public function __call($method, $args) {
            return $this->returns[$method] ?? throw new \RuntimeException("No stub for {$method}");
        }
    };

    App::instance($serviceClass, $stub);
}
```

The exact approach depends on whether service classes are final or have constructor requirements — this will be resolved during implementation.

### How ScenarioPlayer Distinguishes Stub Types

ScenarioPlayer must determine whether an `arrange()` entry is a guard, action, or service:

```php
foreach ($arrange as $class => $value) {
    if (is_subclass_of($class, GuardBehavior::class)) {
        // Guard stub: $value is bool
        $this->registerGuardStub($class, $value);
    } elseif (is_subclass_of($class, ActionBehavior::class)) {
        // Action stub: $value is array
        $this->registerActionStub($class, $value);
    } else {
        // Service stub: $value is array<method => return>
        $this->registerServiceStub($class, $value);
    }
}
```

### Stubs vs. Fakes Comparison

| Aspect | Fake (`Machine::fake()`) | Stub (scenario `arrange()`) |
|--------|--------------------------|----------------------------|
| Behavior execution | Skipped entirely | Runs, but with predetermined data |
| Event history | Not created | Created (real transition) |
| Entry/exit actions | Skipped | Run normally |
| Context mutations | Skipped | Applied as normal |
| Machine state after | Artificial | Genuine |

---

## 7. Steps & Models

### ScenarioStep Value Object

**Location:** `src/Scenarios/ScenarioStep.php`

```php
<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

class ScenarioStep
{
    private function __construct(
        public readonly string $eventType,
        public readonly array $payload = [],
    ) {}

    public static function send(string $eventType, array $payload = []): self
    {
        return new self($eventType, $payload);
    }
}
```

### ChildScenarioStep

**Location:** `src/Scenarios/ChildScenarioStep.php`

```php
<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Scenarios;

class ChildScenarioStep
{
    private ?string $scenarioClass = null;
    private array $params = [];

    public function __construct(
        public readonly string $machineClass,
    ) {}

    public function scenario(string $scenarioClass): self
    {
        $this->scenarioClass = $scenarioClass;

        return $this;
    }

    public function with(array $params): self
    {
        $this->params = $params;

        return $this;
    }

    public function getScenarioClass(): ?string { return $this->scenarioClass; }
    public function getParams(): array { return $this->params; }
}
```

### Model Creation

`models()` defines Eloquent models that must exist before event replay starts. Models are created in declaration order by `ScenarioPlayer` — each model is added to the scenario via `addModel()` immediately after creation, so later models can reference earlier ones.

```php
protected function models(): array
{
    return [
        'farmer' => Farmer::factory()->state([
            'tckn'       => $this->param('farmer_tckn'),
            'birth_date' => $this->param('birth_date', '1990-01-01'),
        ]),
        'retailer' => Retailer::factory(),
        'retailerUser' => RetailerUser::factory()->state(function (array $attributes) {
            return ['retailer_id' => $this->model('retailer')->id];
        }),
    ];
}
```

**Note on cross-references:** When a model depends on a previously created model, use Laravel's closure-form `state()` (receives `$attributes`, returns array). This is the idiomatic Laravel factory pattern. The closure captures `$this` from the scenario, providing access to `$this->model()`.

### Steps with @always Transitions

Steps represent explicit `Machine::send()` calls. Automatic transitions (`@always`, `@done`) fire naturally as part of the engine — they do NOT require separate steps.

```php
protected function steps(): array
{
    return [
        $this->send('START_APPLICATION', [
            'retailer_id' => $this->model('retailer')->id,
            'farmer_id'   => $this->model('farmer')->id,
        ]),
        // @always: routing → eligibility_check → verification happens automatically
        // No steps needed for @always transitions — they fire as part of the send() above

        $this->send('CONSENT_GRANTED'),
        // @always: eligibility_check fires automatically after consent

        // Child scenarios for parallel child machines
        $this->child(FindeksMachine::class)
            ->scenario(FindeksReportCompleted::class)
            ->with(['tckn' => $this->param('farmer_tckn')]),

        $this->child(TurmobMachine::class)
            ->scenario(TurmobVerified::class),

        // After both children complete, parent's @done guard passes automatically
        // → machine transitions to checking_protocol without an explicit step
    ];
}
```

**Design decisions:**

| Decision | Why |
|----------|-----|
| Factory instances, not raw arrays | Leverage Laravel's full factory system — states, sequences, afterCreating hooks |
| Closure-form `state()` for cross-references | Idiomatic Laravel pattern. `$this` is captured from scenario scope. |
| Declaration order = creation order | Predictable, no dependency resolution complexity |
| No steps for @always/@done | These are engine-level automatic transitions. Requiring explicit steps would break the mental model. |

---

## 8. Parametrization

Scenarios have defaults but accept runtime overrides. Parameters are resolved once via `hydrate()` before `models()` and `steps()` are called.

`defaults()` defines parameter names and their default values. `$this->param()` reads resolved values — runtime overrides take precedence over defaults.

```php
protected function defaults(): array
{
    return [
        'farmer_tckn' => '12345678901',
        'birth_date'  => '1990-01-01',
        'amount'      => 150_000_00,
    ];
}

// Then use in models() and steps() — see Section 7 for full examples:
'farmer' => Farmer::factory()->state([
    'tckn' => $this->param('farmer_tckn'),   // resolved from defaults or runtime override
]),

$this->send('START_APPLICATION', [
    'amount' => $this->param('amount'),       // same
]),
```

**Invocation:**

```php
// With defaults
CarSaleAtProtocolCheck::play();

// With overrides
CarSaleAtProtocolCheck::play([
    'farmer_tckn' => '99887766655',
    'amount'      => 250_000_00,
]);

// Via artisan
// php artisan machine:scenario CarSaleAtProtocolCheck --param="farmer_tckn:99887766655" --param="amount:25000000"
```

---

## 9. Composition — Extending Scenarios

Scenarios can build on top of each other via `parent()`:

```php
class CarSaleAtDataCollection extends MachineScenario
{
    protected function machine(): string { return CarSalesMachine::class; }
    protected function description(): string { return 'At data collection — protocol passed'; }

    protected function parent(): string
    {
        return CarSaleAtProtocolCheck::class;
    }

    protected function arrange(): array
    {
        return [
            CheckProtocolAction::class => ['protocol_eligible' => true],
        ];
    }

    protected function steps(): array
    {
        return [
            // CarSaleAtProtocolCheck's steps run first, then:
            $this->send('PROTOCOL_PASSED'),
        ];
    }
}

class CarSaleApproved extends MachineScenario
{
    protected function machine(): string { return CarSalesMachine::class; }
    protected function description(): string { return 'Fully approved application'; }

    protected function parent(): string
    {
        return CarSaleAtDataCollection::class;
    }

    protected function steps(): array
    {
        return [
            // CarSaleAtProtocolCheck → CarSaleAtDataCollection steps run first, then:
            $this->send('VEHICLE_SUBMITTED', ['plate' => '34ABC123']),
            $this->send('PAYMENT_SELECTED', ['term' => 12]),
            $this->send('PERSONAL_INFO_SUBMITTED', [/* ... */]),
            $this->send('ADDRESS_INFO_SUBMITTED', [/* ... */]),
            $this->send('EMPLOYMENT_INFO_SUBMITTED', [/* ... */]),
            $this->send('CUSTOMER_INFO_SUBMITTED'),
            $this->send('DOCUMENTS_RECEIVED'),
            $this->send('ALLOCATION_APPROVED'),
        ];
    }
}
```

**Composition chain:** `CarSaleApproved` → `CarSaleAtDataCollection` → `CarSaleAtProtocolCheck`

**Merge rules:**

| Aspect | Behavior |
|--------|----------|
| `machine()` | Must match across the chain — validated by ScenarioPlayer, throws on mismatch |
| `arrange()` | Deep merged — child overrides parent stubs |
| `models()` | Parent models created first. Child can add new models or override by name (re-creating with different state). |
| `defaults()` | Deep merged — child defaults override parent defaults |
| `steps()` | Sequential — parent steps play first, then child steps |
| `params` (runtime) | Passed to the entire chain — parent and child both access the same resolved params |

---

## 10. Mid-Flight Scenarios — Playing on Existing Machines

### Use Case

In staging, a QA tester is manually walking through a `CarSalesMachine`. They've reached `checking_protocol` through the real UI. Now they want to fast-forward to `approved` without manually filling 8 more forms:

```php
// Machine already exists and is at checking_protocol
CarSaleFromProtocolToApproved::playOn(machineId: 'evt_01HXYZ...');
```

### The `from()` Method

Scenarios that operate on existing machines declare their expected starting state:

```php
class CarSaleFromProtocolToApproved extends MachineScenario
{
    protected function machine(): string { return CarSalesMachine::class; }
    protected function description(): string { return 'Fast-forward from protocol check to approved'; }

    protected function from(): ?string
    {
        return 'checking_protocol';  // machine must be in this state
    }

    protected function arrange(): array
    {
        return [
            CheckProtocolAction::class => ['protocol_eligible' => true],
        ];
    }

    protected function steps(): array
    {
        return [
            $this->send('PROTOCOL_PASSED'),
            $this->send('VEHICLE_SUBMITTED', ['plate' => '34ABC123']),
            $this->send('PAYMENT_SELECTED', ['term' => 12]),
            // ... more steps to reach approved
        ];
    }
}
```

### ScenarioPlayer Changes

`ScenarioPlayer::play()` accepts an optional `machineId` parameter:

```php
public function play(MachineScenario $scenario, array $params = [], ?string $machineId = null): ScenarioResult
```

When `machineId` is provided:

1. **Restore the machine** — `$machineClass::create(state: $machineId)` (existing EventMachine pattern)
2. **Validate `from()` state** — if `$scenario->getFrom()` is set, check `$machine->state->currentStateDefinition->key === $from`. Throw `ScenarioFailedException` on mismatch.
3. **Skip parent chain** — mid-flight scenarios don't use `parent()`. The existing machine IS the starting point.
4. **Skip model creation** — models already exist in the database (created during the real flow).
5. **Register stubs** — `arrange()` stubs still apply (external APIs need stubbing).
6. **Replay steps** — same as normal scenario flow.

### `play()` vs `playOn()` Decision Matrix

| Method | Machine | `from()` | `parent()` | `models()` |
|--------|---------|----------|------------|------------|
| `play()` | Creates new | Ignored | Resolved | Created |
| `playOn(machineId)` | Restores existing | Validated | Ignored | Skipped |

### Artisan Command

```bash
# Mid-flight: play scenario on existing machine
php artisan machine:scenario CarSaleFromProtocolToApproved \
    --machine-id=evt_01HXYZ...

# With parameter overrides
php artisan machine:scenario CarSaleFromProtocolToApproved \
    --machine-id=evt_01HXYZ... \
    --param="farmer_tckn:99887766655"
```

### HTTP Endpoint

Per-machine scenario endpoints are auto-registered via discovery when `scenarios_enabled=true`:

```
# New machine (same as before)
POST /machine/scenarios/{scenario}

# Existing machine (mid-flight)
POST /machine/scenarios/{scenario}/{machineId}
```

**Request:**

```http
POST /machine/scenarios/car-sale-from-protocol-to-approved/evt_01HXYZ...
Content-Type: application/json

{
    "params": {
        "farmer_tckn": "99887766655"
    }
}
```

### Error Cases

| Error | When | Exception |
|-------|------|-----------|
| Machine not found | `machineId` doesn't exist | `ScenarioFailedException` |
| State mismatch | Machine is at `awaiting_payment` but `from()` says `checking_protocol` | `ScenarioFailedException` with message: "Expected machine to be in state 'checking_protocol', but found 'awaiting_payment'" |
| Machine class mismatch | `machineId` belongs to `FindeksMachine` but scenario targets `CarSalesMachine` | `ScenarioConfigurationException` |
| `from()` set but `play()` called | Developer used `play()` instead of `playOn()` | Works — `from()` is only validated when `machineId` is provided |
| `parent()` set with `playOn()` | Conflicting: both parent chain and existing machine | `ScenarioConfigurationException`: "Mid-flight scenarios cannot use parent()" |

### Composition vs Mid-Flight

These are two different patterns for the same problem — "start from a known state":

| Pattern | How it gets to the starting state | When to use |
|---------|----------------------------------|-------------|
| **Composition** (`parent()`) | Replays parent scenario's steps | Reproducible, no pre-existing machine needed |
| **Mid-Flight** (`from()` + `playOn()`) | Machine already exists | QA/staging, machine arrived at state through real UI usage |

Both are valid. A `CarSaleAtDataCollection` scenario could use either approach depending on context.

---

## 11. Endpoint Integration — Scenario-Aware Responses

### Problem

QA uses the existing frontend in staging. They interact with the machine through its HTTP endpoints. They need to:
1. See which scenarios are available from the **current state** (after a transition)
2. Send a normal event AND attach a scenario to fast-forward the remaining states

Both should work through the **existing endpoint infrastructure** — no separate scenario endpoints needed.

### `available_scenarios` in Endpoint Response

Just like `available_events` shows which events the machine accepts from its current state, `available_scenarios` shows which scenarios can be played from the current state.

**Timing:** Both `available_events` and `available_scenarios` reflect the state **after** the transition — not the state before. The `$state` object returned by `$machine->send()` is used for both.

**Discovery:** When `MACHINE_SCENARIOS_ENABLED=true`, `MachineController::buildResponse()` queries `ScenarioDiscovery` for scenarios where `machine()` matches the current machine class AND `from()` matches the current state.

**Response format:**

```json
{
  "data": {
    "machine_id": "evt_01HXYZ...",
    "value": "checking_protocol",
    "context": { "..." },
    "available_events": ["PROTOCOL_PASSED", "PROTOCOL_FAILED"],
    "available_scenarios": [
      {
        "slug": "from-protocol-to-approved",
        "description": "Fast-forward from protocol check to approved",
        "from": "checking_protocol"
      },
      {
        "slug": "from-protocol-to-rejected",
        "description": "Fast-forward from protocol check to rejected",
        "from": "checking_protocol"
      }
    ]
  }
}
```

When `MACHINE_SCENARIOS_ENABLED=false`, the `available_scenarios` key is omitted entirely — zero overhead.

### Sending Events with Scenario Continuation

The `scenario` field can be added to any event request. When present (and scenarios are enabled), the event is processed normally first, then the scenario plays from the resulting state.

**Request:**

```http
POST /orders/{orderId}/protocol-passed
Content-Type: application/json

{
  "type": "PROTOCOL_PASSED",
  "payload": { "protocol_id": "PRT-001" },
  "scenario": "from-data-collection-to-approved"
}
```

**Flow inside `MachineController::executeEndpoint()`:**

```
1. $state = $machine->send(event: $event)
   → Machine transitions: checking_protocol → data_collection

2. Detect 'scenario' field in request + MACHINE_SCENARIOS_ENABLED=true

3. Resolve scenario class from slug
   → CarSaleFromDataCollectionToApproved

4. Validate scenario.from() === $state->currentStateDefinition->key
   → 'data_collection' === 'data_collection' ✓

5. ScenarioPlayer->play($scenario, $params, machineId: $rootEventId)
   → Replays steps: VEHICLE_SUBMITTED, PAYMENT_SELECTED, ...
   → Machine arrives at: approved

6. Response uses final state (after scenario replay)
   → value: 'approved'
   → available_events: ['...']
   → available_scenarios: [scenarios with from='approved']
```

**Error cases:**

| Error | Behavior |
|-------|----------|
| `scenario` field present but `MACHINE_SCENARIOS_ENABLED=false` | Field silently ignored — normal event processing only |
| Scenario slug not found | Return 422 with error message |
| Scenario `from()` doesn't match post-transition state | Return 422: "Scenario expects state 'X' but machine is at 'Y' after event" |
| Scenario step fails during replay | Return 422 with `ScenarioFailedException` details |

### Endpoint Registration via `MachineRouter::register()`

Scenario endpoint registration moves from `MachineServiceProvider` (global) into `MachineRouter::register()` (per-machine). When `MACHINE_SCENARIOS_ENABLED=true`, `MachineRouter` also registers per-machine scenario routes under the machine's prefix:

```
# Existing machine endpoints
POST /orders/create
POST /orders/{orderId}/submit
POST /orders/{orderId}/protocol-passed

# Auto-registered scenario endpoints (when scenarios exist for this machine)
GET  /orders/scenarios                                → list this machine's scenarios
POST /orders/scenarios/{slug}                         → play (new machine)
POST /orders/scenarios/{slug}/{machineId}             → play on existing machine (mid-flight)
GET  /orders/scenarios/{slug}/describe                → scenario details
```

**No global `/machine/scenarios/` route** — each machine owns its scenario endpoints under its own prefix. If a machine has no `MachineRouter::register()` call, it has no scenario endpoints.

### Design Decisions

| Decision | Why |
|----------|-----|
| `available_scenarios` reflects post-transition state | Same semantics as `available_events` — tells the frontend "what can I do NEXT from here" |
| `scenario` field in event request, not query param | Consistent with `type` and `payload` — all in one JSON body |
| Scenario `from()` validated against post-transition state | The event does its job first, then the scenario continues. Prevents invalid scenario activation. |
| Silent ignore when disabled | Frontend can always send `scenario` field — production simply ignores it |
| Per-machine scenario routes via `MachineRouter` | Follows EventMachine convention: everything lives under the machine's endpoint prefix |

---

## 12. Child Machine Scenarios

When a parent machine delegates to a child machine (via `machine` key on a state), the child scenario:

1. **Finds the spawned child machine instance** (via `machine_children` table)
2. **Registers child-specific `arrange()` stubs** in the container
3. **Replays child scenario `steps()`** against the child machine instance
4. **Child reaches its final state** → parent's `@done` transition fires naturally

**Internal flow for `CarSaleAtProtocolCheck`:**

```
ScenarioPlayer: send('CONSENT_GRANTED')
  → engine: parent transitions through @always to 'verification' (parallel)
  → engine: child machines spawned (FindeksMachine, TurmobMachine)
  → engine: machine_children records created
  → engine: child jobs dispatched (see Section 11 for async handling)

ScenarioPlayer: child(FindeksMachine)->scenario(FindeksReportCompleted)
  → lookup child machine_id from machine_children table
  → instantiate FindeksReportCompleted scenario
  → hydrate with params
  → register FindeksReportCompleted.arrange() stubs
  → replay FindeksReportCompleted.steps() against child machine instance
  → child reaches 'report_saved' (final state)
  → parent's @done.report_saved transition fires automatically

ScenarioPlayer: child(TurmobMachine)->scenario(TurmobVerified)
  → same flow
  → child reaches 'verified' (final state)
  → parent's @done transition fires automatically

After both children: parent's parallel @done guard passes → 'checking_protocol'
```

---

## 13. Async Dispatch, Timers & Job Actors

### Child Machine Dispatch (`machine` key)

**Problem:** In production, child machines are dispatched to queues:

```php
// CarSalesMachine state definition
'running' => [
    'machine' => FindeksMachine::class,
    'queue'   => config('queue.tubes.findeks_reports'),  // async!
]
```

When a scenario sends an event that enters this state, the engine:
1. **Creates the child machine** — writes `machine_children` record, initializes child context from `with` keys, creates root event
2. **Dispatches `ChildMachineJob`** to the queue — this is the part we need to intercept

The child machine *record* exists after step 1. The *job* in step 2 would process it asynchronously. The scenario needs the child to complete *now* so it can continue with the next step.

**Solution:** `Bus::fake([ChildMachineJob::class])` before replay starts. This intercepts only `ChildMachineJob` dispatch — all other jobs dispatch normally. The child machine record is already created by the engine, so the scenario player can find it in `machine_children` and replay the child scenario's steps directly against it.

```php
// Inside ScenarioPlayer — before replaying steps
Bus::fake([ChildMachineJob::class, ChildJobJob::class]);

try {
    foreach ($steps as $step) {
        if ($step instanceof ChildScenarioStep) {
            // Child machine record already exists (created by the preceding send())
            // Look it up and replay the child scenario against it
            $this->playChildScenario($step, $parentMachineId);
        } else {
            $machine->send($step->eventType, $step->payload);
        }
    }
} finally {
    // Restore original Bus dispatcher (stored before Bus::fake)
    Bus::swap($originalDispatcher);
}
```

**Ordering constraint:** `ChildScenarioStep` entries in `steps()` must appear after the `send()` that triggers child delegation. The engine creates the child machine during `send()` — the scenario player then finds and drives it via the subsequent `ChildScenarioStep`.

**Note:** This is NOT faking machine behavior — the child machine is fully created, its event history is real, context mutations are real. Only the *queue dispatch mechanism* is intercepted so the scenario can drive the child synchronously instead of waiting for a queue worker.

### Job Actors (`job` key / `ChildJobJob`)

EventMachine also supports `job` key for fire-and-forget job delegation (via `ChildJobJob`). These are intercepted the same way — `Bus::fake([ChildJobJob::class])` prevents dispatch, and the scenario player can optionally replay a job actor scenario if one is defined:

```php
$this->child(SomeJobActor::class)->scenario(SomeJobCompleted::class),
```

If no `ChildScenarioStep` is defined for a job actor, its dispatch is simply suppressed (fire-and-forget jobs are typically side effects that don't affect the parent machine's state).

### Timer Handling (`after` / `every`)

**Problem:** When a scenario enters a state with `after: 30` (seconds) or `every: 60`, the engine creates `machine_timer_fires` records. But the scenario needs to proceed immediately — it can't wait for wall-clock time.

**Solution:** ScenarioPlayer disables timer registration during replay:

```php
// ScenarioPlayer sets a flag before replay
app()->instance('scenario.timers_disabled', true);

// Timer registration code in MachineDefinition checks this flag
// and skips creating machine_timer_fires records
```

After the scenario completes and the machine is at the target state, timers for that state ARE registered normally (the flag is removed during cleanup). This way:
- Intermediate state timers don't fire during replay
- The final state's timers are active — the machine behaves normally from that point on

---

## 14. ScenarioPlayer — Runtime Engine

**Location:** `src/Scenarios/ScenarioPlayer.php`

```
play(scenario, params, machineId?)
│
├── 1. Validate environment (scenarios enabled?)
│     └── Throw ScenariosDisabledException if not
│
├── 2. Determine mode: new machine or mid-flight
│     ├── machineId provided → mid-flight mode
│     │   ├── Restore machine from machineId
│     │   ├── Validate from() state if defined
│     │   ├── Validate machine class matches scenario.machine()
│     │   └── Skip parent chain and model creation
│     └── machineId null → new machine mode
│         └── Resolve parent chain if scenario.parent() is set
│
├── 3. Hydrate scenario
│     └── scenario.hydrate(mergedParams, parentModels)
│
├── 4. Create models (skipped in mid-flight mode)
│     ├── Call scenario.models()
│     ├── For each: Factory::create() or invoke callable
│     └── scenario.addModel(name, instance) after each creation
│
├── 5. Register stubs
│     ├── Call scenario.arrange()
│     ├── Classify each entry (guard / action / service)
│     └── App::bind() or App::instance() for each
│
├── 6. Create or continue machine
│     ├── Mid-flight → already restored in step 2
│     ├── No parent → Machine::create() with first send()
│     └── Has parent → continue parent's machine instance
│
├── 7. Intercept async dispatch & suspend timers
│     ├── Bus::fake([ChildMachineJob::class, ChildJobJob::class])
│     └── app()->instance('scenario.timers_disabled', true)
│
├── 8. Replay steps
│     ├── ScenarioStep → Machine::send(eventType, payload)
│     └── ChildScenarioStep → find child in machine_children,
│         play child scenario against it
│
├── 9. Build ScenarioResult
│
└── 10. Cleanup
      ├── Unbind stubs from container
      ├── Bus::swap($originalDispatcher)
      ├── Remove scenario.timers_disabled flag
      └── Final state's timers are now registered normally
```

**Error handling:**

| Error | Behavior |
|-------|----------|
| Guard rejects a transition | Throw `ScenarioFailedException` with step index, event type, current state, guard class, and rejection reason |
| Invalid event for current state | Throw `ScenarioFailedException` with available events |
| Child machine not found in `machine_children` | Throw `ScenarioFailedException` — likely the preceding `send()` didn't trigger child spawn |
| Parent scenario fails | Exception propagates up — child scenario never starts |
| `machine()` mismatch in parent chain | Throw `ScenarioConfigurationException` at the start, before any execution |

---

## 15. Environment Gating

**Config addition to `config/machine.php`:**

```php
'scenarios' => [
    'enabled' => env('MACHINE_SCENARIOS_ENABLED', false),
    'path'    => app_path('Machines/Scenarios'),
],
```

**Enforcement points:**

| Point | Behavior when disabled |
|-------|----------------------|
| `ScenarioPlayer::play()` | Throws `ScenariosDisabledException` |
| `machine:scenario` artisan command | Prints error, exits with code 1 |
| HTTP endpoint registration | Routes not registered at all |
| Service provider | Scenario discovery skipped — zero overhead |

---

## 16. Execution: Artisan Command & HTTP Endpoint

### Artisan Command

**Location:** `src/Commands/ScenarioCommand.php`

```bash
# List all scenarios
php artisan machine:scenario --list

# List scenarios for a specific machine
php artisan machine:scenario --list --machine=CarSalesMachine

# Play a scenario (new machine)
php artisan machine:scenario CarSaleAtProtocolCheck

# Play with parameter overrides
php artisan machine:scenario CarSaleAtProtocolCheck \
    --param="farmer_tckn:99887766655" \
    --param="amount:25000000"

# Mid-flight: play scenario on existing machine
php artisan machine:scenario CarSaleFromProtocolToApproved \
    --machine-id=evt_01HXYZ...

# Mid-flight with parameter overrides
php artisan machine:scenario CarSaleFromProtocolToApproved \
    --machine-id=evt_01HXYZ... \
    --param="farmer_tckn:99887766655"
```

**Play output:**

```
 ┌─ Scenario: CarSaleAtProtocolCheck ─────────────────────────────────┐
 │ Machine:     CarSalesMachine                                       │
 │ Description: At protocol check — Findeks & TÜRMOB completed        │
 │ Parent:      —                                                     │
 │ Parameters:  farmer_tckn=99887766655, amount=25000000               │
 ├────────────────────────────────────────────────────────────────────┤
 │ [1/5] Creating models...                                           │
 │   ✓ farmer (id: 42, tckn: 99887766655)                             │
 │   ✓ retailer (id: 7)                                               │
 │   ✓ retailerUser (id: 15)                                          │
 │ [2/5] Registering stubs...                                         │
 │   ✓ HasConsentGuard → true                                         │
 │   ✓ IsFarmerNotEligibleGuard → false                               │
 │ [3/5] Replaying events...                                          │
 │   ✓ START_APPLICATION → routing → verification (32ms)              │
 │   ✓ CONSENT_GRANTED (12ms)                                         │
 │   ✓ [child] FindeksMachine: FindeksReportCompleted (6 steps, 89ms) │
 │   ✓ [child] TurmobMachine: TurmobVerified (2 steps, 24ms)         │
 │   ✓ → checking_protocol (@done, 8ms)                               │
 │ [4/5] Cleaning up stubs...                                         │
 │ [5/5] Building result...                                           │
 ├────────────────────────────────────────────────────────────────────┤
 │ ✓ Done! Machine is now at: checking_protocol                       │
 │   Machine ID: mach_01HXYZ...                                       │
 │   Root Event: evt_01HXYZ...                                        │
 │   Duration:   165ms                                                 │
 └────────────────────────────────────────────────────────────────────┘
```

**List output:**

```
 Machine Scenarios
 ═════════════════

 CarSalesMachine
 ───────────────
  CarSaleAtProtocolCheck     At protocol check — Findeks & TÜRMOB completed
  CarSaleAtDataCollection    At data collection — protocol passed         (parent: CarSaleAtProtocolCheck)
  CarSaleApproved            Fully approved application                   (parent: CarSaleAtDataCollection)

 FindeksMachine
 ──────────────
  FindeksReportCompleted     Findeks report successfully saved
  FindeksAwaitingPin         Findeks report requested, waiting for PIN

 TurmobMachine
 ─────────────
  TurmobVerified             TÜRMOB verification completed
```

### HTTP Endpoint

**Route registration:** Handled by `MachineServiceProvider` when scenarios are enabled.

```
POST   /machine/scenarios/{scenario}                → Play scenario (new machine)
POST   /machine/scenarios/{scenario}/{machineId}    → Play scenario on existing machine (mid-flight)
GET    /machine/scenarios                           → List available scenarios
GET    /machine/scenarios/{scenario}/describe        → Scenario details (params, description, from, parent)
```

**Request/Response:**

```http
POST /machine/scenarios/car-sale-at-protocol-check
Content-Type: application/json

{
    "params": {
        "farmer_tckn": "99887766655",
        "amount": 25000000
    }
}
```

```json
{
    "scenario": "CarSaleAtProtocolCheck",
    "machine": "CarSalesMachine",
    "machine_id": "mach_01HXYZ...",
    "current_state": "checking_protocol",
    "root_event_id": "evt_01HXYZ...",
    "models": {
        "farmer": { "id": 42, "tckn": "99887766655" },
        "retailer": { "id": 7 },
        "retailerUser": { "id": 15 }
    },
    "steps_executed": 6,
    "duration_ms": 165
}
```

**Slug convention:** Scenario class name converted to kebab-case. `CarSaleAtProtocolCheck` → `car-sale-at-protocol-check`.

---

## 17. Discovery & Registration

Scenario classes are discovered via directory scanning (similar to Laravel's event discovery):

**Default path:** `app/Machines/Scenarios/` (configurable via `machine.scenarios.path`)

```
app/Machines/Scenarios/
├── CarSales/
│   ├── CarSaleAtProtocolCheck.php
│   ├── CarSaleAtDataCollection.php
│   └── CarSaleApproved.php
├── Findeks/
│   ├── FindeksReportCompleted.php
│   └── FindeksAwaitingPin.php
└── Turmob/
    └── TurmobVerified.php
```

**Caching:** `php artisan machine:scenario-cache` (similar to `machine:cache` for machine discovery). Stores class map for production-like staging environments.

---

## 18. What NOT to Include

| Feature | Why excluded |
|---------|-------------|
| **Automatic rollback / cleanup** | Scenarios create persistent state intentionally — the point is to use the machine afterwards |
| **Parallel step execution** | Steps must be sequential — each depends on the state from the previous step |
| **Conditional steps** | Over-engineering. If you need different flows, create different scenarios or use composition. |
| **Step assertions** | Scenarios are not tests. They don't assert — they set up state. |
| **UI / dashboard** | Out of scope for v1. Artisan + HTTP endpoints are sufficient. |
| **Dry-run mode** | Complex to implement correctly, low value. The scenario either works or throws. |
| **Schedule / cron scenarios** | Scenarios are on-demand, not scheduled. |
| **Automatic model cleanup** | Models are part of the scenario's result. Cleanup is the consumer's responsibility. |

---

## 19. Migration: Removing the Old Scenario System

### Files to Modify

| File | Changes |
|------|---------|
| `src/Definition/MachineDefinition.php` | Remove `$scenarios` constructor parameter, `$scenariosEnabled` property, `createScenarioStateDefinitions()`, `getScenarioStateIfAvailable()`, and all 3 call sites of `getScenarioStateIfAvailable()` |
| `src/Behavior/EventBehavior.php` | Remove `getScenario()` method |
| `src/Testing/TestMachine.php` | Remove `withScenario()` method |
| `src/StateConfigValidator.php` | Remove `'scenarios_enabled'` from `ALLOWED_ROOT_KEYS` |

### Files to Delete

| File | Reason |
|------|--------|
| `tests/Stubs/Machines/MachineWithScenarios.php` | Old scenario stub |
| `tests/Features/ScenarioTest.php` | Old scenario test |
| `docs/advanced/scenarios.md` | Old scenario docs (will be replaced by new docs) |

### Tests to Update

| File | Changes |
|------|---------|
| `tests/Features/Testability/TestMachineTest.php` | Remove `withScenario()` tests |

### Documentation Cross-References to Update

- `docs/building/configuration.md` — remove `scenarios_enabled` config key
- `docs/behaviors/events.md` — remove scenario support in events
- `docs/testing/test-machine.md` — remove `withScenario()` method
- `docs/best-practices/testing-strategy.md` — remove scenario mentions

---

## 20. Implementation Checklist

### Phase 1: Foundation ✅

All foundation classes implemented:
`MachineScenario`, `ScenarioStep`, `ChildScenarioStep`, `ScenarioResult`, `ScenarioPlayer`,
`ScenarioGuardStub`, `ScenarioActionStub`, `ScenarioStubContract`,
`ScenariosDisabledException`, `ScenarioFailedException`, `ScenarioConfigurationException`,
`config/machine.php` scenarios section.

### Phase 1b: Mid-Flight Scenarios ✅

`from()`, `playOn()`, `getFrom()`, `ScenarioPlayer` accepts `machineId`,
state validation, skip parent/models in mid-flight, `ScenarioCommand --machine-id`,
per-machine mid-flight endpoint, `from` field in describe response.

### Phase 2: Composition & Child Scenarios ✅

`parent()` chain, `arrange()`/`models()`/`defaults()` merge, `machine()` validation,
`ChildScenarioStep` execution, child machine lookup, `Bus::fake` interception,
timer suspension/re-registration.

### Phase 3: Endpoint Integration ✅

`available_scenarios` in `buildResponse()`, `ScenarioDiscovery::forMachineAtState()`,
`scenario` field in event request, per-machine routes via `MachineRouter::register()`,
global routes removed from `MachineServiceProvider`, `ScenarioController` per-machine context.

### Phase 4: Execution Interfaces ✅

`ScenarioCommand` (play + list + --machine-id), `ScenarioController` (play, list, describe, playOn),
`ScenarioCacheCommand`.

### Phase 5: Remove Old Scenario System ✅

Old code removed from `MachineDefinition`, `EventBehavior`, `TestMachine`, `StateConfigValidator`.
Old stubs, tests, docs deleted. Cross-references updated.

### Phase 6: Testing ✅

All test files implemented:
`ScenarioPlayTest`, `ScenarioCompositionTest`, `ChildScenarioTest`, `ScenarioTimerTest`,
`ScenarioArrangeTest`, `ScenarioMidFlightTest`, `ScenarioCommandTest`, `ScenarioHttpTest`,
`ScenarioGatingTest`, `ScenarioErrorTest`, `ScenarioEndpointIntegrationTest`.
Test stubs: `TrafficLightsActiveScenario`, `TrafficLightsIncrementedScenario`,
`MidFlightMachine`, `MidFlightToActiveScenario`, `MidFlightFinishScenario`.

### Phase 7: Documentation ✅

`docs/advanced/scenarios.md`, `docs/laravel-integration/endpoints.md`,
`docs/laravel-integration/artisan-commands.md`, `docs/testing/overview.md` — all updated.

### Phase 8: Remaining

- [ ] DocTest attributes on all code blocks in `docs/advanced/scenarios.md`
