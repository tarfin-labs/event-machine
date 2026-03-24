# Terminology Rename: Calculator → Assignment

**Status:** Draft — needs discussion and refinement before implementation.

## Motivation

"Calculator" is a term unique to EventMachine. No other state machine framework, specification, or academic paper uses it. The concept itself (pre-computing/mutating context before guards evaluate) does exist elsewhere, but under different names:

| Source | Term | Type |
|--------|------|------|
| SCXML (W3C) | `<assign>` | Distinct executable content element |
| XState v5 | `assign()` | Special action subtype |
| Robot (JS) | `reduce()` | Separate transition function |
| Python transitions | `prepare` | Callback that runs before conditions |
| Akka FSM | `using` | Fluent modifier on transitions |
| UML, Spring, AASM, Boost, QP, Sismic | *(part of action)* | No separate concept |

The rename aligns EventMachine with the SCXML specification's `<assign>` root while producing natural English across all usage points.

## Proposed Rename

| Current | Proposed | Notes |
|---------|----------|-------|
| `CalculatorBehavior` | `AssignmentBehavior` | Base class |
| `OrderTotalCalculator` | `OrderTotalAssignment` | User class naming pattern |
| `'calculators'` (config key) | `'assignments'` | Plural, consistent with `'actions'`, `'guards'` |
| `orderTotalCalculator` (inline key) | `orderTotalAssignment` | camelCase inline key |
| `BehaviorType::Calculator` | `BehaviorType::Assignment` | Enum case |
| `Calculator` suffix in conventions | `Assignment` suffix | Naming convention doc |

## Why "Assignment" and not "Assign"

The `'assigns'` plural key reads unnaturally in English. Comparison:

```php
// Current — all keys are natural English plurals
behavior: [
    'actions'      => [...],
    'guards'       => [...],
    'calculators'  => [...],
]

// Option A — 'assigns' is not a natural noun plural
behavior: [
    'actions' => [...],
    'guards'  => [...],
    'assigns' => [...],   // awkward
]

// Option B — 'assignments' reads naturally
behavior: [
    'actions'     => [...],
    'guards'      => [...],
    'assignments' => [...],   // natural plural, like 'actions'
]
```

The class suffix also reads better: `OrderTotalAssignment` ("the order total assignment") vs `OrderTotalAssign` (incomplete verb).

## Alternatives Considered

| Option | Key | Class Suffix | Rejected Because |
|--------|-----|-------------|-----------------|
| `Assign` | `'assigns'` | `*Assign` | Unnatural plural, incomplete verb as suffix |
| `Assigner` | `'assigners'` | `*Assigner` | Workable but "assigner" is a person/agent, not the operation |
| `Reducer` | `'reducers'` | `*Reducer` | FP term, unfamiliar to most PHP developers |
| `Preparer` | `'preparers'` | `*Preparer` | Vague — doesn't communicate context mutation |
| `Mutation` | `'mutations'` | `*Mutation` | GraphQL connotation, implies destructive change |
| `Computed` | `'computed'` | `*Computed` | Adjective as class suffix is awkward |

## Execution Order Semantics (Unchanged)

The rename is purely cosmological. Execution order remains:

```
Event received → Assignments run → Guards evaluate → Actions execute
                 ^^^^^^^^^^^
                 (pre-computed values available to guards)
```

This "compute before guard" ordering is what makes assignments distinct from actions. Python transitions' `prepare` is the only other framework with this exact semantic.

## Scope of Change

### Code (src/)
- `src/Behavior/CalculatorBehavior.php` → `AssignmentBehavior.php`
- `BehaviorType::Calculator` → `BehaviorType::Assignment`
- All references in `MachineDefinition`, `TransitionDefinition`, `InvokableBehavior`, etc.

### Tests (tests/)
- Test stub classes: `*Calculator` → `*Assignment`
- Config arrays: `'calculators'` → `'assignments'`
- Test file names where applicable

### Documentation (docs/)
- `docs/behaviors/calculators.md` → `assignments.md`
- Convention doc updates
- All code examples

### Migration Guide
- Document the rename in `docs/getting-started/upgrading.md`
- Simple find-and-replace for users: `Calculator` → `Assignment`, `calculators` → `assignments`

## Open Questions

1. **Should we keep a `CalculatorBehavior` alias temporarily?** Probably not — if we're in a BC window, clean break is better.
2. **Does the rename affect XState export?** The `machine:xstate` command may need updating if it maps calculators to XState concepts.
3. **Convention doc suffix pattern:** `{Subject}{Noun}Calculator` → `{Subject}{Noun}Assignment` — does "OrderTotalAssignment" read well enough? Or should it be `{Verb}{Subject}Assignment` like "AssignOrderTotal"?
4. **Should inline key convention change too?** `orderTotalCalculator` → `orderTotalAssignment` vs `assignOrderTotal`?
