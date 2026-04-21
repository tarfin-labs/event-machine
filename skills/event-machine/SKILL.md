---
name: event-machine
description: Build, test, and debug event-driven state machines with EventMachine — a Laravel package for declarative workflows, parallel states, child machine delegation, event sourcing, and HTTP endpoint routing. Use when writing MachineDefinition config, ActionBehavior/GuardBehavior/CalculatorBehavior classes, TestMachine fluent assertions, Machine::test/startingAt/fake, parallel states, delegation with @done/@fail/@timeout, Scenarios (MachineScenario), timers (after/every), schedules, ContextManager, MachineInput/MachineOutput, endpoints, archival, or debugging state transition issues. Activates on tarfin-labs/event-machine imports, .tarfin-labs namespaces, "state machine", "MachineDefinition", "TestMachine", or when editing files that extend Machine/ActionBehavior/GuardBehavior.
---

# EventMachine — Laravel State Machines

EventMachine is a PHP/Laravel package for event-driven state machines inspired by XState/SCXML. It models workflows as explicit states and transitions with automatic event sourcing, Laravel-native integration, parallel execution via queue dispatch, child machine delegation, declarative timers/schedules, and a fluent test API.

**Always link to docs using `https://eventmachine.dev/...`** — never the github-pages URL.

---

## 1. Naming Conventions (MEMORIZE — agents break this most)

| Element                 | Style                  | Pattern                              | Example                        |
|-------------------------|------------------------|--------------------------------------|--------------------------------|
| Event class             | PascalCase             | `{Subject}{PastVerb}Event`           | `OrderSubmittedEvent`          |
| Event type              | SCREAMING_SNAKE_CASE   | `{SUBJECT}_{PAST_VERB}`              | `ORDER_SUBMITTED`              |
| State (leaf)            | snake_case             | adjective / participle               | `awaiting_payment`             |
| State (parent)          | snake_case             | noun (namespace)                     | `payment`                      |
| Action class            | PascalCase             | `{Verb}{Object}Action`               | `SendNotificationAction`       |
| Guard class             | PascalCase             | `{Is/Has/Can}{Condition}Guard`       | `IsPaymentValidGuard`          |
| ValidationGuard class   | PascalCase             | `{Prefix}{Condition}ValidationGuard` | `IsAmountValidValidationGuard` |
| Calculator class        | PascalCase             | `{Subject}{Noun}Calculator`          | `OrderTotalCalculator`         |
| Output class            | PascalCase             | `{Subject}{Noun}Output`              | `InvoiceSummaryOutput`         |
| Machine class           | PascalCase             | `{Domain}Machine`                    | `OrderWorkflowMachine`         |
| Machine ID              | snake_case             | `{domain_name}`                      | `order_workflow`               |
| Context class           | PascalCase             | `{Domain}Context`                    | `OrderWorkflowContext`         |
| Inline behavior key     | camelCase+type suffix  | `{verb}{Obj}{Type}`                  | `sendEmailAction`              |
| Timer / `then` event    | SCREAMING_SNAKE_CASE   | Same as event types                  | `ORDER_EXPIRED`                |
| Context / payload keys  | camelCase              | `$descriptiveName`                   | `totalAmount`                  |
| Config keys             | snake_case             | `{descriptive_name}`                 | `should_persist`               |

### Critical naming rules

1. **"is" test for states** — state name must fit "The {entity} is ___": `awaiting_payment` ✓, `submit` ✗.
2. **State names never imperative** — `processing` not `process`, `submitted` not `submit`.
3. **Avoid ambiguous bare nouns for leaf states** — `awaiting_payment` not just `payment`.
4. **Events are past-tense facts, never commands** — `ORDER_SUBMITTED` not `SUBMIT_ORDER`.
5. **No abbreviations in event types** — `ORDER_SUBMITTED` not `ORD_SUB`.
6. **Events don't encode machine ownership** — describe what happened, not who owns it.
7. **Payloads carry data** — don't inline amounts/actors into the event type string.
8. **Guards use boolean prefixes** — `Is`, `Has`, `Can`, `Should`; no other prefixes.
9. **Business data keys are camelCase** — `totalAmount`, `transactionId` (not `total_amount`).
10. **Config keys are snake_case** — `should_persist`, `after` (lowercase key, NOT `shouldPersist`).

