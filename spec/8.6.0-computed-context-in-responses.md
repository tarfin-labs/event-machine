# Expose Computed Context Methods in Endpoint Responses

**Status:** Draft
**Author:** Claude
**Date:** 2026-03-23
**Scope:** Allow ContextManager subclasses to define computed properties that are included in API responses but NOT persisted to the database
**Reported by:** Turan (CarSalesMachine — `isResumable` not appearing in endpoint response)

---

## Problem

`ContextManager` extends `Spatie\LaravelData\Data`. When context is serialized via `toArray()`, only **public properties** are included. Methods like `isResumable()` or `isCountEven()` on context subclasses are invisible in API responses.

### Current Behavior

```json
{
  "context": {
    "resumableStates": ["car_sales.data_collection.customer_info_completed", "car_sales.allocation.*"]
  }
}
```

### Expected Behavior

```json
{
  "context": {
    "resumableStates": ["..."],
    "is_resumable": false
  }
}
```

---

## Design Constraint: toArray() Has Two Consumers

`context->toArray()` is called for both API responses and DB persistence. Computed values should only appear in API responses.

| Call Site | Purpose | Change to `toResponseArray()`? |
|-----------|---------|-------------------------------|
| `MachineController::buildResponse()` | API response | **Yes** |
| `MachineController::buildForwardedResponse()` | API response (forwarded) | **Yes** |
| `State::toArray()` / `jsonSerialize()` | User-facing state serialization | **Yes** |
| `State::recordMachineEvent()` | DB persistence | No |
| `ParallelRegionJob` | Internal snapshot | No |

---

## Solution

### 1. Add to `ContextManager`

**`computedContext()`** — override point for subclasses:

```php
protected function computedContext(): array
{
    return [];
}
```

**`toResponseArray()`** — merges properties + computed for API responses:

```php
public function toResponseArray(): array
{
    return array_merge($this->toArray(), $this->computedContext());
}
```

---

## Edge Cases

### 1. Computed key collides with a real property

`array_merge()` overwrites the property with the computed value. No collision detection — user's responsibility, predictable behavior.

### 2. Computed method throws

Exception propagates to controller → 500 error. Consistent with actions/guards/calculators.

---

## Test Plan

### Test Stub: `tests/Stubs/Contexts/ComputedTestContext.php`

Dedicated stub — do NOT modify `TrafficLightsContext` to avoid breaking existing test assertions.

```php
class ComputedTestContext extends ContextManager
{
    public function __construct(
        public int $count = 0,
        public string $status = 'active',
    ) {
        parent::__construct();
    }

    protected function computedContext(): array
    {
        return [
            'is_count_even' => $this->count % 2 === 0,
            'display_label' => "Item #{$this->count} ({$this->status})",
        ];
    }
}
```

### Test Cases: `tests/ContextComputedTest.php`

| # | Test Name | What It Verifies |
|---|-----------|-----------------|
| 1 | `toArray does not include computed values` | `toArray()` returns only `count` and `status` |
| 2 | `toResponseArray includes computed values` | `toResponseArray()` returns properties + `is_count_even` + `display_label` |
| 3 | `computed values reflect current state` | Change count 0→3, verify `is_count_even` flips `true`→`false` |
| 4 | `base ContextManager has no computed values` | `new ContextManager(data: ['a' => 1])` — `toResponseArray()` === `toArray()` |

### Test Cases: `tests/Routing/EndpointComputedContextTest.php`

Requires a minimal machine stub using `ComputedTestContext`.

| # | Test Name | What It Verifies |
|---|-----------|-----------------|
| 5 | `endpoint response includes computed context values` | POST to endpoint → response JSON has computed keys |
| 6 | `contextKeys filtering includes computed values` | `contextKeys: ['count', 'is_count_even']` → only those appear |
| 7 | `contextKeys filtering excludes computed values` | `contextKeys: ['count']` → computed key absent |
| 8 | `State toArray includes computed context` | `$machine->state->toArray()['context']` has computed keys |
| 9 | `DB persisted context excludes computed values` | `machine_events.context` column lacks computed keys |

---

## Implementation Order

1. Add `computedContext()` and `toResponseArray()` to `ContextManager`
2. Update 3 call sites in `MachineController` and `State`
3. Create `ComputedTestContext` stub
4. Write unit tests (#1–4)
5. Create minimal machine stub for endpoint tests
6. Write integration tests (#5–9)
7. Update documentation (3 files)
8. Run full test suite
9. `composer pint && composer rector`

---

## Files Changed

| File | Change |
|------|--------|
| `src/ContextManager.php` | Add `computedContext()` and `toResponseArray()` |
| `src/Routing/MachineController.php` | `toResponseArray()` in 2 places |
| `src/Actor/State.php` | `toResponseArray()` in `toArray()` |
| `tests/Stubs/Contexts/ComputedTestContext.php` | New test stub |
| `tests/ContextComputedTest.php` | New — unit tests |
| `tests/Routing/EndpointComputedContextTest.php` | New — integration tests |
| `docs/advanced/custom-context.md` | New subsection: "Exposing Computed Values in API Responses" |
| `docs/building/working-with-context.md` | Add `computedContext()` and `toResponseArray()` to methods table |
| `docs/laravel-integration/endpoints.md` | Note about computed values in default response |

---

## Documentation Updates

### 1. `docs/advanced/custom-context.md` — Main change

The existing "Computed Methods" section (line 121) explains how to define methods and use them in guards/actions. Add a new subsection **after** "Using Computed Methods" (after line 197) about exposing computed values in API responses:

**New subsection: "Exposing Computed Values in API Responses"**

Content to cover:
- By default, computed methods are only available in PHP (guards, actions, calculators, ResultBehavior). They do NOT appear in endpoint JSON responses.
- Override `computedContext()` to declare which computed values should be included in API responses.
- Code example showing `computedContext()` override on the existing `CartContext` example (reusing `subtotal()`, `total()`, `isEmpty()` etc. that are already defined above).
- Note: computed values are NOT persisted to the database — they are recomputed on every response.
- Note: computed keys respect `contextKeys` filtering on endpoints — they can be included or excluded just like regular context keys.

### 2. `docs/building/working-with-context.md` — Context Methods table

Add two rows to the "Context Methods" table (line 100):

| Method | Description |
|--------|-------------|
| `computedContext()` | Override in subclasses to define computed key-value pairs for API responses |
| `toResponseArray()` | Returns `toArray()` merged with `computedContext()` — used by endpoints and `State::toArray()` |

### 3. `docs/laravel-integration/endpoints.md` — Default response note

In the "Default Response Structure" section (around line 220), add a brief note that computed context values (from `computedContext()`) are automatically included in the default response. Link to `docs/advanced/custom-context.md` for details.

---

## Out of Scope

- Computed property caching, lazy evaluation, validation
- Modifying existing `TrafficLightsContext`
- Artisan xstate export (doesn't include context values)
