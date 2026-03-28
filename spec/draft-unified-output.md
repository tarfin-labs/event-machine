# Unified State Output

**Status:** Draft
**Date:** 2026-03-28

---

## Problem

Six separate mechanisms control what data leaves a machine:

| Mechanism | Scope | Purpose |
|-----------|-------|---------|
| `toResponseArray()` | Machine-wide | Serialize all context (+ computed properties) |
| `contextKeys` | Endpoint | Filter toResponseArray() to specific keys |
| `result` (endpoint) | Endpoint | Computed response via ResultBehavior |
| `result` (final state) | Final state | $machine->result() output |
| `output` (final state) | Final state | Child → parent data transfer |
| `availableEvents` | Endpoint | Sometimes included, sometimes not |

**Consequences:**
- Two different response shapes (with/without result)
- `availableEvents` lost when result is used
- No state-aware responses — `toResponseArray()` is the same shape regardless of current state
- `output` and `result` on final states serve overlapping purposes
- Endpoints need individual result definitions for state-aware responses

---

## Solution: One Keyword — `output`

Replace `result`, `contextKeys`, and `output` with a single unified `output` key that works on **any state** and **any endpoint**.

### Why `output`?

- XState v5 alignment — they use `output`
- Natural at all levels: "what does this state output?"
- No finality implied — works for mid-flow states
- Already exists in EventMachine (final state child→parent) — meaning expands, not changes
- Aligns with `MachineOutput` from the typed contracts spec

### Renames

| Old | New |
|-----|-----|
| `result` (state config key) | `output` |
| `result` (endpoint config key) | `output` |
| `contextKeys` (endpoint/state) | `output => ['key1', 'key2']` |
| `output` (child→parent on final) | `output` (same key, expanded scope) |
| `ResultBehavior` | `OutputBehavior` |
| `behavior.results` | `behavior.outputs` |
| `$machine->result()` | `$machine->output()` |
| `{Name}Result` class convention | `{Name}Output` |

---

## `output` Accepted Formats

```php
// 1. Not defined → toResponseArray() fallback (includes computed properties)

// 2. Empty array → no context in response (metadata only)
'output' => [],

// 3. Array of strings → filter toResponseArray() to these keys (computed included)
'output' => ['installmentOptions', 'totalCashPrice', 'total'],

// 4. Class reference → OutputBehavior
'output' => PaymentOptionsOutput::class,

// 5. Inline key → resolved from behavior.outputs
'output' => 'paymentOptionsOutput',

// 6. Closure → inline output behavior
'output' => fn (ContextManager $ctx) => [
    'options' => $ctx->installmentOptions,
    'total'   => $ctx->totalCashPrice?->getAmount()?->toFloat(),
],
```

Same flexibility as `entry`, `guards`, `actions` — one key, multiple formats.

### Disambiguation

| Format | Detection | Interpretation |
|--------|-----------|---------------|
| Not set | `!isset` | toResponseArray() fallback |
| `[]` | Empty array | No context data |
| `['key1', 'key2']` | Array of non-class strings | Field filtering |
| `Output::class` | String + class_exists | Output behavior class |
| `'inlineKey'` | String + !class_exists | Resolve from behavior.outputs |
| `fn () => ...` | Closure | Inline output behavior |

---

## State-Level Output

Any state can define its output — not just final states:

```php
'states' => [
    'idle' => [
        'on' => ['START' => 'data_collection'],
        // output not defined → toResponseArray()
    ],
    'awaiting_vehicle' => [
        'output' => [],                                       // metadata only
        'on'     => ['SUBMIT_VEHICLE' => 'calculating'],
    ],
    'calculating' => [
        '@always' => ['target' => 'awaiting_payment_options', 'actions' => CalculatePricesAction::class],
    ],
    'awaiting_payment_options' => [
        'output' => ['installmentOptions', 'totalCashPrice'], // filtered context
        'on'     => ['PAYMENT_OPTIONS_SELECTED' => 'done'],
    ],
    'under_review' => [
        'output' => CustomerReviewOutput::class,              // computed
        'on'     => ['SUBMIT' => 'completed'],
    ],
    'completed' => [
        'type'   => 'final',
        'output' => OrderCompletedOutput::class,              // final + child→parent
    ],
],
```

Each state answers: **"What do I look like to the outside world?"**

### Parallel States