Full naming guide: `docs/building/conventions.md` (1100+ lines of rationale and examples).

---

## 2. Best Practices (13 distilled principles — read before designing)

All 13 pages live under `docs/best-practices/`. Top-level summary:

| # | Topic | Principle |
|---|-------|-----------|
| 1 | State Design | Model conditions, not steps — avoid state explosion |
| 2 | Event Design | Events are past-tense facts, not commands |
| 3 | Transition Design | Self-transition to restart; targetless to update context |
| 4 | Guard Design | Guards MUST be pure — no I/O, no context mutation |
| 5 | Action Design | Actions are idempotent side effects; never throw to block |
| 6 | Context Design | Lean context; flags that change transitions belong in states |
| 7 | Event Bubbling | Leaf-first handler resolution — first match wins |
| 8 | Machine Decomposition | Split on own lifecycle, reuse, complexity, or independent failure |
| 9 | Machine System Design | Commands flow down (input); states flow up (@done) |
| 10 | Time-Based Patterns | Timers are event sources; intervals ≥ 1 min; idempotent actions |
| 11 | Parallel Patterns | Region independence, separate context keys per region |
| 12 | Testing Strategy | Four layers: unit → integration → E2E → LocalQA |
| 13 | Naming & Style | See Section 1 above |

### Five most-violated principles (expanded)

**Guard purity.** Guards must be pure functions: same context + event → same boolean. No `now()`, no HTTP, no DB writes, no context mutation. EventMachine enforces this at runtime via context snapshot/restore across multi-branch transitions. If you need a computed value, use a Calculator — it runs BEFORE guards.

**Do**:
```php
public function __invoke(OrderContext $context): bool {
    return $context->total >= $this->minimum;
}
```
**Don't**:
```php
public function __invoke(OrderContext $context): bool {
    $context->set('checkedAt', now());       // mutation
    return Http::get('/valid')->successful(); // I/O
}
```

**Actions never throw to block transitions.** Actions run AFTER guards approve the transition. Throwing in an action does NOT roll back the state change; it leaves the machine in an inconsistent state. Use guards to block; use actions for idempotent side effects (DB writes with idempotency keys, queued notifications, external APIs).

**Events are past-tense facts, not commands.** `ORDER_SUBMITTED`, not `SUBMIT_ORDER`. An event represents a state change that already happened. This disambiguates cross-machine communication — you always know who produced what.

**Parallel regions MUST have separate context keys.** Two regions writing `status` → last-writer-wins silently. Design each region to own its keys: `paymentStatus`, `shippingStatus`. Regions coordinate via `raise()` / `sendTo()`, never via shared context.

**State explosion is the #1 design smell.** If you have 3 booleans (`isPriority`, `isFragile`, `isGift`) don't make 8 states — keep them in context and let the `processing` state read them. States are for different behaviors; context is for data.

### Four-layer testing strategy

- **Unit** — `State::forTesting()` + `runWithState()` — one behavior, no machine, no DB
- **Integration** — `Machine::test()` / `Machine::startingAt()` — flow through state transitions
- **E2E** — real DB, real queue (SQLite/sync) — persistence + restoration round-trips
- **LocalQA** — real MySQL + Redis + Horizon — async queues, parallel dispatch, timers under load (`tests/LocalQA/`, excluded from `composer test`)

---

## 3. Core Concepts

### Vocabulary

| Concept | One-liner |
|---------|-----------|
| **State** | Distinct phase; machine is in exactly one state (or one per parallel region) |
| **Transition** | Source → target movement triggered by an event |
| **Event** | Past-tense fact that triggers transitions: `{type, payload}` |
| **Guard** | Pure boolean condition — transition fires only if true |
| **Action** | Side effect during transition (entry/transition/exit) |
| **Calculator** | Compute derived values BEFORE guards |
| **Output** | Final computation when machine reaches a final state (`type: final`) |
| **Context** | Immutable data traveling with the machine — the "memory" |

