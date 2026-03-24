# Boost.Statechart Pass 2 — Gaps (Edge Cases & Compile-Time Validation)

> Generated: 2026-03-25
> Source: /tmp/boost-statechart/test/ (InvalidChartTest1-3, InvalidTransitionTest1-2, TerminationTest)
> Focus: Invalid hierarchy detection, illegal cross-region transitions, termination cascading

---

## Source Pattern Review

### InvalidChartTest1.cpp — State claims child of non-container parent
State B declares itself as a child of A (`simple_state<B, A>`), but A has no inner states declared.
Boost catches this at compile-time/initiation.

### InvalidChartTest2.cpp — State references non-existent orthogonal region
A has one region (region 0, child B). State C claims to be in `A::orthogonal<1>` which does not exist.
Boost catches this at compile-time.

### InvalidChartTest3.cpp — Duplicate/misassigned region indices
A declares two regions (B in region 0, C in region 1). But B claims `A::orthogonal<1>` and C also claims `A::orthogonal<1>` — B should be in region 0.
Boost catches this at compile-time.

### InvalidTransitionTest1.cpp — Cross-region transition (direct)
Parallel state Active has regions Idle0 (region 0) and Idle1 (region 1). Idle0 defines a transition targeting Idle1. This is illegal — transitions cannot cross orthogonal region boundaries.
Boost catches this at initiation.

### InvalidTransitionTest2.cpp — Cross-region transition (nested)
Same as Test1 but deeper: Idle10 (nested in region 1) transitions to Idle00 (nested in region 0). Cross-region even when nested deeper.
Boost catches this at initiation.

### TerminationTest.cpp — Selective region termination and cascading
Deeply nested parallel hierarchy: A(B, C(D, E(F, G))) — 3 levels of orthogonal regions.
Key scenarios:
- Terminating E removes E, F, G but keeps A, B, C, D
- Terminating C removes C, D, E, F, G but keeps A, B
- Bottom-up termination: G -> F -> E auto-terminates -> D -> C auto-terminates -> B -> A auto-terminates
- Re-initiation after termination restores all states
- Double termination is idempotent
- exit() always called before destructor

---

## Gap Analysis

### Gap 1: No validation that states reference declared children (InvalidChartTest1 pattern)

**Boost pattern:** State B claims to be child of A, but A has no `states` key declaring children.

**EventMachine behavior:** In EventMachine, state hierarchy is defined top-down: parent states contain their children in the `states` key. A child cannot "claim" a parent — it is declared by the parent. This makes the InvalidChartTest1 pattern structurally impossible in EventMachine's config format. The parent defines children, not the reverse.

**Gap status:** NOT APPLICABLE — EventMachine's top-down config format prevents this by design.

**However:** A related edge case IS possible — a transition target referencing a state that does not exist anywhere in the config. `TransitionBranch` throws `NoStateDefinitionFoundException` at definition time when the target is unresolvable. This is already tested indirectly but lacks explicit edge case tests for:
- Transition targeting a deeply nested state that was misspelled
- Transition targeting a state name that exists at a different level than expected

**Dedup check:** Searched tests for `NoStateDefinitionFoundException` — found in `tests/Definition/TransitionDefinitionTest.php` but only as general tests. No specific "misspelled target" or "wrong-level target" test.

**Proposed bead:** Test that definition-time validation catches transition targets referencing non-existent states (typos, wrong nesting level).

---

### Gap 2: No validation for parallel state region structure (InvalidChartTest2/3 patterns)

**Boost pattern:** State claims to be in a non-existent orthogonal region, or two states claim the same region index.

**EventMachine behavior:** EventMachine defines parallel regions via the `states` key under a `type: parallel` state. Each key in `states` IS a region. There are no explicit region indices — regions are identified by their key names. This means:
- "Non-existent region" is impossible (regions are the keys you define)
- "Duplicate region index" is impossible (PHP array keys are unique by definition)

**Gap status:** NOT APPLICABLE — EventMachine's named-region config format prevents index-based errors by design.

