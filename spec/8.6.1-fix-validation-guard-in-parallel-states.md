# Fix: ValidationGuardBehavior in Parallel States

**Status:** Implemented — Released in 8.6.1
**Date:** 2026-03-25
**Severity:** Bug — validation errors silently swallowed, exception thrown instead of 422 response

---

## Problem

When a `ValidationGuardBehavior` fails inside a parallel state region, the framework throws `NoTransitionDefinitionFoundException` instead of returning a 422 validation error with the guard's `$this->errorMessage`.

**Non-parallel (working):**
```
Guard fails → null → TRANSITION_FAIL recorded → state stays
→ Machine::send() returns → handleValidationGuards() scans history
→ throws MachineValidationException → MachineController returns 422
```

**Parallel (broken):**
```
Guard fails → null → selectTransitions() returns []
→ transitionParallelState() sees [] → throws NoTransitionDefinitionFoundException
✗ handleValidationGuards() NEVER REACHED
```

## Root Cause

`selectTransitions()` returns `TransitionBranch[]`. An empty array means BOTH:
1. "No transition handler defined for this event" — correct to throw
2. "Transition handler exists but guard(s) failed" — should NOT throw

---

## Fix

### 1. New DTO: `TransitionSelectionResult`

```php
// src/Definition/TransitionSelectionResult.php
final class TransitionSelectionResult
{
    public function __construct(
        public readonly array $branches,
        public readonly bool $hadValidationGuardFailure,
    ) {}
}
```

### 2. Updated `selectTransitions()`

```php
protected function selectTransitions(EventBehavior $eventBehavior, State $state): TransitionSelectionResult
{
    $transitions                = [];
    $seen                       = [];
    $hadValidationGuardFailure = false;

    foreach ($this->getActiveAtomicStates($state) as $atomicState) {
        $transitionDef = $this->findTransitionDefinitionOrNull($atomicState, $eventBehavior);

        if ($transitionDef instanceof TransitionDefinition) {
            $defId = spl_object_id($transitionDef);
            if (isset($seen[$defId])) {
                continue;
            }
            $seen[$defId] = true;

            $branch = $transitionDef->getFirstValidTransitionBranch($eventBehavior, $state);

            if ($branch instanceof TransitionBranch) {
                $transitions[] = $branch;
            } elseif ($this->transitionHasValidationGuard($transitionDef)) {
                $hadValidationGuardFailure = true;
            }
        }
    }

    return new TransitionSelectionResult($transitions, $hadValidationGuardFailure);
}
```

### 3. New helper: `transitionHasValidationGuard()`

Checks if any branch of a `TransitionDefinition` has a `ValidationGuardBehavior` guard.

Guard definitions come in two forms (same as `getInvokableBehavior` resolution):
- **FQCN:** `IsVinValidValidationGuard::class` → `is_subclass_of` directly
- **String key:** `'isVinValid'` → look up in `$this->behavior['guards']`, then `is_subclass_of`

Both need to be handled:

```php
private function transitionHasValidationGuard(TransitionDefinition $transitionDef): bool
{
    foreach ($transitionDef->branches as $branch) {
        foreach ($branch->guards ?? [] as $guardDefinition) {
            $baseName = explode(':', (string) $guardDefinition, 2)[0];

            // FQCN: class directly extends ValidationGuardBehavior
            if (is_subclass_of($baseName, ValidationGuardBehavior::class)) {
                return true;
            }

            // String key: resolve from behavior registry
            $guardClass = $this->behavior[BehaviorType::Guard->value][$baseName] ?? null;
            if (is_string($guardClass) && is_subclass_of($guardClass, ValidationGuardBehavior::class)) {
                return true;
            }
        }
    }

    return false;
}
```

### 4. Updated `transitionParallelState()`

Validation check goes **above** the empty-transitions check — a validation failure in ANY region blocks ALL regions. Excluded for `@always` (automatic transitions with no HTTP caller).

```php
protected function transitionParallelState(
    State $state,
    EventBehavior $eventBehavior,
    int $recursionDepth = 0
): State {
    $result      = $this->selectTransitions($eventBehavior, $state);
    $transitions = $result->branches;

    // Validation guard failure blocks entire parallel transition.
    // Excluded for @always — automatic transitions have no HTTP caller.
    // Machine::handleValidationGuards() will throw MachineValidationException.
    if ($result->hadValidationGuardFailure
        && $eventBehavior->type !== TransitionProperty::Always->value
    ) {
        $state->setInternalEventBehavior(
            type: InternalEvent::TRANSITION_FAIL,
            placeholder: "{$state->currentStateDefinition->route}.{$eventBehavior->type}",
        );

        return $state;
    }

    if ($transitions === []) {
        // ... existing logic unchanged: @always silent return, child forwarding, throw ...
    }

    // ... rest of method unchanged ...
}
```