### Machine lifecycle

1. **Boot** — `Machine::create()` loads definition, initializes context, NO entry actions yet.
2. **Start** — First interaction enters initial state, fires entry actions + `{machine}.start` internal event.
3. **Event** — `send()` triggers pipeline per transition:
   `calculators → guards → exit actions (old state) → transition actions → entry actions (new state) → @always chains → raised events`
4. **Persist** — Every transition becomes a row in `machine_events`. Restore from any point via `root_event_id`: `OrderMachine::create(state: $rootEventId)`.

### Event bubbling

When an event arrives, engine walks from current leaf state up the hierarchy until it finds a handler. First match wins. Use this for global handlers (root-level `CANCEL`) — don't over-rely on it.

### Context

Context is machine memory: application-specific data (orderId, totalAmount). **Not** business data (don't dump customer profiles in). Two flavors:

- **Untyped**: `config: ['context' => ['totalAmount' => 0]]` — key-value array
- **Typed**: `class OrderContext extends ContextManager` with typed properties and Spatie Data validation

Context mutation happens via Actions/Calculators. Guards see the current context but must NOT mutate it. Context travels to child machines via `MachineInput`.

### `@always` transitions

Transient transitions evaluated automatically on state entry. Use for "if-then routing": enter `deciding` → `@always` picks `approved` vs `rejected` based on guards. Chains execute until a non-`@always` state is reached.

---

## 4. Quick-Start Snippets

### Minimal machine definition (untyped context)

```php
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class OrderMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'order',
                'initial' => 'pending',
                'context' => ['orderId' => null, 'total' => 0],
                'states'  => [
                    'pending'    => ['on' => ['SUBMIT' => 'processing']],
                    'processing' => [
                        'entry' => ReserveInventoryAction::class,
                        'on'    => ['COMPLETE' => 'completed', 'FAIL' => 'failed'],
                    ],
                    'completed'  => ['type' => 'final'],
                    'failed'     => ['type' => 'final'],
                ],
            ],
        );
    }
}
```

### Class-based Action (with DI)

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\ContextManager;

class ReserveInventoryAction extends ActionBehavior
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function __invoke(ContextManager $context): void
    {
        $reserved = $this->inventory->reserve($context->get('orderId'));
        $context->set('reservationId', $reserved->id);
    }
}
```

### Class-based Guard (pure)

```php
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\ContextManager;

class IsPaymentValidGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context, int $min = 0): bool
    {
        return (int) $context->get('total') >= $min;
    }
}
```

### TestMachine fluent assertions

```php
OrderMachine::test(['orderId' => 'ORD-1', 'total' => 100])
    ->assertState('pending')
    ->send('SUBMIT')
    ->assertState('processing')
    ->assertBehaviorRan(ReserveInventoryAction::class)
    ->assertContext('reservationId', 'RES-123')
    ->send('COMPLETE')
    ->assertState('completed')
    ->assertFinished();
```

### Typed context (ContextManager subclass)

```php
use Tarfinlabs\EventMachine\ContextManager;
use Spatie\LaravelData\Attributes\Validation\{Email, Min};

