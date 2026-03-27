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
10. [Child Machine Scenarios](#10-child-machine-scenarios)
11. [Async Dispatch, Timers & Job Actors](#11-async-dispatch-timers--job-actors)
12. [ScenarioPlayer — Runtime Engine](#12-scenarioplayer--runtime-engine)
13. [Environment Gating](#13-environment-gating)
14. [Execution: Artisan Command & HTTP Endpoint](#14-execution-artisan-command--http-endpoint)
15. [Discovery & Registration](#15-discovery--registration)
16. [What NOT to Include](#16-what-not-to-include)
17. [Migration: Removing the Old Scenario System](#17-migration-removing-the-old-scenario-system)
18. [Implementation Checklist](#18-implementation-checklist)

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
│
├── defaults(): array                → default parameters (overridable at runtime)
├── arrange(): array                 → stub definitions for guards/actions/services
├── models(): array                  → Eloquent model factory definitions
├── steps(): array<ScenarioStep|ChildScenarioStep>
│
├── param(key): mixed                → access resolved parameters
├── model(name): Model               → access created models
│
└── play(params): ScenarioResult     → static entry point
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

    // ─── Introspection (used by artisan --list, HTTP describe) ──────

    public function getMachine(): string { return $this->machine(); }
    public function getDescription(): string { return $this->description(); }
    public function getParent(): ?string { return $this->parent(); }
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

## 10. Child Machine Scenarios

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

## 11. Async Dispatch, Timers & Job Actors

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

## 12. ScenarioPlayer — Runtime Engine

**Location:** `src/Scenarios/ScenarioPlayer.php`

```
play(scenario, params)
│
├── 1. Validate environment (scenarios enabled?)
│     └── Throw ScenariosDisabledException if not
│
├── 2. Resolve parent chain
│     └── If scenario.parent() is set:
│         ├── Recursively play parent scenario first
│         └── Get parent's ScenarioResult (machine instance, models)
│
├── 3. Hydrate scenario
│     └── scenario.hydrate(mergedParams, parentModels)
│
├── 4. Create models
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

## 13. Environment Gating

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

## 14. Execution: Artisan Command & HTTP Endpoint

### Artisan Command

**Location:** `src/Commands/ScenarioCommand.php`

```bash
# List all scenarios
php artisan machine:scenario --list

# List scenarios for a specific machine
php artisan machine:scenario --list --machine=CarSalesMachine

# Play a scenario
php artisan machine:scenario CarSaleAtProtocolCheck

# Play with parameter overrides
php artisan machine:scenario CarSaleAtProtocolCheck \
    --param="farmer_tckn:99887766655" \
    --param="amount:25000000"
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
POST   /machine/scenarios/{scenario}          → Play scenario
GET    /machine/scenarios                     → List available scenarios
GET    /machine/scenarios/{scenario}/describe  → Scenario details (params, description, parent)
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

## 15. Discovery & Registration

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

## 16. What NOT to Include

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

## 17. Migration: Removing the Old Scenario System

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

## 18. Implementation Checklist

### Phase 1: Foundation

- [ ] `src/Scenarios/MachineScenario.php` — abstract base class
- [ ] `src/Scenarios/ScenarioStep.php` — value object
- [ ] `src/Scenarios/ChildScenarioStep.php` — value object
- [ ] `src/Scenarios/ScenarioResult.php` — value object
- [ ] `src/Scenarios/ScenarioPlayer.php` — engine (params, models, stubs, event replay)
- [ ] `src/Scenarios/ScenarioGuardStub.php` — guard stub wrapper
- [ ] `src/Scenarios/ScenarioActionStub.php` — action stub wrapper
- [ ] `src/Scenarios/ScenarioStubContract.php` — interface for explicit action stub mapping
- [ ] `src/Exceptions/ScenariosDisabledException.php`
- [ ] `src/Exceptions/ScenarioFailedException.php`
- [ ] `src/Exceptions/ScenarioConfigurationException.php`
- [ ] `config/machine.php` — add `scenarios` section

### Phase 2: Composition & Child Scenarios

- [ ] `parent()` chain resolution in ScenarioPlayer
- [ ] `arrange()` merge across parent chain
- [ ] `models()` merge across parent chain (parent models accessible via `$this->model()`)
- [ ] `defaults()` merge across parent chain
- [ ] `machine()` validation across parent chain
- [ ] ChildScenarioStep execution in ScenarioPlayer
- [ ] Child machine lookup via `machine_children` table
- [ ] Async child dispatch interception (`Bus::fake` for `ChildMachineJob` + `ChildJobJob`)
- [ ] Job actor (`job` key) handling — suppress dispatch, optional scenario
- [ ] Timer suspension during replay (`scenario.timers_disabled` flag)
- [ ] Timer re-registration for final state after replay completes

### Phase 3: Execution Interfaces

- [ ] `src/Commands/ScenarioCommand.php` (play + list)
- [ ] `src/Scenarios/ScenarioController.php` (play, list, describe)
- [ ] Route registration in `MachineServiceProvider` (gated by config)
- [ ] `src/Commands/ScenarioCacheCommand.php`

### Phase 4: Remove Old Scenario System

- [ ] Remove code from `MachineDefinition`, `EventBehavior`, `TestMachine`, `StateConfigValidator`
- [ ] Delete old scenario stubs, tests, docs
- [ ] Update all documentation cross-references

### Phase 5: Testing & Documentation

- [ ] Test stubs: example scenarios for TrafficLights or similar package test machine
- [ ] `tests/Features/Scenarios/ScenarioPlayTest.php` — basic play, params, models, stubs
- [ ] `tests/Features/Scenarios/ScenarioCompositionTest.php` — parent chain, merge rules
- [ ] `tests/Features/Scenarios/ChildScenarioTest.php` — child replay, async interception, job actors
- [ ] `tests/Features/Scenarios/ScenarioTimerTest.php` — timer suspension during replay, re-registration after
- [ ] `tests/Features/Scenarios/ScenarioArrangeTest.php` — guard/action/service stubs
- [ ] `tests/Features/Scenarios/ScenarioCommandTest.php` — artisan play + list
- [ ] `tests/Features/Scenarios/ScenarioHttpTest.php` — HTTP endpoints
- [ ] `tests/Features/Scenarios/ScenarioGatingTest.php` — disabled environment
- [ ] `tests/Features/Scenarios/ScenarioErrorTest.php` — failure cases
- [ ] `docs/advanced/scenarios.md` (new — replaces old)
- [ ] `docs/testing/overview.md` — update decision table with scenario layer
- [ ] `docs/artisan-commands.md` — add scenario commands
- [ ] DocTest attributes on all code blocks

### Phase 6: Quality Gate

- [ ] `composer pint && composer rector`
- [ ] `composer test`
- [ ] `composer larastan`
- [ ] DocTest verification