### 5. No other changes needed

| File | Reason it already works |
|------|------------------------|
| `TransitionDefinition::getFirstValidTransitionBranch()` | Already records GUARD_FAIL with errorMessage in payload |
| `Machine::handleValidationGuards()` | Already scans history for ValidationGuardBehavior failures, throws MachineValidationException |
| `MachineController` | Already catches MachineValidationException → 422 |

---

## Edge Cases

### E1: Regular guard fails first on a branch that also has a ValidationGuard

```php
'guards' => [HasPermissionGuard::class, IsVinValidValidationGuard::class]
```

`getFirstValidTransitionBranch()` breaks at first failure (line 269). Regular guard fails → ValidationGuard never runs → no GUARD_FAIL event with errorMessage. But `transitionHasValidationGuard()` returns true → flag set → `transitionParallelState()` returns state.

`handleValidationGuards()` scans history — finds no ValidationGuardBehavior-specific GUARD_FAIL → does NOT throw. Machine stays in current state silently. This matches non-parallel behavior (guard failure = stay in state).

### E2: Multi-branch with ValidationGuard on branch 1, unguarded branch 2

```php
'SUBMIT' => [
    ['target' => 'a', 'guards' => IsVinValidValidationGuard::class],
    ['target' => 'b'],  // fallback, no guards
]
```

Branch 1 validation fails → continues → branch 2 has no guards → selected. Transition executes via branch 2. Then `handleValidationGuards()` throws because branch 1's GUARD_FAIL is in history.

This is a pre-existing issue in non-parallel too — out of scope for this fix.

### E3: ValidationGuard failure + other region has no handler

Region A: handler found, validation guard fails → flag set. Region B: no handler → skipped. Result: branches=[], flag=true → returns state → MachineValidationException. Correct.

### E4: ValidationGuard failure + other region would transition

Region A: validation guard fails → flag set. Region B: no guard → valid branch added. Result: branches=[B], flag=true. Because validation check is above empty check, ALL regions blocked. Correct — validation rejection is atomic.

### E5: @always with ValidationGuardBehavior

Excluded by `$eventBehavior->type !== TransitionProperty::Always->value` check. @always follows existing behavior (silent return). No HTTP caller to receive validation errors.

### E6: Dispatch mode

Out of scope — async regions can't return sync HTTP responses. Validation guard failure in dispatched region: region doesn't transition, GUARD_FAIL recorded in history. Document as limitation.

### E7: Stale history

`handleValidationGuards()` filters by `$lastPreviousEventNumber`. Only current `send()` events scanned. No cross-event contamination.

---

## TDD Plan

### Stubs

**Machine:** `tests/Stubs/Machines/Parallel/ValidationGuardParallelMachine.php`

```php
'collecting' => [
    'type' => 'parallel',
    '@done' => 'completed',
    'states' => [
        'data_entry' => [
            'initial' => 'awaiting_input',
            'states' => [
                'awaiting_input' => [
                    'on' => [
                        'SUBMIT_DATA' => [
                            'target' => 'data_received',
                            'guards' => IsValuePositiveValidationGuard::class,
                        ],
                        'SUBMIT_ALWAYS_FAIL' => [
                            'target' => 'data_received',
                            'guards' => AlwaysFailParallelValidationGuard::class,
                        ],
                        'SUBMIT_REGULAR_GUARD_FAIL' => [
                            'target' => 'data_received',
                            'guards' => AlwaysFailRegularGuard::class,
                        ],
                    ],
                ],
                'data_received' => ['type' => 'final'],
            ],
        ],
        'review' => [
            'initial' => 'pending_review',
            'states' => [
                'pending_review' => [
                    'on' => [
                        'SUBMIT_DATA' => ['target' => 'reviewed'],
                        'SUBMIT_ALWAYS_FAIL' => ['target' => 'reviewed'],
                    ],
                ],
                'reviewed' => ['type' => 'final'],
            ],
        ],
    ],
],
```

Event → scenario mapping:
| Event | data_entry | review | Tests |
|-------|-----------|--------|-------|
| `SUBMIT_ALWAYS_FAIL` | ValidationGuard, always fails | No guard, would transition | 1, 2, 3 |
| `SUBMIT_DATA` (value > 0) | ValidationGuard, passes | No guard | 4 |
| `SUBMIT_DATA` (value ≤ 0) | ValidationGuard, fails | No guard, would transition | 5 |
| `SUBMIT_REGULAR_GUARD_FAIL` | Regular guard, always fails | No handler | 6 |
| `UNKNOWN_EVENT` | No handler | No handler | 7 |