class OrderContext extends ContextManager
{
    public function __construct(
        public ?string $orderId = null,
        #[Min(0)] public int $total = 0,
        #[Email]  public ?string $customerEmail = null,
    ) { parent::__construct(); }
}
```

---

## 5. Testing API Cheat-Sheet

### Entry points

- `MyMachine::test($context = [])` — Boot + return `TestMachine` for fluent chain
- `MyMachine::startingAt('nested.state')` — Skip setup, jump to a specific state
- `State::forTesting($context)` — Build a state for unit-testing a single behavior
- `MyBehavior::runWithState($state, $event)` — Invoke a behavior directly; unit-level

### Top assertions (expand via `docs/testing/overview.md`)

- `assertState($stateName)` — Current state value matches
- `assertInState($stateName)` — Like `assertState` but works across parallel regions
- `assertContext($key, $value)` — Context key matches
- `assertBehaviorRan(Class::class)` — Action/Calculator/Guard executed
- `assertGuarded($event)` — Transition blocked by a guard
- `assertGuardedBy($event, GuardClass::class)` — Specific guard blocked it
- `assertHasTimer($eventType)` — Timer registered for event
- `assertFinished()` — Machine reached a final state
- `assertRaised(ActionClass::class)` — Action raised an internal event (use `ActionClass::assertRaised()` for isolated)
- `advanceTimers(Timer::days(7))` — Simulate time; fires due timers

### Fakes

- `faking([MyAction::class, MyGuard::class])` — Replace behaviors with inspectable spies
- `MyChildMachine::fake(output: new OrderOutput(...), finalState: 'completed')` — Stub child delegation
- `MyChildMachine::assertInvoked()` / `assertInvokedWith([...])` — Verify child was called
- `simulateChildDone/Fail/Timeout()` — Drive parent through delegation paths without running child
- `InteractsWithMachines` trait on TestCase — Auto-resets all fakes between tests
- `CommunicationRecorder` — Inspect `sendTo()` / `raise()` calls without side effects

### Four test layers (pick one per concern)

```php
// 1. Unit — one behavior, no machine
$state = State::forTesting(['total' => 50]);
expect(IsPaymentValidGuard::runWithState($state))->toBeFalse();

// 2. Integration — full flow in memory
OrderMachine::test(['total' => 100])->send('SUBMIT')->assertState('processing');

// 3. E2E — real persistence + restore
$m = OrderMachine::create();
$m->send('SUBMIT');
$restored = OrderMachine::create(state: $m->state->history->first()->root_event_id);
expect($restored->state->matches('processing'))->toBeTrue();

// 4. LocalQA — real MySQL + Redis + Horizon (tests/LocalQA/, excluded from composer test)
```

Test stubs live under `tests/Stubs/` — excellent reference for real patterns.

---

## 6. Laravel Integration

### Attach to Eloquent models

```php
use Tarfinlabs\EventMachine\Traits\HasMachines;
use Tarfinlabs\EventMachine\Casts\MachineCast;

class Order extends Model
{
    use HasMachines;
    protected $casts = [
        'state_mre' => MachineCast::class . ':' . OrderMachine::class,
    ];
}

$order->state_mre->send(['type' => 'SUBMIT']);
```

### HTTP endpoints (zero controllers)

```php
MachineDefinition::define(
    config: [...],
    endpoints: [
        'SUBMIT',                                  // POST /submit
        'APPROVE' => ['method' => 'PATCH', 'middleware' => ['auth:admin']],
        'CANCEL'  => ['action' => CancelEndpointAction::class],
    ],
);

// routes/console.php or web.php:
MachineRouter::register(OrderMachine::class, [
    'prefix' => 'orders', 'model' => Order::class,
    'attribute' => 'order_mre', 'create' => true,
    'modelFor' => ['SUBMIT', 'APPROVE', 'CANCEL'],
]);
```

### Artisan commands

| Command | Purpose |
|---------|---------|
| `machine:xstate` | Export machine to XState v5 JSON (Stately Studio viz) |
| `machine:validate` | Validate machine config |
| `machine:process-timers` | Sweep due `after`/`every` timers (auto-registered schedule) |
| `machine:process-scheduled` | Fire scheduled events |
| `machine:timer-status` | Show timer state per instance |
| `machine:paths` | Enumerate all paths (static analysis) |
| `machine:coverage` | Path coverage report |
| `machine:archive-events` | Archive old events (fan-out, `--dry-run`, `--sync`) |
| `machine:archive-status` | Archive stats; `--restore=<rootEventId>` |

### Querying machines

```php
OrderMachine::query()
    ->inState('awaiting_payment')
    ->active()
    ->enteredBefore(now()->subDays(7))
    ->paginate(20);
