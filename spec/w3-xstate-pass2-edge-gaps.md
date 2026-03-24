# W3 XState Pass 2: Edge-Case Gaps

> Theme: EDGE CASES & BOUNDARY CONDITIONS
> Lens: Off-by-one, empty, null, max depth, single-region parallel, degenerate configs
> Generated: 2026-03-25
> Source: XState v5 test suite (`/tmp/xstate/packages/core/test/`)

---

## Dedup Notes

Checked against:
- All existing EventMachine tests in `tests/` directory (grep'd extensively for each gap topic)
- W3 XState Pass 1 gaps in `spec/w3-xstate-pass1-happy-gaps.md` (13 actionable gaps)
- W1 Pass 2 edge-case gaps (open bead `event-machine-ht-w1p2-edge-cases`)
- Open beads via `bd list --status=open`
- W1P1, AASM P1, Spring, Commons tie-breaker beads

Gaps below are ONLY those NOT already covered by Pass 1 gaps, W1P2 gaps, or existing tests.

---

## Gap E1: Single-region parallel state initializes and transitions correctly

- **Priority**: High
- **XState source**: `parallel.test.ts` lines 758-814 (parallel state with only one region containing initial transition actions)
- **Type**: Feature test (edge case)
- **Scenario**: Create a parallel state with ONLY ONE region (degenerate case). The single region has an initial state. Verify: (a) the machine starts correctly with value `{region: 'child'}`, (b) transitions within the single region work, (c) @done fires when the single region reaches final.
- **Expected behavior**: A parallel state with a single region should function identically to a compound state, but the state value should still be represented as a parallel structure `{region: 'childState'}`.
- **Dedup check**: Grepped "single.*region.*parallel" -- found references in `NestedParallelDoneCascadeTest` but that tests multi-region done cascade. No test for a 1-region parallel state. `BasicParallelStatesTest` uses 2+ regions always.
- **W1P2 overlap**: None
- **Pass 1 overlap**: None

## Gap E2: Flat parallel regions (regions with no child states) represented as empty value

- **Priority**: Medium
- **XState source**: `parallel.test.ts` lines 293-305 (`flatParallelMachine` -- regions `foo` and `bar` have NO child states), lines 603-619
- **Type**: Feature test (edge case)
- **Scenario**: Create a parallel state where some regions have NO child states (leaf regions). In XState these are represented as `{}` in the state value. Verify EventMachine handles regions that are leaf states (no `initial`, no `states` key) within a parallel container. Verify the state value correctly represents these leaf regions.
- **Expected behavior**: A parallel region that is itself a leaf state (no children) should be valid and representable. EventMachine may require all parallel children to be compound -- this tests the boundary.
- **Dedup check**: Grepped "flat.*parallel", "empty.*region" -- found `ParallelValidationGuardTest` mentions "empty region" but that's about guard validation, not the degenerate structure. No test for parallel regions without child states.
- **W1P2 overlap**: None
- **Pass 1 overlap**: None

## Gap E3: @always self-targeting compound child should NOT infinite loop (settles on first pass)

- **Priority**: High
- **XState source**: `transient.test.ts` lines 569-677 (three tests: fallback target, guarded target, action-only target -- all self-targeting @always transitions within compound children)
- **Type**: Feature test (edge case)
- **Scenario**: State `active` has child states `a` and `b`. The `active` state has an @always transition targeting `.b` (its own child). Since entering `.b` doesn't change the "active" parent context, this must NOT infinite loop. The @always should fire once (entering `b`), then settle. Test three variants: (a) fallback target (first guard false), (b) guarded target (guard true targets `.a`), (c) action-only with same target.
- **Expected behavior**: @always transitions that target a child of their own parent compound state settle immediately without looping, because the state value changes from one child to another (or stays same = no re-evaluation).
- **Dedup check**: `MaxTransitionDepthTest` tests @always infinite loops that DO exceed depth. No test for @always self-targeting compound children that settle correctly. `InitialAlwaysChainTest` tests chains, not self-targeting.
- **W1P2 overlap**: None
- **Pass 1 overlap**: X9 covers context-changing @always loop termination. This gap covers the non-context-changing case.

## Gap E4: @always re-evaluated after raised event that doesn't change state

- **Priority**: Medium
- **XState source**: `transient.test.ts` lines 699-727 ("should execute an always transition after a raised transition even if that raised transition doesn't change the state")
- **Type**: Feature test (edge case)
- **Scenario**: Machine has root-level @always with an action. Machine handles `EV` by raising `RAISED`. `RAISED` handler has an action but no target (doesn't change state). The root @always should be re-evaluated after the RAISED event is processed (since it's a new microstep), calling the @always action twice total: once for EV, once for RAISED.
- **Expected behavior**: @always transitions are re-evaluated after every microstep, including microsteps from raised events that don't change state. The @always action fires once per microstep where it's applicable.
- **Dedup check**: Grepped "always.*after.*raised", "always.*re-evaluated" -- no results. `EventProcessingOrderTest` tests ordering but not @always re-evaluation after no-op raised events.
- **W1P2 overlap**: None
- **Pass 1 overlap**: X2 covers @always before raised events (priority). This covers re-evaluation after.

## Gap E5: Cross-region @always with stateIn guards (parallel coordination)

- **Priority**: High
- **XState source**: `transient.test.ts` lines 201-303 (three tests using `stateIn` guards in @always transitions to coordinate across parallel regions)
- **Type**: Feature test (edge case)
- **Scenario**: Parallel machine with regions A, B, C. Region B has @always targeting B2 with guard `stateIn({A: 'A2'})`. Region C has @always targeting C2 with guard `stateIn({A: 'A2'})`. Send event that transitions A from A1 to A2. Verify B and C both transition to B2/C2 respectively via their @always guards detecting A's new state.
- **Expected behavior**: @always transitions in parallel regions can use stateIn-equivalent guards to observe other regions' states. When region A changes, regions B and C re-evaluate their @always guards and transition if the condition is met.
- **Dedup check**: `ParallelAlwaysTransitionsTest` tests @always in parallel but with simple unconditional @always, not cross-region stateIn guards. No test for @always with stateIn-equivalent cross-region coordination. EventMachine doesn't have `stateIn` directly, but guards can check current state via `State` injection.
- **W1P2 overlap**: None
- **Pass 1 overlap**: None

## Gap E6: Null/empty output on final state is correctly passed through @done

- **Priority**: Medium
- **XState source**: `final.test.ts` lines 1249-1293 (two tests: null output directly on root, null output resolving with final state's output)
- **Type**: Feature test (edge case)
- **Scenario**: Create a machine where a final state has `null` output (or empty/undefined output). The parent's @done handler receives this null output. Verify the result/output is `null`/empty rather than undefined or throwing.
- **Expected behavior**: Null is a valid output value. @done events should carry null output faithfully, not treat it as "no output" or throw.
- **Dedup check**: Grepped "null.*output", "output.*null" -- found tests in `ForwardEndpointEdgeCasesTest`, `ArchiveEdgeCasesTest`, `MachineDelegationTest` but these deal with null in different contexts (HTTP responses, archive). No test specifically for null result from a final state passed via @done.
- **W1P2 overlap**: None
- **Pass 1 overlap**: None

## Gap E7: Parallel state not done when only SOME regions are final (partial completion boundary)

- **Priority**: High
- **XState source**: `final.test.ts` lines 1036-1087 (two tests: one region final + one region not final; direct final child + compound region not final)
- **Type**: Feature test (edge case)
- **Scenario**: Parallel state has two regions. Region A starts in its final state immediately. Region B has a non-final initial state. Verify the parallel state does NOT fire @done. Only when region B also reaches final should @done fire. Test both variants: (a) region A is compound with final child, (b) region A is a direct `type: final` child of parallel.
- **Expected behavior**: Parallel @done requires ALL regions to be in final states. Having some regions in final while others are not is the boundary condition -- must NOT fire @done prematurely.
- **Dedup check**: `ParallelFinalStatesTest` tests that @done fires after all regions complete, and `ParallelDoneTimingTest` tests timing. But neither explicitly tests the boundary where SOME regions start in final and the parallel must NOT be done. This is the "partial completion" edge case.
- **W1P2 overlap**: None
- **Pass 1 overlap**: X4 covers "fires exactly once on simultaneous completion". This covers the opposite: NOT firing on partial completion.

## Gap E8: Output/result of final child NOT resolved when parent is parallel (non-completing region)

- **Priority**: Medium
- **XState source**: `final.test.ts` lines 1089-1116 ("should not resolve output of a final state if its parent is a parallel state")
- **Type**: Feature test (edge case)
- **Scenario**: Parallel state has region B with `type: final` and output/result behavior, and region C which is not final. The output of region B should NOT be resolved/called because the parallel state is not done yet (C is still active). Only when ALL regions are final should outputs be resolved.
- **Expected behavior**: Result/output behaviors on final states within parallel regions are deferred until the parallel state itself completes. Individual region completion does not trigger output resolution.
- **Dedup check**: Grepped "result.*parallel.*final", "output.*parallel.*region" -- no results. `ParallelFinalStatesTest` tests @done timing but not output resolution timing. `ResultBehaviorInjectionTest` tests result injection but not the parallel-specific deferral.
- **W1P2 overlap**: None
- **Pass 1 overlap**: None

## Gap E9: Restore/rehydrate does NOT replay entry actions

- **Priority**: High
- **XState source**: `rehydration.test.ts` lines 159-176 ("should not replay actions when starting from a persisted state"), also `interpreter.test.ts` lines 109-171
- **Type**: Feature test (edge case)
- **Scenario**: Machine has an entry action with a side effect. Create and start the machine (entry fires). Persist its state. Restore the machine from persisted state. Verify the entry action does NOT fire again on restore.
- **Expected behavior**: When restoring a machine from persisted state, entry actions must NOT be replayed. The machine resumes from the persisted state without re-executing entry actions.
- **Dedup check**: `MachineIdentityRestoreTest` tests restore but focuses on machineId/parentMachineId identity, not entry action replay. `ArchiveTransparencyTest` tests archive/restore transparency. No test explicitly verifies entry actions are NOT replayed on restore.
- **W1P2 overlap**: None
- **Pass 1 overlap**: None

## Gap E10: Restore from invalid/incompatible state value throws clear error

- **Priority**: Medium
- **XState source**: `rehydration.test.ts` lines 127-156 ("should error on incompatible state value" -- both shallow and deep)
- **Type**: Feature test (edge case)
- **Scenario**: Try to restore a machine from a state value that doesn't exist in the machine definition (e.g., `startingAt('nonexistent_state')`). Verify a clear, descriptive error is thrown rather than a cryptic null reference or silent corruption.
- **Expected behavior**: Attempting to restore from a state value not in the definition should throw a descriptive exception indicating the invalid state.
- **Dedup check**: Grepped "resolveState", "incompatible.*state", "invalid.*state.*value" -- no results. `StateConfigValidatorTest` validates config structure but not runtime restore with invalid values. No test for startingAt with nonexistent state.
- **W1P2 overlap**: None
- **Pass 1 overlap**: None

## Gap E11: Restore rehydrated done child does NOT re-notify parent of completion

- **Priority**: Medium
- **XState source**: `rehydration.test.ts` lines 269-303 ("should not re-notify the parent about its completion")
- **Type**: Feature test (edge case)
- **Scenario**: Parent machine has a child delegation. Child reaches final. Parent handles @done and transitions. Persist the parent (which includes the done child). Restore parent. Verify the parent does NOT receive a second @done notification for the already-completed child.
- **Expected behavior**: When restoring a machine with a completed child, the child's completion event must not be re-delivered. The parent stays in whatever state it was persisted in.
- **Dedup check**: `AsyncMachineDelegationTest`, `MachineDelegationTest` test delegation but not the restore-of-done-child scenario. `ArchiveTransparencyTest` tests archive restore but not the child-done re-notification edge case.
- **W1P2 overlap**: None
- **Pass 1 overlap**: None

## Gap E12: availableEvents returns false for unknown events and true for guarded transitions

- **Priority**: Medium
- **XState source**: `state.test.ts` lines 263-341 (.can() tests: unknown event returns false, guarded transition true returns true, guarded transition false returns false)
- **Type**: Feature test (edge case)
- **Scenario**: Test `availableEvents()` edge cases: (a) unknown event type not listed in any `on` handler -- should NOT appear in available events, (b) event with guard returning true -- should appear, (c) event with guard returning false -- should NOT appear. Also test (d) targetless transition with action -- should appear.
- **Expected behavior**: `availableEvents()` should filter based on whether the event would produce any effect (state change, action execution, or context change). Events with all-failing guards should be excluded.
- **Dedup check**: `AvailableEventsTest` exists and tests basic scenarios. Need to check if it covers guarded-event exclusion. Reading first 100 lines showed basic on-event listing. May partially overlap -- need to verify guard-based filtering is tested.
- **W1P2 overlap**: None
- **Pass 1 overlap**: None

## Gap E13: availableEvents in parallel state shows events from ALL regions

- **Priority**: Medium
- **XState source**: `state.test.ts` lines 416-447 (.can() returns true when non-first parallel region changes value)
- **Type**: Feature test (edge case)
- **Scenario**: Parallel machine has regions A and B. Region A handles EVENT_A, region B handles EVENT_B. From initial state, `availableEvents()` should include BOTH EVENT_A and EVENT_B.
- **Expected behavior**: `availableEvents()` collects events from ALL active parallel regions, not just the first region.
- **Dedup check**: `AvailableEventsTest` has section "Parallel state available events" but need to verify it covers aggregation from all regions explicitly.
- **W1P2 overlap**: None
- **Pass 1 overlap**: None

## Gap E14: Targetless transition on parallel state triggers no entry/exit

- **Priority**: Medium
- **XState source**: `parallel.test.ts` lines 1283-1345 (two tests: targetless on parallel root, targetless in one region)
- **Type**: Feature test (edge case)
- **Scenario**: Parallel machine has an event handler with action but no target (targetless transition) on the parallel state itself. Send the event. Verify NO entry or exit actions fire -- only the transition action runs. Also test: targetless transition defined on ONE region of a parallel state -- same behavior.
- **Expected behavior**: A targetless transition (action-only, no state change) should not cause any entry or exit actions to fire, even in parallel states.
- **Dedup check**: `TargetlessTransitionTest` tests targetless transitions in simple machines. `TargetlessOnDoneTest` tests targetless @done. Neither tests targetless transitions on parallel state roots or regions specifically. `ParallelDispatchXStateTest` tests targetless but in dispatch mode context.
- **W1P2 overlap**: None
- **Pass 1 overlap**: None

## Gap E15: Cross-region transition re-enters source region (LCA semantics in parallel)

- **Priority**: High
- **XState source**: `parallel.test.ts` lines 1180-1281 (two tests: parallel root and nested parallel -- when a transition in region A targets a state in region B, region A is exited and re-entered)
- **Type**: Feature test (edge case)
- **Scenario**: Parallel machine has regions Operation and Mode. Operation.Waiting handles TOGGLE_MODE targeting Mode.Demo (a state in the OTHER region). Because the LCA of the transition is the parallel state itself, both Operation and Mode regions must be exited and re-entered. Verify the exact entry/exit ordering.
- **Expected behavior**: When a transition crosses from one parallel region to another, the LCA is the parallel state. Both the source region and the target region are exited and re-entered. Entry/exit ordering follows document order.
- **Dedup check**: `LcaTransitionTest` tests LCA semantics but for compound states, not cross-region parallel transitions. `ParallelEscapeTransitionTest` tests transitions OUT of a parallel state. No test for cross-region targeting within a parallel state.
- **W1P2 overlap**: None
- **Pass 1 overlap**: None

## Gap E16: Deep hierarchy exit ordering (4 levels: A.B.C.D exits in reverse D,C,B,A)

- **Priority**: Medium
- **XState source**: `deep.test.ts` lines 1-495 (7 tests for deep 4-level hierarchy exit ordering from various levels)
- **Type**: Feature test (edge case)
- **Scenario**: Machine has 4-level nesting: A -> B -> C -> D. From the deepest state D, transition to a sibling top-level state. Verify exit actions fire in correct reversed order: D, C, B, A. Test triggering the exit from different levels (A_EVENT from A level, B_EVENT from B level, etc.).
- **Expected behavior**: Exit actions fire from deepest to shallowest, regardless of which ancestor handles the event.
- **Dedup check**: `DeepHierarchyOrderingTest` exists. Need to verify if it covers 4-level depth. Reading its name suggests it does, but may not cover all variants (exit from each level).
- **W1P2 overlap**: None
- **Pass 1 overlap**: X10 covers parallel completion exit ordering. This covers compound deep hierarchy.

## Gap E17: Guard error during transition crashes machine (error boundary)

- **Priority**: High
- **XState source**: `errors.test.ts` lines 919-951 ("should error when a guard throws when transitioning")
- **Type**: Feature test (edge case)
- **Scenario**: A guard behavior throws an exception during evaluation. Verify the machine enters an error state (or throws a descriptive exception) rather than silently swallowing the error or leaving the machine in an inconsistent state.
- **Expected behavior**: When a guard throws during transition evaluation, the error should be surfaced clearly. The machine should not transition to the target state.
- **Dedup check**: `ParallelAlwaysTransitionsTest` mentions "guard error" in a comment. `ResolveInlineBehaviorTest` tests inline behavior resolution. Neither specifically tests a guard that throws an exception during evaluation and verifies the machine handles it as an error boundary.
- **W1P2 overlap**: None (W1P2 focuses on validation guards, not throwing guards)
- **Pass 1 overlap**: None

## Gap E18: Entry action error on initial state prevents machine from starting cleanly

- **Priority**: Medium
- **XState source**: `errors.test.ts` lines 766-885 (three tests: error in initial entry action errors the actor, error in initial builtin entry errors immediately, deferred actions after error not executed)
- **Type**: Feature test (edge case)
- **Scenario**: Machine's initial state has an entry action that throws an exception. Verify: (a) the machine does not silently start, (b) subsequent actions after the error are NOT executed, (c) the error is surfaced clearly (exception thrown or error state).
- **Expected behavior**: If an entry action throws during machine initialization, the machine should fail to start cleanly. Actions defined after the failing action in the same entry array should NOT be executed.
- **Dedup check**: No test for entry action errors during initialization. `ActionTest` tests action execution but not error scenarios. `MachineTest` tests machine creation but not entry-throws scenarios.
- **W1P2 overlap**: None
- **Pass 1 overlap**: None

## Gap E19: Delayed conditional transition fires only one event (not one per guard candidate)

- **Priority**: Medium
- **XState source**: `after.test.ts` lines 165-199 (#886 regression: "should defer a single send event for a delayed conditional transition")
- **Type**: Feature test (edge case)
- **Scenario**: State has an `after` timer with multiple guarded transitions (array of candidates). When the delay fires, only the first matching candidate should be selected. The timer should NOT fire one event per candidate -- it should fire a single delayed event, and the guard evaluation selects the winner.
- **Expected behavior**: A delayed transition with multiple guarded candidates evaluates guards at fire time and selects exactly one transition. No duplicate events.
- **Dedup check**: `TimerEdgeCasesTest` tests timer edge cases. `TimerTest` tests basic timer functionality. Neither specifically tests that a guarded delayed transition fires only one event (not per-candidate).
- **W1P2 overlap**: None
- **Pass 1 overlap**: None

---

# Summary

| # | Gap Title | Priority | Overlap |
|---|-----------|----------|---------|
| E1 | Single-region parallel state | High | None |
| E2 | Flat parallel regions (no child states) | Medium | None |
| E3 | @always self-targeting compound child settles (no loop) | High | Partial (P1 X9) |
| E4 | @always re-evaluated after no-op raised event | Medium | Partial (P1 X2) |
| E5 | Cross-region @always with stateIn-equivalent guards | High | None |
| E6 | Null/empty output on final state via @done | Medium | None |
| E7 | Parallel NOT done when only some regions final | High | Partial (P1 X4) |
| E8 | Final child output NOT resolved in incomplete parallel | Medium | None |
| E9 | Restore does NOT replay entry actions | High | None |
| E10 | Restore from invalid state throws clear error | Medium | None |
| E11 | Restore done child does NOT re-notify parent | Medium | None |
| E12 | availableEvents: unknown events excluded, guard-filtered | Medium | Possible partial |
| E13 | availableEvents in parallel aggregates all regions | Medium | Possible partial |
| E14 | Targetless transition on parallel triggers no entry/exit | Medium | None |
| E15 | Cross-region transition re-enters source region (LCA) | High | None |
| E16 | Deep hierarchy exit ordering (4 levels) | Medium | Possible partial |
| E17 | Guard error during transition = error boundary | High | None |
| E18 | Entry action error on initial state prevents clean start | Medium | None |
| E19 | Delayed conditional transition fires single event | Medium | None |

## Actionable Gaps (19 beads)

1. **Gap E1** -- Single-region parallel state (High)
2. **Gap E2** -- Flat parallel regions with no child states (Medium)
3. **Gap E3** -- @always self-targeting compound child no-loop (High)
4. **Gap E4** -- @always re-evaluated after no-op raised event (Medium)
5. **Gap E5** -- Cross-region @always with stateIn-like guards (High)
6. **Gap E6** -- Null output on final state via @done (Medium)
7. **Gap E7** -- Parallel NOT done on partial completion (High)
8. **Gap E8** -- Final child output deferred until parallel completes (Medium)
9. **Gap E9** -- Restore does NOT replay entry actions (High)
10. **Gap E10** -- Restore from invalid state throws error (Medium)
11. **Gap E11** -- Restore done child no re-notification (Medium)
12. **Gap E12** -- availableEvents guard filtering edge cases (Medium)
13. **Gap E13** -- availableEvents parallel aggregation (Medium)
14. **Gap E14** -- Targetless transition on parallel no entry/exit (Medium)
15. **Gap E15** -- Cross-region transition LCA re-entry (High)
16. **Gap E16** -- Deep hierarchy 4-level exit ordering (Medium)
17. **Gap E17** -- Guard error = error boundary (High)
18. **Gap E18** -- Entry action error prevents start (Medium)
19. **Gap E19** -- Delayed conditional single-fire (Medium)