**Guards:**
- `AlwaysFailParallelValidationGuard` — `ValidationGuardBehavior`, returns false, errorMessage = "Validation always fails in parallel."
- `IsValuePositiveValidationGuard` — `ValidationGuardBehavior`, returns false if payload value ≤ 0, errorMessage = "Value must be positive."
- `AlwaysFailRegularGuard` — `GuardBehavior`, returns false (control group)

### Tests

File: `tests/Features/ParallelStates/ParallelValidationGuardTest.php`

```
Test 1: validation guard failure in parallel throws MachineValidationException with correct error
  Send SUBMIT_ALWAYS_FAIL
  Assert: throws MachineValidationException (not NoTransitionDefinitionFoundException)
  Assert: exception errors contain "Validation always fails in parallel."
  Assert: history contains GUARD_FAIL event with errorMessage in payload
  Assert: history contains TRANSITION_FAIL event

Test 2: validation guard failure blocks ALL regions — no region transitions (E4)
  Send SUBMIT_ALWAYS_FAIL (review has no guard → would normally transition)
  Catch MachineValidationException
  Assert: both regions unchanged (data_entry.awaiting_input + review.pending_review)

Test 3: validation guard failure with TestMachine fluent API
  Use ValidationGuardParallelMachine::test()
  Send SUBMIT_ALWAYS_FAIL
  Assert: MachineValidationException thrown with correct error

Test 4: validation guard passes → both regions transition normally
  Send SUBMIT_DATA with payload ['value' => 10]
  Assert: data_entry → data_received, review → reviewed

Test 5: conditional validation guard fails → MachineValidationException
  Send SUBMIT_DATA with payload ['value' => -5]
  Assert: throws MachineValidationException with "Value must be positive."
  Assert: both regions unchanged

Test 6: regular guard failure still throws NoTransitionDefinitionFoundException
  Send SUBMIT_REGULAR_GUARD_FAIL (only data_entry handles it, regular guard fails)
  Assert: throws NoTransitionDefinitionFoundException

Test 7: unknown event still throws NoTransitionDefinitionFoundException
  Send UNKNOWN_EVENT (no handler in any region)
  Assert: throws NoTransitionDefinitionFoundException
```

### Commit Plan

```
Commit 1 (RED): test: add failing tests for ValidationGuardBehavior in parallel states
  Create 3 guard stubs, 1 machine stub, 1 test file (7 tests)
  All tests fail

Commit 2 (GREEN): fix: handle ValidationGuardBehavior failure in parallel states
  Create TransitionSelectionResult DTO
  Update selectTransitions(), add transitionHasValidationGuard()
  Update transitionParallelState()
  All tests pass
  Run composer quality — ensure no regressions

Commit 3: docs: document validation guard semantics in parallel states
  docs/behaviors/validation-guards.md — add parallel state section
  docs/advanced/parallel-states/event-handling.md — add validation guard note
  CLAUDE.md — add guard semantics to Parallel States section

Commit 4: test(localqa): add QA test for ValidationGuardBehavior in parallel with real infra
  tests/LocalQA/ParallelValidationGuardQATest.php
  Run against real MySQL + Redis + Horizon
```

---

## Files

### Create

| File | Purpose |
|------|---------|
| `src/Definition/TransitionSelectionResult.php` | DTO |
| `tests/Features/ParallelStates/ParallelValidationGuardTest.php` | 7 unit tests |
| `tests/Stubs/Machines/Parallel/ValidationGuardParallelMachine.php` | Test machine |
| `tests/Stubs/Machines/Parallel/Guards/AlwaysFailParallelValidationGuard.php` | Always-fail validation guard |
| `tests/Stubs/Machines/Parallel/Guards/IsValuePositiveValidationGuard.php` | Conditional validation guard |
| `tests/Stubs/Machines/Parallel/Guards/AlwaysFailRegularGuard.php` | Regular guard (control) |
| `tests/LocalQA/ParallelValidationGuardQATest.php` | QA test with real infra |

### Modify

| File | Change |
|------|--------|
| `src/Definition/MachineDefinition.php` | `selectTransitions()`, `transitionHasValidationGuard()`, `transitionParallelState()` |
| `docs/behaviors/validation-guards.md` | Add "Parallel States" section |
| `docs/advanced/parallel-states/event-handling.md` | Add ValidationGuardBehavior note |
| `CLAUDE.md` | Add guard semantics to Parallel States |

---

## LocalQA Test Plan

### Why QA tests

