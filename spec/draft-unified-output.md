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
| `result` (final state) | Final state | Machine::result() output |
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
| `Machine::result()` | `Machine::output()` |
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

States with `@always` transitions are never observed by external consumers — the machine passes through them immediately. Defining output on transient states is unnecessary but not an error (it will never be read).

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

## `Machine::output()`

Replaces `Machine::result()`. Returns only the **output content** (not the envelope — that's an HTTP concern).

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

`output => []` returns empty array (not null). Undefined output returns toResponseArray(). `Machine::output()` never returns null.

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
| `Machine::result()` | `Machine::output()` |
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
| `Machine::availableEvents()` | Unchanged |