When the machine is in a parallel state, `currentStateDefinition` points to the parallel state itself (not individual regions). The parallel state's output applies:

```php
'data_collection' => [
    'type'   => 'parallel',
    'output' => DataCollectionSummaryOutput::class,  // output for the whole parallel state
    'states' => [
        'retailer' => [...],
        'customer' => [...],
    ],
],
```

The output behavior has access to the full context (written by all regions) and can compose data from all regions into a single response.

**Region states within a parallel state MUST NOT define `output`.** Defining `output` on a region or its child states throws `InvalidOutputDefinitionException` at definition time (`machine:validate-config` also catches this).

Only the parallel state itself can define `output`. It has full context access (all regions' data) and composes the response explicitly — no ambiguity, no merge conflicts, no partial data from incomplete regions.

### Transient States

States with `@always` transitions are never observed by external consumers — the machine passes through them immediately. Defining `output` on a transient state throws `InvalidOutputDefinitionException` at definition time. The output would never be read.

### Hierarchical (Compound) States

When a compound state and its child atomic state both define `output`, the **atomic state's output wins** — it is the most specific:

```php
'payment' => [
    'initial' => 'pending',
    'output'  => PaymentSummaryOutput::class,   // default for all children
    'states'  => [
        'pending'  => [
            'output' => PendingPaymentOutput::class,  // overrides parent
            'on'     => ['PAY' => 'settled'],
        ],
        'settled'  => [
            // no output → parent's PaymentSummaryOutput applies
            'type' => 'final',
        ],
    ],
],
```

Resolution within hierarchy:
1. Atomic state has `output`? → use it
2. Parent compound state has `output`? → use it
3. Neither → `toResponseArray()`

This does NOT apply to parallel states — parallel regions cannot define output (see above).

---

## Endpoint Integration

Endpoints automatically use the current state's output. Override with endpoint-level output:

```php
endpoints: [
    // No output defined → uses current state's output
    StatusRequestedEvent::class => ['uri' => '/status', 'method' => 'GET'],

    // Endpoint-level override
    ResumeRequestedEvent::class => ['uri' => '/resume', 'method' => 'GET', 'output' => ResumeOutput::class],

    // Filtered
    PriceRequestedEvent::class  => ['uri' => '/price', 'method' => 'GET', 'output' => ['totalCashPrice', 'installmentOptions']],
],
```

### Resolution Chain

```
1. Endpoint has output?     → use it
2. Current state has output? → use it
3. Neither                   → toResponseArray()
```

`output => []` (empty array) is NOT the same as "not defined." Empty array means "return no context data" (metadata only). Not defined means fallback to toResponseArray().

---

## Response Envelope

Always the same structure — no more two different shapes:

```json
{
    "data": {
        "id": "01JQXYZ...",
        "machineId": "car_sales",
        "state": ["car_sales.data_collection.retailer.awaiting_payment_options"],
        "availableEvents": ["PAYMENT_OPTIONS_SELECTED", "VEHICLE_EDIT_REQUESTED"],
        "output": {
            "installmentOptions": [
                {"installment": 12, "totalAmount": 165000, "monthlyAmount": 13750},
                {"installment": 24, "totalAmount": 180000, "monthlyAmount": 7500},
                {"installment": 36, "totalAmount": 195000, "monthlyAmount": 5416}
            ],
            "totalCashPrice": 150000
        }
    }
}
```

| Key | Value | Always present |
|-----|-------|---------------|
| `id` | Root event ID (machine instance identifier) | Yes |
| `machineId` | Machine definition ID (e.g., `car_sales`) | Yes |
| `state` | Current state value array | Yes |
| `availableEvents` | Event types valid from current state | Yes |
| `output` | State-specific data (varies per state) | Yes (empty `{}` when `output => []`) |

---

## Child Machine Integration

Final state `output` serves both API consumers AND parent machines. The child defines ONE output; the parent receives it via `ChildMachineDoneEvent`.

### How it flows

1. Child reaches final state → child's `output` behavior runs
2. Output result is placed in `ChildMachineDoneEvent::payload['output']` (replaces the old filtered context array)
3. Parent's `@done` action receives the event with the child's output

```php
// Child machine — defines what it produces
'completed' => [
    'type'   => 'final',
    'output' => PaymentCompletedOutput::class,
    // Produces: {paymentId, transactionRef, total, receiptUrl}
],

// Parent machine — receives child's output via event payload
'@done' => [
    'target'  => 'shipped',
    'actions' => function (ContextManager $ctx, EventBehavior $event): void {
        $ctx->set('paymentId', $event->payload['output']['paymentId']);
    },
],
```

With typed contracts (future):

```php
'@done' => [
    'target'  => 'shipped',
    'actions' => function (ContextManager $ctx, PaymentCompletedOutput $output): void {
        $ctx->set('paymentId', $output->paymentId);  // IDE autocomplete
    },
],
```

No separate `output` key for child→parent filtering needed. The child's output behavior IS the contract.

---

## Computed Properties

`toResponseArray()` merges `toArray()` + `computedContext()`. Computed properties work in all output formats:

```php
class OrderContext extends ContextManager
{
    public int $subtotal = 0;
    public int $tax = 0;

    protected function computedContext(): array
    {
        return ['total' => $this->subtotal + $this->tax];
    }
}
```

| Output format | Computed properties |
|--------------|-------------------|
| Not defined (fallback) | Included — toResponseArray() merges computed values |
| `['subtotal', 'total']` | Included — filters toResponseArray() which contains computed values |
| `OutputClass::class` | Behavior must compute explicitly: `$ctx->subtotal + $ctx->tax` |
| `fn ($ctx) => [...]` | Closure must compute explicitly: `$ctx->subtotal + $ctx->tax` |

For OutputBehavior and closures, computed values from `computedContext()` are NOT automatically available as properties. The behavior has full access to the ContextManager and can calculate any derived values directly.

---

## `$machine->output()`

Replaces `$machine->result()`. Returns only the **output content** (not the envelope — that's an HTTP concern).

```php
$machine = CarSalesMachine::create(state: $rootEventId);

// Returns state-specific output content (array, object, or scalar)
$currentOutput = $machine->output();

// For broadcast
CarSalesApplicationBroadcastEvent::dispatch(
    $machine->state->context->machineId(),
    $machine->state->value,
    $machine->output(),           // state-aware content
    $machine->availableEvents(),  // always available
);
```

Resolution:
1. Current state has `output`? → run it, return result
2. No output defined? → return `toResponseArray()`

`output => []` returns empty array. Undefined output returns `toResponseArray()`. `$machine->output()` returns whatever the output behavior produces (including null if the behavior returns null). When no output is defined, returns `toResponseArray()` which is never null for context-backed machines.

Final state check is now explicit — not `output() !== null`:

```php
$isDone = $machine->state->currentStateDefinition->type === StateDefinitionType::FINAL;
```

---

## OutputBehavior

Replaces `ResultBehavior`. Same parameter injection system:

```php
class PaymentOptionsOutput extends OutputBehavior
{
    public function __invoke(ContextManager $ctx): array
    {
        return [
            'installmentOptions' => $ctx->installmentOptions,
            'totalCashPrice'     => $ctx->totalCashPrice?->getAmount()?->toFloat(),
        ];
    }
}
```

Available injected types:

| Type | What's Injected |
|------|----------------|
| `ContextManager` (or subclass) | Machine context |
| `EventBehavior` (or subclass) | Triggering event |
| `State` | Current state object |
| `EventCollection` | Full event history |
| `ForwardContext` | Child context (forwarded endpoints only) |

Constructor DI for external services works the same way.

### Naming Convention

| Element | Convention | Example |
|---------|-----------|---------|
| Class | `{Description}Output` | `PaymentOptionsOutput` |
| Inline key | `{description}Output` | `'paymentOptionsOutput'` |
| Config key | `output` | `'output' => PaymentOptionsOutput::class` |
| Behavior array | `outputs` | `'outputs' => ['paymentOptionsOutput' => PaymentOptionsOutput::class]` |

---

## Forwarded Endpoint Output

Forwarded endpoints (`forward` config on delegation states) also support `output` instead of `contextKeys`:

```php
// Old
'forward' => [
    ReportRequestedEvent::class => [
        'uri'         => '/findeks/report-requested',
        'contextKeys' => ['phones', 'maskedPhone', 'queryId'],
    ],
],

// New
'forward' => [
    ReportRequestedEvent::class => [
        'uri'    => '/findeks/report-requested',
        'output' => ['phones', 'maskedPhone', 'queryId'],
    ],
],
```

Same resolution chain applies: forwarded endpoint `output` overrides child state's output.

---

## `toResponseArray()` Migration

`toResponseArray()` remains as the fallback, but machines that override it with complex logic (DB queries, external lookups) should migrate to state-level output behaviors.

### Problem: DB queries in toResponseArray()

Some ContextManager subclasses perform DB queries inside `toResponseArray()` — loading related models, computing derived values. This runs on EVERY response regardless of state — wasteful when the current state only needs 2 fields from context.

### Solution: State-level OutputBehavior

Each state's output behavior only computes what it needs. States that need pricing data don't query customer tables. States that need customer data don't compute installment options. Each state pays only for what it returns.

### `computedContext()` vs OutputBehavior

`computedContext()` was designed for derived values but is unused in practice — `toResponseArray()` overrides absorbed its role. With state-level output, neither is needed as the primary mechanism:

| Mechanism | When to use |
|-----------|------------|
| `output => ['key1', 'key2']` | Simple field selection from context |
| `output => OutputClass::class` | Computed values, DB lookups, formatting |
| `toResponseArray()` | Fallback only — avoid overriding in new code |
| `computedContext()` | Deprecated in favor of OutputBehavior |

---

## Summary

### What's Removed

| Keyword/Concept | Replaced By |
|----------------|------------|
| `contextKeys` (endpoint) | `output => ['key1', 'key2']` on endpoint |
| `contextKeys` (state) | `output => ['key1', 'key2']` on state |
| `result` (state) | `output` (same position, different name) |
| `result` (endpoint) | `output` (same position, different name) |
| `output` (child→parent array filter) | Unified — child's `output` behavior serves parent too |
| `ResultBehavior` | `OutputBehavior` |
| `$machine->result()` | `$machine->output()` |
| Dual response shapes | Single envelope: `{machineId, state, availableEvents, output}` |

### What's Added

| Feature | Description |
|---------|------------|
| State-level `output` | Any state can define what it exposes |
| Automatic endpoint resolution | Endpoints inherit current state's output |
| Consistent envelope | `availableEvents` never lost |
| Computed property support | Works in all output formats |

### What's Unchanged

| Feature | Status |
|---------|--------|
| `toResponseArray()` | Fallback when no output defined |
| `computedContext()` | Still works inside toResponseArray() fallback. Deprecated for new code — use OutputBehavior instead |
| Parameter injection | Same DI system |
| Constructor DI | Same service container injection |
| `$machine->availableEvents()` | Unchanged |

---

## Migration Plan

### Framework (event-machine package)

| Step | Change | Files |
|------|--------|-------|
| 1 | Add `OutputBehavior` base class (extends/aliases `ResultBehavior`) | `src/Behavior/OutputBehavior.php` |
| 2 | Add `output` property to `StateDefinition` (all states, not just final) | `src/Definition/StateDefinition.php` |
| 3 | Add definition-time validation: `output` on transient (`@always`) and parallel region states throws `InvalidOutputDefinitionException` | `src/Definition/StateDefinition.php`, `src/Exceptions/` |
| 4 | Add `$machine->output()` method — resolves state output, falls back to `toResponseArray()` | `src/Actor/Machine.php` |
| 5 | Update `MachineController::buildResponse()` — always return envelope `{id, machineId, state, availableEvents, output}` | `src/Routing/MachineController.php` |
| 6 | Update endpoint resolution chain: endpoint output → state output → `toResponseArray()` | `src/Routing/MachineController.php` |
| 7 | Update `EndpointDefinition` — accept `output` key (replace `result` and `contextKeys`) | `src/Routing/EndpointDefinition.php` |
| 8 | Update `ForwardedEndpointDefinition` — accept `output` key (replace `contextKeys`) | `src/Routing/ForwardedEndpointDefinition.php` |
| 9 | Update `ChildMachineDoneEvent` — populate `payload['output']` from child's output behavior | `src/Definition/MachineDefinition.php` |
| 10 | Update behavior registry — `behavior.results` → `behavior.outputs` | `src/Definition/MachineDefinition.php` |
| 11 | Update XState export — export `output` key per state | `src/Commands/ExportXStateCommand.php` |
| 12 | Update `machine:validate-config` — validate output definitions | `src/Commands/ValidateConfigCommand.php` |
| 13 | Rename `ResultBehavior` → `OutputBehavior` (keep `ResultBehavior` as deprecated alias) | `src/Behavior/` |
| 14 | Deprecate `$machine->result()` — alias to `$machine->output()` | `src/Actor/Machine.php` |
| 15 | Update `TestMachine` — add output assertions | `src/Testing/TestMachine.php` |
| 16 | Refactor `ChildMachineCompletionJob` — remove `$result` constructor param, use only `$outputData` | `src/Jobs/ChildMachineCompletionJob.php` |
| 17 | Refactor `ChildMachineJob` — stop passing `$machine->result()` to completion job, pass only `outputData` | `src/Jobs/ChildMachineJob.php` |
| 18 | Refactor `routeChildDone` / `routeChildDoneEvent` — remove `'result'` field from ChildMachineDoneEvent payload, keep only `'output'` | `src/Definition/MachineDefinition.php` |
| 19 | Deprecate `ChildMachineDoneEvent::result()` — alias to `output()` | `src/Behavior/ChildMachineDoneEvent.php` |
| 20 | Update `TestMachine::simulateChildDone` — rename `$result` param to `$output` | `src/Testing/TestMachine.php` |
| 21 | Update `ChildJobJob` — rename internal `$result` variable to `$output` for clarity | `src/Jobs/ChildJobJob.php` |
| 22 | Update `StateConfigValidator` — validate `output` on forward endpoints, fire-and-forget validation unchanged | `src/StateConfigValidator.php` |
| 23 | Update `MachineController` — child completion dispatch uses unified output | `src/Routing/MachineController.php` |
| 24 | Update `Machine::fake()` — accept `output:` key instead of `result:` | `src/Actor/Machine.php` |

### Documentation (docs/)

#### High impact (50+ changes per file)

| Step | File | Changes | Detail |
|------|------|---------|--------|
| 1 | `docs/laravel-integration/endpoints.md` | ~50 | `'result' =>` → `'output'`, `contextKeys` → `output => [...]`, `ResultBehavior` → `OutputBehavior`, `{Name}Result` → `{Name}Output`, response examples, 3 endpoint config tables |
| 2 | `docs/behaviors/results.md` | ~40 | **Rename file** → `docs/behaviors/outputs.md`. Rewrite all `result` → `output`, `ResultBehavior` → `OutputBehavior`, `$machine->result()` → `$machine->output()`, all class names, behavior array `'results'` → `'outputs'` |

#### Medium impact (10-20 changes per file)

| Step | File | Changes | Detail |
|------|------|---------|--------|
| 3 | `docs/advanced/delegation-data-flow.md` | ~15 | `ChildMachineDoneEvent::result()` deprecation, `ResultBehavior` → `OutputBehavior`, `contextKeys` in forward config → `output`, `PaymentStepResult` → `PaymentStepOutput` |
| 4 | `docs/getting-started/upgrading.md` | ~15 | Add v8→v9 upgrade section (keyword renames, class renames, response shape, new state-level output), update existing `result` references in older upgrade sections |
| 5 | `docs/advanced/machine-delegation.md` | ~10 | `ChildMachineDoneEvent` accessor docs, `result()` deprecation note, `Machine::fake(result:)` test helper |
| 6 | `docs/testing/test-machine.md` | ~10 | `assertResult()` → `assertOutput()`, `fakingChild(result:)` → `fakingChild(output:)` |

#### Low impact (< 10 changes per file)

| Step | File | Changes | Detail |
|------|------|---------|--------|
| 7 | `docs/building/configuration.md` | ~5 | Behavior array `'results' =>` → `'outputs' =>`; syntax shorthands section |
| 8 | `docs/building/conventions.md` | ~5 | `{Name}Result` → `{Name}Output`, inline key `'invoiceSummaryResult'` → `'invoiceSummaryOutput'`, behavior table |
| 9 | `docs/building/defining-states.md` | ~3 | Add `output` to state definition reference table, show output on non-final states |
| 10 | `docs/behaviors/introduction.md` | ~3 | Behavior type table: "Results" → "Outputs", `'results' =>` → `'outputs' =>` |
| 11 | `docs/advanced/dependency-injection.md` | ~3 | `ResultBehavior` → `OutputBehavior`, class rename example |
| 12 | `docs/advanced/custom-context.md` | ~3 | `ResultBehavior` → `OutputBehavior`, `contextKeys` → `output` |
| 13 | `docs/advanced/job-actors.md` | ~3 | `ChildMachineDoneEvent` accessor table, `result()` note |
| 14 | `docs/laravel-integration/available-events.md` | ~2 | `ResultBehavior` → `OutputBehavior` references |
| 15 | `docs/testing/recipes.md` | ~5 | Result testing patterns → output testing |
| 16 | `docs/testing/delegation-testing.md` | ~5 | Child output testing patterns |
| 17 | `docs/advanced/async-delegation.md` | ~5 | Child completion output flow |
| 18 | `docs/advanced/delegation-patterns.md` | ~3 | Output in delegation patterns |

#### Project root files

| Step | File | Changes | Detail |
|------|------|---------|--------|
| 19 | `CLAUDE.md` | ~5 | `result()` → `output()`, `ResultBehavior` → `OutputBehavior`, behavior list |
| 20 | `CODEBASE_MAP.md` | ~5 | Architecture diagram `RB[ResultBehavior]` → `OB[OutputBehavior]`, sequence diagram, behavior table |

### Upgrading Guide (`docs/getting-started/upgrading.md`)

The v8 → v9 section must cover:

#### Keyword renames

```php
// States: result → output
// Before (v8)
'completed' => ['type' => 'final', 'result' => OrderResult::class],
// After (v9)
'completed' => ['type' => 'final', 'output' => OrderCompletedOutput::class],

// Endpoints: result → output
// Before (v8)
'GET_STATUS' => ['uri' => '/status', 'result' => StatusResult::class],
// After (v9)
'GET_STATUS' => ['uri' => '/status', 'output' => StatusOutput::class],

// Endpoints: contextKeys → output
// Before (v8)
'GET_PRICE' => ['uri' => '/price', 'contextKeys' => ['totalAmount', 'currency']],
// After (v9)
'GET_PRICE' => ['uri' => '/price', 'output' => ['totalAmount', 'currency']],

// Forwarded endpoints: contextKeys → output
// Before (v8)
'forward' => [Event::class => ['uri' => '/x', 'contextKeys' => ['a', 'b']]],
// After (v9)
'forward' => [Event::class => ['uri' => '/x', 'output' => ['a', 'b']]],
```

#### Behavior registry

```php
// Before (v8)
'behavior' => ['results' => ['orderResult' => OrderResult::class]],
// After (v9)
'behavior' => ['outputs' => ['orderOutput' => OrderCompletedOutput::class]],
```

#### Class renames

```php
// Before (v8)
class OrderResult extends ResultBehavior { ... }
// After (v9)
class OrderCompletedOutput extends OutputBehavior { ... }
```

#### Method renames

```php
// Before (v8)
$machine->result();
// After (v9)
$machine->output();
```

#### Response shape change

```json
// Before (v8) — without result
{"data": {"id": "...", "machineId": "...", "state": [...], "context": {...}, "availableEvents": [...]}}
// Before (v8) — with result (metadata lost!)
{"data": {...result only...}}

// After (v9) — always consistent
{"data": {"id": "...", "machineId": "...", "state": [...], "availableEvents": [...], "output": {...}}}
```

**Key change:** `context` key in response replaced by `output`. Frontends consuming `response.data.context` must update to `response.data.output`.

#### New: state-level output

```php
// v9 feature: define output per state (not just final states)
'awaiting_payment' => [
    'output' => ['installmentOptions', 'totalCashPrice'],
    'on'     => [...],
],
```

#### Child machine output

The `output` key on final states already existed in v8 (child→parent data transfer). In v9, the same key now **also** serves API consumers and accepts `OutputBehavior` classes:

```php
// v8 — output only used for child→parent, only array format
'completed' => ['type' => 'final', 'output' => ['paymentId', 'status']],

// v9 — same key, now also serves API + accepts OutputBehavior
'completed' => ['type' => 'final', 'output' => PaymentCompletedOutput::class],
// Array format still works (backward compatible):
'completed' => ['type' => 'final', 'output' => ['paymentId', 'status']],
```

---

## Feature Interactions

Features analyzed for unified output impact:

| Feature | Affected? | Detail |
|---------|-----------|--------|
| **Child delegation (sync)** | Yes | `routeChildDone` builds event with both `result` and `output` — unified to `output` only |
| **Child delegation (async)** | Yes | `ChildMachineJob` passes `$machine->result()` separately — remove, use only `outputData` |
| **ChildMachineCompletionJob** | Yes | Has separate `$result` and `$outputData` constructor params — unify to `$outputData` |
| **ChildMachineDoneEvent** | Yes | Has both `result()` and `output()` accessors — deprecate `result()` |
| **ChildMachineFailEvent** | No | Already uses `output()` only |
| **Job actors (ChildJobJob)** | Cosmetic | Internal `$result` variable rename to `$output` for clarity |
| **ReturnsResult contract** | No | Method name stays `result()` (job-level API, not machine config) |
| **Parallel regions** | No | ParallelRegionJob doesn't touch output — completion flows through normal @done |
| **processParallelOnDone** | No | Handles parallel state's own @done, not individual region outputs |
| **Timers & schedules** | No | No output interaction |
| **Listeners** | No | No output interaction |
| **sendTo / dispatchTo** | No | Fire-and-forget event delivery, no output flow |
| **dispatchToParent** | No | Sends events to parent, doesn't carry output |
| **Machine::fake()** | Yes | `Machine::fake(result: [...])` → `Machine::fake(output: [...])` |
| **simulateChildDone** | Yes | `$result` param → `$output` rename |
| **simulateChildFail** | No | Already uses `$output` param |
| **XState export** | Yes | Needs to export state-level `output` definitions per state |
| **Machine discovery/cache** | No | Indexes timer configs, not output |
| **Archive/restore** | No | Output definitions are stateless metadata on StateDefinition |
| **ForwardContext** | No | Orthogonal — carries child state for injection into parent OutputBehavior |
| **StateConfigValidator** | Yes | Validate `output` key in forward config, fire-and-forget check unchanged |
| **resolveChildOutput()** | No | Already correctly implements unified output resolution |

---

## Test Plan

### Tests Requiring UPDATE (~60 files)

Keyword renames across existing tests — mechanical changes, no logic change:

| Category | Files | Change |
|----------|-------|--------|
| `'result'` on states | ~15 stub machines | `'result' =>` → `'output' =>` |
| `behavior['results']` | ~7 files | `'results' =>` → `'outputs' =>` |
| `->result()` calls | 6 test files | `->result()` → `->output()` |
| `ResultBehavior` extends | 3 stub classes | `extends ResultBehavior` → `extends OutputBehavior` |
| `contextKeys` on endpoints | 5+ test files | `'contextKeys' =>` → `'output' =>` |
| Response `'context'` assertions | 4 test files | `'context'` → `'output'` in response checks |
| `ChildMachineDoneEvent` result key | 4 test files | `['result' => ...]` → `['output' => ...]` |
| Stub Result classes | 3 files | Rename `GreenResult` → `GreenOutput`, etc. |

**Key test files requiring update:**

- `tests/Features/ResultBehaviorTriggeringEventTest.php` — 6 tests, all use `'result'` on states + `->result()` calls
- `tests/Features/ResultBehaviorInjectionTest.php` — 10 tests, all use `behavior['results']` + `'result'` on states
- `tests/Routing/MachineControllerTest.php` — response structure assertions (`'context'` → `'output'`)
- `tests/Routing/EndpointDefinitionTest.php` — `'result'` → `'output'` in endpoint parsing
- `tests/Routing/ForwardedEndpointHttpTest.php` — `contextKeys` → `output`, response structure
- `tests/Routing/EndpointComputedContextTest.php` — `contextKeys` filtering → `output` array
- `tests/Features/ForwardEndpointParsingTest.php` — 8 tests parsing `contextKeys` in forward config
- `tests/Features/AsyncMachineDelegationTest.php` — child completion with result payload
- `tests/Definition/StateDefinitionTest.php` — `'result'` on states + `->result()` call

### Tests Requiring REMOVAL

None — all existing tests cover valid concepts that survive the rename. No test becomes obsolete.

### NEW Tests to Write

#### 1. State-Level Output (Non-Final States)

```
- output on non-final atomic state works (returns filtered/computed output)
- output on compound state works
- output on non-final state accessible via $machine->output()
- state without output returns toResponseArray() fallback
```

#### 2. Output Resolution Chain

```
- endpoint output overrides state output
- state output used when endpoint has no output
- toResponseArray() used when neither has output
- endpoint output => [] returns empty, even if state has output
- state output => [] returns empty, even if toResponseArray() has data
```

#### 3. InvalidOutputDefinitionException

```
- output on @always (transient) state throws at definition time
- output on parallel region state throws at definition time
- output on parallel region's child state throws at definition time
- output on parallel state itself is allowed
- machine:validate-config catches output on transient state
- machine:validate-config catches output on parallel region
- exception message includes state route for debugging
```

#### 4. Response Envelope

```
- every endpoint response has {id, machineId, state, availableEvents, output}
- create endpoint includes envelope
- stateless endpoint includes envelope
- machineId-bound endpoint includes envelope
- model-bound endpoint includes envelope
- forwarded endpoint includes envelope
- output with OutputBehavior class returns computed data in envelope
- output with array filter returns filtered context in envelope
- output with closure returns closure result in envelope
- availableEvents is always present (never lost like v8 with result)
```

#### 5. $machine->output()

```
- returns state output on non-final state
- returns state output on final state
- returns toResponseArray() when no output defined
- returns empty array when output => []
- never returns null
- works after persist + restore
```

#### 6. Child Machine Output Integration

```
- child final state output populates ChildMachineDoneEvent payload['output']
- parent @done action receives child's OutputBehavior result
- child with output => ['key1'] filters context for parent
- child with output => OutputClass::class runs behavior for parent
- child without output sends full context to parent (fallback)
```

#### 7. Parallel State Output

```
- parallel state with output returns that output
- parallel state without output returns toResponseArray()
- parallel region with output throws InvalidOutputDefinitionException
- parallel region child with output throws InvalidOutputDefinitionException
```

#### 8. Forwarded Endpoint Output

```
- forwarded endpoint with output => ['k1'] filters child context
- forwarded endpoint with output => OutputClass runs behavior
- forwarded endpoint without output returns full child context
- ForwardContext available in forwarded output behavior
```

#### 9. OutputBehavior

```
- OutputBehavior receives ContextManager via injection
- OutputBehavior receives EventBehavior (triggering event)
- OutputBehavior receives State
- OutputBehavior receives EventCollection
- OutputBehavior receives ForwardContext (forwarded endpoints)
- OutputBehavior with constructor DI resolves from container
- OutputBehavior return type can be array, object, scalar
```

#### 10. Computed Properties in Output

```
- output => ['computedKey'] includes computed value from computedContext()
- OutputBehavior can access computed methods on typed ContextManager
- toResponseArray() fallback includes computedContext() values
```

#### 11. Async Delegation Output Flow

```
- ChildMachineJob passes outputData (not result) to ChildMachineCompletionJob
- ChildMachineCompletionJob populates ChildMachineDoneEvent with output only (no result field)
- ChildMachineDoneEvent::output() returns child's resolved output
- ChildMachineDoneEvent::result() deprecated alias returns same as output()
- ChildMachineFailEvent::output() returns child context at failure time
- deep delegation chain (Parent→Child→Grandchild) propagates output correctly
- job actor (ChildJobJob) passes ReturnsResult::result() as outputData
```

#### 12. Fire-and-Forget

```
- fire-and-forget delegation ignores child output (no @done handler)
- output on fire-and-forget child's final state is valid but unused
- StateConfigValidator still forbids output on fire-and-forget parent state (correct)
```

#### 13. TestMachine Faking

```
- simulateChildDone accepts $output param (renamed from $result)
- simulateChildDone populates ChildMachineDoneEvent with output field
- simulateChildFail passes $output correctly (already aligned)
- Machine::fake result key maps to output
```

#### 14. Hierarchical State Output Resolution

```
- child atomic state output overrides parent compound state output
- parent compound output used when child has no output
- no output on either → toResponseArray() fallback
- three levels deep: grandchild > child > parent resolution
```

#### 15. Edge Cases

```
- output defined on every state of a machine (full coverage)
- output behavior that returns null
- output behavior that throws exception
- output behavior with no __invoke parameters (returns static data)
- deeply nested compound state with output
- state with both output and machine delegation (output applies before delegation)
- hierarchical state: child state output overrides parent compound state output
- @done.{finalState} routing — each final state's output is available in its own @done action
- concurrent ChildMachineCompletionJobs for parallel child machines — each carries own output
```