**However:** There ARE parallel-specific edge cases that could be tested:
- **Single-region parallel state**: A parallel state with only one region child — is this valid? `StateConfigValidator` only checks `states` is non-empty, not that there are >= 2 regions.
- **Parallel state with atomic children (no initial/states)**: A region child that has no `initial` key and no `states` — effectively an empty region.
- **Parallel state where all regions are immediately final**: Every region's initial state is `type: final`.

**Dedup check:** Searched tests for "single.region" — found `ParallelIndependentTieBreakTest` but it tests tie-breaking, not validation. No test for single-region parallel validation. `StateConfigValidator::validateParallelState` checks `states !== []` but not count >= 2.

**Proposed bead:** Test definition-time validation edge cases for parallel states: (a) single-region parallel (should this be allowed?), (b) parallel with empty/trivial regions, (c) parallel where all regions start final.

---

### Gap 3: No validation for cross-region transitions (InvalidTransitionTest1/2 patterns) — CRITICAL GAP

**Boost pattern:** Transition from Idle0 (region 0) to Idle1 (region 1) is illegal. Transitions cannot cross orthogonal region boundaries.

**EventMachine behavior:** Examined `StateConfigValidator` — it validates transition config format (keys, types) but does NOT validate that transition targets are legal relative to region boundaries. The `TransitionBranch` constructor resolves targets via `getNearestStateDefinitionByString()` which walks up the hierarchy looking for a match — it will find any state by name regardless of region boundaries. No cross-region check exists.

At runtime, `transitionParallelState()` in `MachineDefinition` dispatches events to regions independently and each region selects its own transitions. So a region can only define transitions on its own states. But the VALIDATION does not reject a config where region A's state targets a state in region B.

**Specific risk:** If a user writes:
```php
'processing' => [
    'type' => 'parallel',
    'states' => [
        'region_a' => [
            'initial' => 'idle_a',
            'states' => [
                'idle_a' => ['on' => ['GO' => 'idle_b']], // targets state in region_b!
            ],
        ],
        'region_b' => [
            'initial' => 'idle_b',
            'states' => ['idle_b' => []],
        ],
    ],
],
```
The definition will resolve `idle_b` via the hierarchy walk and succeed. At runtime, the behavior is undefined — the transition system may silently put both regions into the same state, corrupt the state value array, or throw an unexpected error.

**Gap status:** CRITICAL — No compile-time validation prevents illegal cross-region transitions.

**Dedup check:** Searched tests for "cross.region" — found `ParallelCrossRegionRaiseTest` and `ParallelDispatchEventQueueCrossRegionTest` but these test raise() across regions, not illegal transition targets. No test validates that cross-region transition targets are rejected at definition time.

**Proposed beads:**
1. **Test bead:** Write a test confirming that cross-region transitions are rejected at definition time (or if they are NOT currently rejected, document and test the current runtime behavior as a known gap).
2. **Fix bead (conditional):** If the test in bead 1 reveals that cross-region transitions are silently accepted, add validation to `StateConfigValidator` or `TransitionBranch` to detect and reject them.

---

### Gap 4: No validation for nested cross-region transitions (InvalidTransitionTest2 pattern)

**Boost pattern:** Idle10 (nested in Idle1, region 1) transitions to Idle00 (nested in Idle0, region 0). Cross-region even at deeper nesting.

**EventMachine behavior:** Same as Gap 3 but at deeper nesting. `resolveStateRelativeToSource()` walks up the hierarchy and will match a deeply nested target in a sibling region. No depth-level cross-region check.

**Gap status:** CRITICAL — Same root cause as Gap 3 but for nested states within regions.

**Dedup check:** Subsumed by Gap 3 but needs its own test case (nested variant).

**Proposed bead:** Include in the Gap 3 test bead: a nested variant where the source and target are both deeply nested in different regions.

---

### Gap 5: Selective parallel region termination (TerminationTest patterns)

**Boost pattern:** Terminating region E removes E, F, G but keeps A, B, C, D. Terminating C cascades to remove C, D, E, F, G.

**EventMachine behavior:** EventMachine does not have "selective region termination" as a first-class concept. Regions complete by reaching a `type: final` state. The `@done` event fires only when ALL regions reach final. There is no mechanism to:
- Terminate a single region independently
- Have a region "fail" without affecting the parallel state's overall lifecycle (outside of dispatch mode)