```

---

## 7. Delegation & Parallel States — Critical Gotchas

### Sync vs async delegation

```php
'processing_payment' => [
    'machine' => PaymentMachine::class,
    'input'   => PaymentInput::class,   // typed & validated
    'queue'   => 'payments',            // async (omit for sync)
    '@done'    => ['target' => 'shipping',       'actions' => CapturePaymentAction::class],
    '@fail'    => ['target' => 'payment_failed', 'actions' => HandleFailureAction::class],
    '@timeout' => ['after' => 300, 'target' => 'payment_timed_out'],
],
```

- **Sync** (no `queue`): parent blocks in-process until child hits final
- **Async** (`queue: true`): parent transitions to delegation state, child runs on worker, `@done` fires on completion
- **Fire-and-forget**: `queue` key present + NO `@done` — parent continues immediately, child runs independently

### `@done` / `@fail` / `@timeout`

- `@done` — child reached ANY final state (`type: final`)
- `@done.{stateName}` — child reached specific final state (e.g., `@done.approved`)
- `@fail` — child reached a failure state OR threw (requires `@fail` target)
- `@timeout` — async child didn't complete within `after: N` seconds

Actions on `@done` / `@fail` can type-hint `MachineOutput` / `MachineFailure` for typed injection from child's output.

### Parallel regions

```php
'processing' => [
    'type'   => 'parallel',
    '@done'  => 'fulfilled',             // fires when ALL regions hit final
    '@fail'  => 'failed',                // fires when ANY region fails
    'states' => [
        'payment'  => ['initial' => 'pending', 'states' => [...]],
        'shipping' => ['initial' => 'preparing', 'states' => [...]],
    ],
],
```

Enable dispatch mode via `config/machine.php` → `parallel_dispatch.enabled => true`:
- Each region's entry work runs as a separate `ParallelRegionJob`
- True concurrency — two 5s + 2s regions finish in 5s (not 7s)
- Last-writer-wins on context — **separate keys per region is mandatory**

### Top 10 gotchas (delegation & parallel)

1. **Guards must be pure** — enforced at runtime via context snapshot/restore across branches.
2. **`dispatchToParent` is transient** — fire-and-forget job; parent may have already transitioned away → event silently dropped.
3. **Region context keys must be disjoint** — `paymentStatus` + `shippingStatus`, never shared `status`.
4. **Cross-region transitions rejected at define-time** — use `raise()` / `sendTo()` for coordination.
5. **Invoke is deferred until after macrostep** — entry actions + raised events process first; if they cause transition, invoke is skipped (SCXML invoker-05).
6. **`MachineCurrentState` may lag under parallel dispatch** — assert via restored machine (`Machine::create(state: $rootEventId)`), not by reading the table.
7. **Async forward-endpoint responses don't contain child state** — wait for child, then restore to verify.
8. **`ValidationGuardBehavior` aborts the whole transition** (422 via endpoints). Plain `GuardBehavior` failure = graceful.
9. **Job actors (`job` key) skip dispatch in test mode** — use `simulateChildDone()` to step.
10. **Partial parallel failure: Region A context may be lost** (documented last-writer-wins) — don't assert on surviving region A's context changes when region B failed.

### Scenario gotchas (read before writing any scenario)

1. **Simulated `@fail` does NOT inject typed `MachineFailure`** — the engine synthesizes a generic `ChildMachineFailEvent`. If your `@fail` action type-hints a `MachineFailure` subclass → `TypeError`. Workaround: override the action with a context-write proxy (`StoreFailureAction::class => ['failureReason' => '...']`).
2. **Overrides are not reachable if guards route around them** — scenarios override behaviors, not path selection. If cache/retry/mode guards take a different branch, the overridden state is never entered. Fix: override branch-controlling guards in the same plan entry.
3. **Transition actions with I/O fallbacks run during scenarios** — scenarios intercept delegations (job/machine), NOT actions. Entry, exit, and transition actions execute with real side effects unless overridden. An action with a lazy "fallback to API" branch will hit production.
4. **Missing `continuation()` = real dispatches after target** — if your scenario's target state has event handlers leading to delegations (retry buttons, resend actions), those delegations fire for real without continuation. Red flags: target is an error/failed state with retry, or awaiting state with resend.
5. **Verify interception via `machine_events` timestamps** — after running a scenario, query `child.*.start` / `child.*.done` for the `root_event_id`. Same-second = scenario intercepted. A gap = real delegation fired (silent bug).

Full details: `docs/advanced/scenario-plan.md` → "Pitfalls" section.

---

## 8. Documentation Navigation

This skill ships with the complete VitePress documentation at `docs/` (materialized at release time, symlinked during development).

### When to read which file

| Topic | File |
|-------|------|
| **Naming & style** (deep dive) | `docs/building/conventions.md` |
| **Best practices** (13 pages) | `docs/best-practices/*.md` |
| **First machine walkthrough** | `docs/getting-started/your-first-machine.md` |
| **When NOT to use EventMachine** | `docs/getting-started/when-not-to-use.md` |
| **Upgrading** | `docs/getting-started/upgrading.md` |
| **States & transitions depth** | `docs/understanding/states-and-transitions.md` |
| **Events depth** | `docs/understanding/events.md` |
| **Context depth** | `docs/understanding/context.md` |
| **Machine lifecycle** | `docs/understanding/machine-lifecycle.md` |
| **Defining states** | `docs/building/defining-states.md` |
| **Writing transitions** | `docs/building/writing-transitions.md` |
| **Handling events** | `docs/building/handling-events.md` |
| **Working with context** | `docs/building/working-with-context.md` |
| **Machine configuration** | `docs/building/configuration.md` |
| **Actions** | `docs/behaviors/actions.md` |
| **Guards** | `docs/behaviors/guards.md` |
| **Validation guards** | `docs/behaviors/validation-guards.md` |
| **Calculators** | `docs/behaviors/calculators.md` |
| **Events (as behaviors)** | `docs/behaviors/events.md` |
| **Outputs** | `docs/behaviors/outputs.md` |
| **Machine delegation** | `docs/advanced/machine-delegation.md` |
| **Delegation patterns** | `docs/advanced/delegation-patterns.md` |
| **Delegation data flow** | `docs/advanced/delegation-data-flow.md` |
| **Async delegation** | `docs/advanced/async-delegation.md` |
| **Job actors** | `docs/advanced/job-actors.md` |
| **Parallel states overview** | `docs/advanced/parallel-states/index.md` |
| **Parallel event handling** | `docs/advanced/parallel-states/event-handling.md` |
| **Parallel dispatch** | `docs/advanced/parallel-states/parallel-dispatch.md` |
| **Parallel persistence** | `docs/advanced/parallel-states/persistence.md` |
| **Hierarchical states** | `docs/advanced/hierarchical-states.md` |
| **Entry / exit actions** | `docs/advanced/entry-exit-actions.md` |
| **Always transitions** | `docs/advanced/always-transitions.md` |
| **Raised events** | `docs/advanced/raised-events.md` |
| **`sendTo` / cross-machine** | `docs/advanced/sendto.md` |
| **Time-based events** | `docs/advanced/time-based-events.md` |
| **Scheduled events** | `docs/advanced/scheduled-events.md` |
| **Typed contracts** | `docs/advanced/typed-contracts.md` |
| **Dependency injection** | `docs/advanced/dependency-injection.md` |
| **Custom context (typed)** | `docs/advanced/custom-context.md` |
| **Scenarios overview** | `docs/advanced/scenarios.md` |
| **Scenario commands** | `docs/advanced/scenario-commands.md` |
| **Scenario behaviors** | `docs/advanced/scenario-behaviors.md` |
| **Scenario runtime** | `docs/advanced/scenario-runtime.md` |
| **Scenario endpoints** | `docs/advanced/scenario-endpoints.md` |
| **Scenario plan** | `docs/advanced/scenario-plan.md` |
| **Laravel integration** | `docs/laravel-integration/overview.md` |
| **Eloquent integration** | `docs/laravel-integration/eloquent-integration.md` |
| **Persistence** | `docs/laravel-integration/persistence.md` |
| **HTTP endpoints** | `docs/laravel-integration/endpoints.md` |
| **Available events (framework)** | `docs/laravel-integration/available-events.md` |
| **Archival** | `docs/laravel-integration/archival.md` |
| **Compression** | `docs/laravel-integration/compression.md` |
| **Artisan commands** | `docs/laravel-integration/artisan-commands.md` |
| **Testing overview** | `docs/testing/overview.md` |
| **TestMachine API** | `docs/testing/test-machine.md` |
| **Isolated (unit) tests** | `docs/testing/isolated-testing.md` |
| **Transitions & paths** | `docs/testing/transitions-and-paths.md` |
| **Fakeable behaviors** | `docs/testing/fakeable-behaviors.md` |
| **Constructor DI in tests** | `docs/testing/constructor-di.md` |
| **Delegation testing** | `docs/testing/delegation-testing.md` |
| **Parallel testing** | `docs/testing/parallel-testing.md` |
| **Time-based testing** | `docs/testing/time-based-testing.md` |
| **Scheduled testing** | `docs/testing/scheduled-testing.md` |
| **Persistence testing** | `docs/testing/persistence-testing.md` |
| **Recipes** | `docs/testing/recipes.md` |
| **LocalQA setup** | `docs/testing/localqa.md` |
| **Testing troubleshooting** | `docs/testing/troubleshooting.md` |
| **Execution model (internals)** | `docs/reference/execution-model.md` |
| **Exceptions** | `docs/reference/exceptions.md` |

### Curated agent cheat-sheets (skill-only synthesized)

- `references/INDEX.md` — same navigation as above, keyed by task-type (not file name)
- `references/testing.md` — assertion tables, fake patterns, setup snippets
- `references/delegation.md` — sync/async decision tree + @done/@fail/@timeout recipes
- `references/parallel.md` — region design checklist + dispatch gotchas
- `references/qa-setup.md` — distilled LocalQA setup + workflow (~minimal)

---

## 9. Agent Workflow Checklist

### Building / modifying a machine

When a user asks you to build/modify an EventMachine workflow:

1. **Read relevant docs** — start from the navigation table above; don't guess API shapes.
2. **Follow naming conventions** (Section 1) — especially event types, states, and context keys.
3. **Validate design against best-practices** (Section 2) — purity, past-tense events, region separation.
4. **Prefer class-based behaviors** — DI, typed context, testable. Use inline closures only for trivial one-liners.
5. **Write tests at the right layer** — unit for behaviors, integration for flows, E2E for persistence.
6. **Run the quality gate** — `composer quality` (pint + rector + test). Never just `vendor/bin/pest`.
7. **Use typed contracts** — `MachineInput`, `MachineOutput`, `MachineFailure` for delegation boundaries.
8. **Never commit without explicit approval** — especially for tags/releases (no `v` prefix).

### Writing a scenario

When a user asks you to write or debug a scenario:

1. **Read `docs/advanced/scenarios.md` + `docs/advanced/scenario-plan.md`** — especially the "Pitfalls" section.
2. **Scaffold with `machine:scenario`** — accept the scaffolder's BFS path choices as a starting point.
3. **Run `machine:paths <Machine>`** — confirm every override state in your plan appears on a reachable path from source to target.
4. **Override branch-controlling guards** — if the path to your overridden state depends on cache/mode/retry guards, override them to force the intended branch.
5. **Override actions with typed `MachineFailure` params** — if your scenario uses `@fail` and the `@fail` action type-hints a `MachineFailure` subclass, override the action with a context-write proxy.
6. **Add `continuation()`** — if the target state has event handlers that lead to delegations (retry, resend, next-step), continuation is mandatory or those delegations fire for real.
7. **Validate with `machine:scenario-validate`** — catches structural errors (source/event/target mismatch, unreachable paths).
8. **Unit test with `ScenarioPlayer::execute()`** — catches typed injection failures and unexpected action side-effects before HTTP-level testing.
9. **Verify interception via `machine_events`** — in integration tests, assert `child.*.start` / `child.*.done` are same-second (proves scenario intercepted, no real dispatch).