Unit tests use in-memory SQLite with sync queue. The bug was reported in a real production
machine (CarSalesMachine with parallel Findeks/Turmob delegation). QA tests verify the fix
works with:
- Real MySQL (persisted machine events, locks)
- Real Redis + Horizon (queue processing)
- Full machine lifecycle (create → send → validate → persist)

### QA Test: `tests/LocalQA/ParallelValidationGuardQATest.php`

Uses the same `ValidationGuardParallelMachine` stub (already has parallel regions with
validation guards). Tests the full persistence + HTTP stack:

```
QA Test 1: validation guard failure persists GUARD_FAIL + TRANSITION_FAIL events
  - Create machine (persisted to MySQL)
  - Send SUBMIT_ALWAYS_FAIL
  - Catch MachineValidationException
  - Query machine_events table directly
  - Assert: GUARD_FAIL event persisted with errorMessage in payload
  - Assert: TRANSITION_FAIL event persisted
  - Assert: machine_current_states shows machine still in parallel state

QA Test 2: validation guard failure followed by successful retry
  - Create machine (persisted)
  - Send SUBMIT_DATA with value=-5 → MachineValidationException
  - Send SUBMIT_DATA with value=10 → success
  - Assert: machine transitioned to next state
  - Assert: event history shows both attempts (fail then success)

QA Test 3: validation guard failure does not leave orphan locks
  - Create machine (persisted)
  - Send SUBMIT_ALWAYS_FAIL → MachineValidationException
  - Assert: machine_locks table has no active lock for this machine
  - Send another event → should not deadlock
```

### QA Test Prerequisites

Per `tests/LocalQA/README.md` — requires:
- MySQL running (`qa_event_machine_v7` database)
- Redis running
- Horizon running in `/tmp/qa-v7-review`
- Test stubs in Horizon autoload

---

## Documentation Updates

### 1. `docs/behaviors/validation-guards.md` — New section: "Parallel States"

Add after the existing content:

```markdown
## Parallel States

When a `ValidationGuardBehavior` fails inside a parallel state region, the behavior differs
from regular guards:

| Guard Type | Failure in Parallel | Result |
|-----------|-------------------|--------|
| `GuardBehavior` | Region treated as "didn't handle event" | Event bubbles, may throw `NoTransitionDefinitionFoundException` |
| `ValidationGuardBehavior` | **Entire parallel transition blocked** | Machine stays in current state, `MachineValidationException` thrown |

A validation guard failure in **any** region blocks **all** regions from transitioning. This is
intentional — validation rejection is atomic. The error message propagates as a 422 response
through endpoints.

### Example

```php no_run
'data_collection' => [
    'type' => 'parallel',
    'states' => [
        'vehicle_info' => [
            'initial' => 'awaiting',
            'states' => [
                'awaiting' => [
                    'on' => [
                        VehicleSubmittedEvent::class => [
                            'target' => 'received',
                            'guards' => IsVinValidGuard::class, // extends ValidationGuardBehavior
                        ],
                    ],
                ],
                'received' => ['type' => 'final'],
            ],
        ],
        'documents' => [
            // This region is also blocked if IsVinValidGuard fails
            // ...
        ],
    ],
],
```

### Dispatch Mode Limitation

In dispatch mode (`parallel_dispatch.enabled = true`), each region runs as a separate queue job.
Validation guard failures in dispatched regions:
- Region doesn't transition (guard failure recorded in history)
- Error does NOT propagate to the HTTP response (async execution)

If synchronous validation feedback is needed, validate before entering the parallel state.
```

### 2. `docs/advanced/parallel-states/event-handling.md` — Add note

Add a "Validation Guards" subsection linking to the validation-guards doc:

```markdown
## Validation Guards in Parallel States

When a `ValidationGuardBehavior` fails in any region, the entire parallel transition is blocked
— no region transitions. This differs from regular `GuardBehavior` failure, which only affects
the region where it occurred.

See [Validation Guards → Parallel States](/behaviors/validation-guards#parallel-states) for
details and examples.
```

### 3. `CLAUDE.md` — Parallel States section

Add to the existing Parallel States bullet list:

```markdown
- **Guard semantics in parallel:** Regular `GuardBehavior` failure in a region = "event not
  handled by this region" (bubbles/throws). `ValidationGuardBehavior` failure = "operation
  rejected" — entire parallel transition aborted, machine stays, `MachineValidationException`
  thrown (422 via endpoints). Dispatch mode: validation errors don't propagate (async).
```

---

## Out of Scope

- **E2 (multi-branch fallthrough + validation):** Pre-existing issue in non-parallel — separate fix
- **Dispatch mode propagation:** Async regions can't return sync HTTP responses