The closest equivalent is:
- A region reaching a final state (completion, not termination)
- `@fail` on the parallel state (region failure)
- In dispatch mode, `ParallelRegionTimeoutJob` can time out individual regions

**Testing gap:** While the Boost termination model doesn't map 1:1, the cascading semantics DO map:
- When a parallel state exits (via @done or event on parent), ALL active child states in ALL regions must exit
- Exit actions must run for all active states in exit order (innermost first)
- Re-entering a parallel state after it exits must re-initialize all regions

**Dedup check:** Searched for "exit.*order.*parallel" — `ActionOrderingTest` exists but does not test parallel exit ordering. `ParallelFinalStatesTest` tests @done firing but not exit action cascading. No test verifies that exiting a parallel state runs exit actions on all active region states in correct order.

**Proposed bead:** Test parallel state exit cascading: when a parallel state transitions out (via event on parent or @done), verify that exit actions run on ALL active states in ALL regions, in innermost-first order.

---

### Gap 6: Bottom-up parallel completion cascading (TerminationTest bottom-up pattern)

**Boost pattern:** Terminate G -> F -> E auto-terminates (all regions done) -> D -> C auto-terminates -> B -> A auto-terminates. When all regions of a parallel state are terminated/completed, the parent auto-terminates.

**EventMachine behavior:** EventMachine has `allRegionsFinal()` which checks if all regions are in final states and fires @done. But the cascading is not extensively tested for deeply nested parallel states (parallel within parallel within parallel).

**Existing coverage:** `NestedParallelDoneCascadeTest` exists — let me check its scope.

**Dedup check:** `NestedParallelDoneCascadeTest` tests nested parallel @done cascading. Need to verify if it covers the 3-level deep case (A > C(D,E) > E(F,G)).

**Proposed bead:** Test deeply nested parallel completion cascading (3+ levels): when the innermost parallel's regions all reach final, it fires @done, which may cause the mid-level parallel's region to reach final, which cascades to the outer parallel's @done.

---

### Gap 7: Double termination idempotency (TerminationTest double-terminate pattern)

**Boost pattern:** Terminating an already-terminated region is a no-op. Terminating an already-terminated machine is a no-op.

**EventMachine behavior:** Sending an event to a machine in a final state — what happens? The machine should either ignore it or throw. This needs testing.

**Dedup check:** No test for "send event to machine in final state" or "double @done firing". Searched for "final.*send" and "terminated.*event" — no explicit test found.

**Proposed bead:** Test that sending events to a machine that has already reached a final state is handled gracefully (either no-op or explicit error), and that @done cannot fire twice for the same parallel state.

---

### Gap 8: Re-initiation after termination (TerminationTest re-initiate pattern)

**Boost pattern:** After termination, `machine.initiate()` restores all states. Double initiation (initiate on already-active machine) is idempotent.

**EventMachine behavior:** EventMachine creates new machine instances rather than re-initiating existing ones. The `Machine::create()` always creates fresh. But `Machine::restore()` restores from persisted state. There is no "re-initiate after final state" concept.

**Gap status:** PARTIALLY APPLICABLE — The concept maps to: can you restore a machine that is in a final state and send events? This should be tested.

**Dedup check:** No test for restoring a machine in final state.

**Proposed bead:** Test that restoring a machine in a final state preserves the final state and does not accept new events (or documents the actual behavior).

---

## Summary: 8 Gaps Found

| # | Gap | Boost Source | Applicability | Priority |
|---|-----|-------------|---------------|----------|
| 1 | Non-existent state target (typo/wrong level) | InvalidChartTest1 | PARTIAL | MEDIUM |
| 2 | Parallel single-region / trivial-region edge cases | InvalidChartTest2/3 | PARTIAL | LOW |
| 3 | Cross-region transition not validated | InvalidTransitionTest1 | CRITICAL | P0 |
| 4 | Nested cross-region transition not validated | InvalidTransitionTest2 | CRITICAL (same root) | P0 |
| 5 | Parallel exit action cascading order | TerminationTest | APPLICABLE | HIGH |
| 6 | Deeply nested parallel @done cascading (3+ levels) | TerminationTest | APPLICABLE | MEDIUM |
| 7 | Double termination / send to final machine | TerminationTest | APPLICABLE | MEDIUM |
| 8 | Restore machine in final state | TerminationTest | PARTIAL | LOW |

