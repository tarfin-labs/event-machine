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
1. Endpoint has output?   → use it
2. State has output?      → use it
3. Neither                → toResponseArray()
```

---

## Response Envelope

Always the same structure — no more two different shapes:

```json
{
    "data": {
        "machineId": "01ABC...",
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

`machineId`, `state`, `availableEvents` always present. `output` content varies by state.

---

## Child Machine Integration

Final state `output` serves both API consumers AND parent machines. The child defines ONE output; the parent picks what it needs:

```php
// Child machine
'completed' => [
    'type'   => 'final',
    'output' => PaymentCompletedOutput::class,
    // Returns: {paymentId, transactionRef, total, receiptUrl}
],

// Parent machine — receives child's output in @done action
'@done' => [
    'target'  => 'shipped',
    'actions' => function (ContextManager $ctx, array $childOutput): void {
        $ctx->set('paymentId', $childOutput['paymentId']);
        // Only takes what it needs from child's output
    },
],
```

With typed contracts:

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
| Not defined (fallback) | Included via toResponseArray() |
| `['subtotal', 'total']` | Included — filters toResponseArray() which has computed values |
| `OutputClass::class` | Available via `$ctx->computedContext()` or direct calculation |
| `fn ($ctx) => [...]` | Available via `$ctx->computedContext()` or direct calculation |

---

## `Machine::output()`

Replaces `Machine::result()`. Works on **any state** with output defined:

```php
$machine = CarSalesMachine::create(state: $rootEventId);
$currentOutput = $machine->output();  // state-aware output

// For broadcast
CarSalesApplicationBroadcastEvent::dispatch(
    $machine->state->context->machineId(),
    $machine->state->value,
    $machine->output(),           // state-aware
    $machine->availableEvents(),  // always available
);
```

Resolution: state has output → run it. No output → `toResponseArray()`.

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
