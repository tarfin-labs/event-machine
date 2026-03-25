# Unified camelCase Convention for Business Data Keys

**Status:** In Progress
**Date:** 2026-03-25

---

## Decision

All business data keys — context, payloads, input, output, delegation `with` — use **camelCase**. Framework config keys remain **snake_case**.

This applies to v8 (current). Not a future/v9 change.

## Why

### Problem: Two Styles for the Same Thing

The codebase had two conventions for context keys:

```php
// Array context — snake_case
'context' => ['total_amount' => 0, 'order_id' => null]

// Typed context — camelCase
class OrderContext extends ContextManager {
    public int $totalAmount = 0;
}
```

This created confusion: "When do I use snake_case, when camelCase?" The answer was "depends on the container" — arrays use snake, classes use camel. But this is a leaky abstraction. The data is the same regardless of its container.

### Resolution: One Rule

**Business data = camelCase. Always.**

The distinction is not "array vs class" but "config vs data":

| Kind | Convention | Examples |
|------|-----------|---------|
| Framework config | snake_case | `should_persist`, `initial`, `scenarios_enabled` |
| Business data | camelCase | `totalAmount`, `orderId`, `paymentId` |
| Identifiers | existing convention | states: `snake_case`, events: `SCREAMING_SNAKE`, IDs: `snake_case` |

### Alignment

- **PHP PSR-1**: Properties SHOULD use camelCase
- **XState v5**: Context uses camelCase (`{ orderId: '', totalAmount: 0 }`)
- **SCXML W3C examples**: camelCase (`<data id="eventStamp"/>`)
- **Spatie Laravel Data**: camelCase properties, auto-mapped to snake_case for serialization

No state machine spec dictates a convention — they follow the host language. PHP says camelCase.

---

## What Changed (Already Done)

### 1. Convention Documentation Updated

**`docs/building/conventions.md`**:
- Quick reference table: `Context keys (array) | snake_case` → `Context keys | camelCase`
- Removed "Why Two Different Styles?" info box
- Added "The Core Rule: Config vs Data" section
- Updated all context examples to camelCase
- Updated raise() payload example: `['transaction_id' => $txId]` → `['transactionId' => $txId]`
- Updated "What to Avoid" section with snake_case context key examples
- Summary principle #5: updated to "Business data is camelCase, config is snake_case"

**`CLAUDE.md`**:
- Updated naming convention: `Context array keys: snake_case` → `Business data keys: camelCase`
- Added explicit config key convention: `Config keys: snake_case`

---

## What Remains (TODO)

### 2. Documentation Refactoring — 33 Files

Every docs/ markdown file with snake_case context keys needs updating. The patterns to change:

| Pattern | Before | After |
|---------|--------|-------|
| Context init | `'order_id' => null` | `'orderId' => null` |
| Context get | `$ctx->get('order_id')` | `$ctx->get('orderId')` |
| Context set | `$ctx->set('order_id', $v)` | `$ctx->set('orderId', $v)` |
| Delegation with | `'with' => ['order_id']` | `'with' => ['orderId']` |
| Delegation output | `'output' => ['payment_id']` | `'output' => ['paymentId']` |
| Event payload | `['transaction_id' => $id]` | `['transactionId' => $id]` |
| Test context | `::test(['order_id' => 1])` | `::test(['orderId' => 1])` |
| Endpoint contextKeys | `'contextKeys' => ['card_token']` | `'contextKeys' => ['cardToken']` |

**Files requiring changes** (grouped by priority):

#### Priority 1: Core documentation (read by every new user)
- `docs/index.md`
- `docs/getting-started/your-first-machine.md`
- `docs/understanding/context.md`
- `docs/building/working-with-context.md`
- `docs/building/configuration.md`

#### Priority 2: Behavior documentation
- `docs/behaviors/actions.md`
- `docs/behaviors/guards.md`
- `docs/behaviors/events.md`
- `docs/behaviors/calculators.md`
- `docs/behaviors/results.md`

#### Priority 3: Advanced features
- `docs/advanced/machine-delegation.md`
- `docs/advanced/async-delegation.md`
- `docs/advanced/delegation-patterns.md`
- `docs/advanced/delegation-data-flow.md`
- `docs/advanced/dependency-injection.md`
- `docs/advanced/raised-events.md`
- `docs/advanced/always-transitions.md`
- `docs/advanced/hierarchical-states.md`
- `docs/advanced/scenarios.md`
- `docs/advanced/job-actors.md`
- `docs/advanced/parallel-states/event-handling.md`
- `docs/advanced/parallel-states/parallel-dispatch.md`

#### Priority 4: Best practices
- `docs/best-practices/context-design.md`
- `docs/best-practices/testing-strategy.md`
- `docs/best-practices/machine-decomposition.md`
- `docs/best-practices/machine-system-design.md`
- `docs/best-practices/action-design.md`
- `docs/best-practices/guard-design.md`
- `docs/best-practices/time-based-patterns.md`
- `docs/best-practices/parallel-patterns.md`
- `docs/best-practices/event-design.md`
- `docs/best-practices/transition-design.md`
- `docs/best-practices/state-design.md`

#### Priority 5: Testing documentation
- `docs/testing/test-machine.md`
- `docs/testing/recipes.md`
- `docs/testing/delegation-testing.md`
- `docs/testing/fakeable-behaviors.md`
- `docs/testing/time-based-testing.md`
- `docs/testing/constructor-di.md`
- `docs/testing/transitions-and-paths.md`
- `docs/testing/parallel-testing.md`
- `docs/testing/troubleshooting.md`
- `docs/testing/localqa.md`

#### Priority 6: Laravel integration
- `docs/laravel-integration/endpoints.md`
- `docs/laravel-integration/eloquent-integration.md`
- `docs/getting-started/upgrading.md`

### 3. Doctest Verification

After each file is updated, run:
```bash
vendor/bin/doctest docs/path/to/file.md -v
```

Ensure 0 failures. Code blocks with `no_run` or `ignore` attributes won't execute, but verify they still look correct.

### 4. Test Stubs Review (Optional)

Test stubs in `tests/Stubs/` use snake_case context keys in machine definitions. These are internal test infrastructure — not user-facing documentation. Changing them is optional but would maintain full consistency.

**Decision:** Leave test stubs as-is for now. They work correctly and changing them risks breaking tests without user-facing benefit.

---

## What NOT to Change

- **State names**: `awaiting_payment` stays snake_case (identifier, not data)
- **Machine IDs**: `order_workflow` stays snake_case (identifier)
- **Event types**: `ORDER_SUBMITTED` stays SCREAMING_SNAKE (constant)
- **Config keys**: `should_persist`, `initial`, `scenarios_enabled` stay snake_case (framework config)
- **DB columns**: `machine_events`, `root_event_id` stay snake_case (SQL convention)
- **Route names**: `machines.application.farmer_saved` stays snake_case (Laravel convention)

---

## Process

For each file:
1. Open the file
2. Find all snake_case context/payload/with/output keys
3. Convert to camelCase
4. Do NOT change state names, config keys, event types, or identifiers
5. Run doctest if applicable
6. Commit with: `docs({filename}): convert context keys to camelCase (unified naming convention)`