---

## Bead Plan

### Bead 1: Cross-region transition validation (Gaps 3 + 4)
- **Type:** test-writing (TDD — may need source fix)
- **Priority:** P0
- **Tags:** boost, cross-region, validation, parallel, test-writing
- **Description:** Write tests that attempt to define a machine with cross-region transitions: (a) direct — state in region A targets state in region B, (b) nested — deeply nested state in region A targets deeply nested state in region B. Verify that definition-time validation catches these illegal transitions. If validation does NOT currently exist, this test will fail and the agent must add cross-region validation to `StateConfigValidator` or `TransitionBranch` to make the test pass (TDD approach per rule 12).
- **Test location:** tests/Features/ParallelStates/ParallelCrossRegionTransitionValidationTest.php

### Bead 2: Non-existent transition target edge cases (Gap 1)
- **Type:** test-writing
- **Priority:** P2
- **Tags:** boost, validation, transition-target, test-writing
- **Description:** Write tests verifying that `TransitionBranch` throws `NoStateDefinitionFoundException` for: (a) misspelled target state name, (b) target that exists at a different nesting level than where it is reachable from the source, (c) target that is a sibling of a different parent. These are definition-time errors.
- **Test location:** tests/Features/TransitionTargetValidationEdgeCasesTest.php

### Bead 3: Parallel state edge case validation (Gap 2)
- **Type:** test-writing
- **Priority:** P3
- **Tags:** boost, parallel, validation, edge-case, test-writing
- **Description:** Write tests for parallel state edge cases: (a) single-region parallel — verify whether it is accepted or rejected and document, (b) parallel where all regions start in final states — verify @done fires immediately, (c) parallel with empty region (no states).
- **Test location:** tests/Features/ParallelStates/ParallelEdgeCaseValidationTest.php

### Bead 4: Parallel exit action cascading order (Gap 5)
- **Type:** test-writing
- **Priority:** P1
- **Tags:** boost, parallel, exit-action, cascade, test-writing
- **Description:** Write tests verifying that when a parallel state exits (via event on parent or @done transition), exit actions run on ALL active states in ALL regions in innermost-first order. Use a machine with parallel state having 2+ regions, each with nested states. Track exit action execution order via context.
- **Test location:** tests/Features/ParallelStates/ParallelExitActionCascadeTest.php

### Bead 5: Deeply nested parallel @done cascading (Gap 6)
- **Type:** test-writing
- **Priority:** P2
- **Tags:** boost, parallel, nested, done-cascade, test-writing
- **Description:** Write tests for 3-level deep parallel nesting: outer parallel > region with inner parallel > region with innermost parallel. When all innermost regions reach final, @done cascades up through each level. Verify the cascade fires correctly at each level. Check against `NestedParallelDoneCascadeTest` to avoid duplication — extend coverage if needed.
- **Test location:** tests/Features/ParallelStates/ParallelTripleNestedDoneCascadeTest.php

### Bead 6: Send event to machine in final state / double @done (Gap 7)
- **Type:** test-writing
- **Priority:** P2
- **Tags:** boost, termination, final-state, idempotency, test-writing
- **Description:** Write tests verifying: (a) sending an event to a machine that has reached a final state is handled gracefully (no-op, error, or documented behavior), (b) @done on a parallel state cannot fire twice even if regions reach final simultaneously, (c) processing events after @done has already fired on a parallel state.
- **Test location:** tests/Features/FinalStateSendEventTest.php

### Bead 7: Restore machine in final state (Gap 8)
- **Type:** test-writing
- **Priority:** P3
- **Tags:** boost, restore, final-state, test-writing
- **Description:** Write tests verifying behavior when restoring (via `Machine::restore()`) a machine that was persisted in a final state. Verify the state is correctly restored and document whether further events are accepted or rejected.
- **Test location:** tests/Features/RestoreFinalStateMachineTest.php
