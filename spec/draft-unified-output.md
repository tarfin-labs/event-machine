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

`output => []` returns empty array (not null). Undefined output returns toResponseArray(). `$machine->output()` never returns null.

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
| `computedContext()` | Still works inside toResponseArray() |
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

### Documentation (docs/)

| Step | File | Change |
|------|------|--------|
| 1 | `docs/behaviors/results.md` | Rewrite → `docs/behaviors/outputs.md` (rename file) |
| 2 | `docs/building/defining-states.md` | Add `output` to state definition reference |
| 3 | `docs/building/configuration.md` | Add `output` to syntax shorthands, update behavior array (`results` → `outputs`) |
| 4 | `docs/building/conventions.md` | Update naming: `{Name}Result` → `{Name}Output`, inline key convention |
| 5 | `docs/laravel-integration/endpoints.md` | Replace `result`/`contextKeys` examples with `output` |
| 6 | `docs/advanced/machine-delegation.md` | Replace `output` (child→parent) explanation with unified output |
| 7 | `docs/advanced/async-delegation.md` | Same |
| 8 | `docs/advanced/delegation-patterns.md` | Same |
| 9 | `docs/testing/test-machine.md` | Update `result()` → `output()` examples |
| 10 | `docs/testing/recipes.md` | Update result testing patterns |
| 11 | `docs/testing/delegation-testing.md` | Update child output testing |
| 12 | `docs/getting-started/upgrading.md` | **v9 upgrade guide** (see below) |

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

```php
// Before (v8) — separate output key for child→parent
'completed' => ['type' => 'final', 'output' => ['paymentId', 'status']],

// After (v9) — same key, but now accepts OutputBehavior too
'completed' => ['type' => 'final', 'output' => PaymentCompletedOutput::class],
// Array format still works for simple cases:
'completed' => ['type' => 'final', 'output' => ['paymentId', 'status']],
```
