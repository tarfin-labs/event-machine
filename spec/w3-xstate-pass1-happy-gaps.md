# W3 XState Pass 1: Happy-Path Gaps

> Theme: HAPPY PATH / SEMANTIC CORRECTNESS
> Lens: Basic behavior that should work but isn't tested.
> Generated: 2026-03-25
> Source: XState v5 test suite (`/tmp/xstate/packages/core/test/`)

---

## Dedup Notes

Checked against:
- All existing EventMachine tests in `tests/` directory (grep'd extensively)
- W1 Pass 1 gaps in `spec/w1-pass1-happy-path-gaps.md` (14 actionable gaps)
- Open beads via `bd list --status=open` (no existing XState pass-1 child beads)

Gaps below are ONLY those NOT already covered by W1P1 gaps or existing tests.

---

## Gap X1: Raised event in one parallel region triggers transition in another region

- **Priority**: High
- **XState source**: `parallel.test.ts` lines 307-372 (`raisingParallelMachine`), lines 637-682 (entry raise triggers cross-region transition)
- **Type**: Feature test
- **Scenario**: Create a parallel machine with regions A and B. Region A raises an event on entry. Region B handles that raised event and transitions. Verify B transitions correctly in response to A's raised event.
- **Expected behavior**: Raised events in one region are visible to other parallel regions within the same macrostep. Region B transitions based on the event raised by region A's entry action.
- **Dedup check**: Grepped "raise.*cross.*region", "raised.*event.*parallel" -- found `ParallelDispatchEventQueueParentTest` and `CompoundOnDoneEventQueueTest`, but these test dispatch-mode event queues, NOT basic sync-mode cross-region raised events. `ParallelDispatchInternalEventsTest` tests internal events in dispatch mode. No basic sync-mode test for entry-raise-triggers-cross-region-transition.
- **W1P1 overlap**: None (W1P1 has no cross-region raise gap)

## Gap X2: @always transitions evaluated before raised events in processing order

- **Priority**: High
- **XState source**: `transient.test.ts` lines 320-350 ("should select eventless transition before processing raised events")
- **Type**: Feature test
- **Scenario**: Create a machine where state B has both an entry raise(BAR) and an @always to C. C handles BAR. Verify the machine goes to C (via @always first), then processes BAR there -- not going to D (which B would go to if BAR processed first).
- **Expected behavior**: @always transitions have priority over raised events. The eventless transition fires first, then raised events process in the new state.
- **Dedup check**: Grepped "eventless.*before.*raised", "always.*before.*raise", "always.*priority" -- no results. `EventProcessingOrderTest` tests entry-before-raise, not always-before-raise. This is a genuinely untested ordering guarantee.
- **W1P1 overlap**: None

## Gap X3: @always transition carries actions from previous transition within same macrostep

- **Priority**: Medium
- **XState source**: `transient.test.ts` lines 108-136 ("should carry actions from previous transitions within same step")
- **Type**: Feature test
- **Scenario**: Machine in state A handles TIMER event targeting T. T has @always to B. A has an exit action, the transition has a transition action, B has an entry action. Verify all actions fire in the correct order within a single macrostep: exit_A, timer_action, enter_B.
- **Expected behavior**: The @always transition from T to B happens within the same macrostep as the A->T transition. Actions from A's exit, the A->T transition, and B's entry all execute in one macrostep.
- **Dedup check**: Grepped "carry.*action", "action.*same.*step", "macrostep.*action" -- no results. `AlwaysEventPreservationTest` tests event preservation through @always chains but not action accumulation across macrostep boundaries.
- **W1P1 overlap**: W1P1 Gap 3 covers action ordering for simple A->B but not action accumulation through @always chains.

## Gap X4: Parallel state @done fires only once when multiple regions complete simultaneously

- **Priority**: Medium
- **XState source**: `final.test.ts` lines 786-833 ("onDone should only be called once when multiple parallel regions complete at once")
- **Type**: Feature test
- **Scenario**: Create a parallel state where both regions start in their final states immediately (or both reach final on the same event). Verify the @done action/transition fires exactly once, not once per region.
- **Expected behavior**: @done fires exactly once when all regions are final, regardless of how many regions complete simultaneously.
- **Dedup check**: Grepped "done.*once", "done.*exactly.*once", "duplicate.*done" -- no results. `ParallelFinalStatesTest` tests that @done fires after all regions are final but doesn't assert it fires exactly once (no count assertion). `ParallelDispatchScxmlDoneTest` focuses on dispatch mode.
- **W1P1 overlap**: W1P1 Gap 10 tests @done timing but not the "exactly once" constraint.

## Gap X5: Nested parallel states: @done cascades correctly through parallel-within-parallel

- **Priority**: High
- **XState source**: `final.test.ts` lines 268-362 ("should emit done state event for parallel state when its parallel children reach final states")
- **Type**: Feature test
- **Scenario**: Create a machine with a parallel state containing two child parallel states (alpha and beta), each with their own regions. All four sub-regions must reach final for the top-level @done to fire. Verify the @done fires only when all 4 sub-regions across both nested parallel children are complete.
- **Expected behavior**: @done at the top parallel state fires only when ALL regions (across all nested parallel children) have reached final states.
- **Dedup check**: Grepped "nested.*parallel.*done", "parallel.*nested.*done" -- found `NestedParallelExitActionsTest` which tests exit action ordering in nested parallel, but NOT done-cascading through nested parallel states. No test for parallel-within-parallel @done cascade.
- **W1P1 overlap**: None

## Gap X6: Compound @done cascades: final child in compound triggers parent @done which triggers grandparent @done

- **Priority**: Medium
- **XState source**: `final.test.ts` lines 95-126 ("should execute final child state actions first")
- **Type**: Feature test
- **Scenario**: Machine has compound state foo -> bar -> baz (final). baz reaching final triggers bar's @done to barFinal (also final), which triggers foo's @done. Verify all entry actions fire in order: bazAction, barAction, fooAction.
- **Expected behavior**: Done events cascade upward through compound state hierarchy. Each level's @done fires after its child reaches final.
- **Dedup check**: `CompoundOnDoneEventQueueTest` tests compound @done but focuses on raise/always interaction, not multi-level cascading. No test for 3-level compound @done cascade.
- **W1P1 overlap**: None

## Gap X7: Simultaneous orthogonal transitions -- same event transitions and updates context across regions

- **Priority**: Medium
- **XState source**: `parallel.test.ts` lines 699-756 ("should handle simultaneous orthogonal transitions")
- **Type**: Feature test
- **Scenario**: Create a parallel machine with regions "editing" (targetless transition with context update on CHANGE) and "status" (transitions between saved/unsaved on SAVE/CHANGE). Send SAVE then CHANGE. Verify both regions process the CHANGE event: editing region updates context, status region transitions to unsaved.
- **Expected behavior**: A single event can trigger both a targetless transition (context update only) and a targeted transition in different parallel regions simultaneously.
- **Dedup check**: Grepped "simultaneous.*orthogonal", "context.*update.*parallel.*region" -- `ParallelAdvancedTest` tests "CHANGE event triggers transitions in BOTH regions" but focuses on both regions transitioning to new states, not one region doing context-only update while another transitions.
- **W1P1 overlap**: None

## Gap X8: Guard evaluation order: first-matching @always guard wins in array order

- **Priority**: High
- **XState source**: `transient.test.ts` lines 31-106 (three tests for first-candidate @always guard selection)
- **Type**: Feature test
- **Scenario**: Create a state with multiple @always transitions with guards. First guard returns false, second returns true. Verify the second target is reached. Then: both guards true, verify first target is reached.
- **Expected behavior**: @always transitions evaluate guards in array order. First guard returning true wins. If no guard matches, fall through to the unguarded fallback @always (if present).
- **Dedup check**: Grepped "always.*first.*candidate", "always.*guard.*order", "always.*fallback" -- no results. `ParallelAlwaysTransitionsTest` tests @always in parallel context. `AlwaysGuardMachine` exists but no test verifies guard evaluation order for @always transitions specifically.
- **W1P1 overlap**: W1P1 Gap 1 covers first-match guard for `on` events. This gap covers the same semantics for @always transitions specifically.

## Gap X9: @always loop terminates when context-changing always transitions reach a stable state

- **Priority**: Medium
- **XState source**: `transient.test.ts` lines 680-697 ("should loop but not infinitely for assign actions")
- **Type**: Feature test
- **Scenario**: Create a state with an @always transition that increments a counter via a calculator and has a guard `count < 5`. Verify the machine loops through the @always transition 5 times, incrementing the counter each time, then stops when the guard returns false.
- **Expected behavior**: @always transitions with context-changing actions and guards loop until the guard condition is no longer met. The machine settles at count=5.
- **Dedup check**: `MaxTransitionDepthTest` tests that infinite loops are caught, but doesn't test the happy-path where a context-changing @always loop terminates naturally.
- **W1P1 overlap**: W1P1 Gap 6 covers initial-state @always chain, not self-looping @always with context changes.

## Gap X10: Exit actions fire in reverse document order when parallel machine completes

- **Priority**: Medium
- **XState source**: `final.test.ts` lines 835-978 (4 tests for exit action ordering on parallel machine completion)
- **Type**: Feature test
- **Scenario**: Create a parallel machine with regions a and b. Both regions transition to final states. Verify exit actions fire in reverse document order: b's children exit first (reverse order), then a's children, then regions themselves.
- **Expected behavior**: When a parallel machine completes (all regions final), exit actions fire in reversed document order (last region's deepest child first, working up and backward to first region).
- **Dedup check**: `NestedParallelExitActionsTest` tests exit ordering when exiting a parallel state via an escape transition, but NOT exit ordering when the parallel state completes via @done (all regions reaching final).
- **W1P1 overlap**: W1P1 Gap 4 covers deep hierarchy exit ordering, not parallel completion exit ordering.

## Gap X11: Parallel state value representation includes all regions

- **Priority**: Low
- **XState source**: `parallel.test.ts` lines 562-619 (multiple tests for state value containing all regions)
- **Type**: Feature test
- **Scenario**: Create a parallel machine. Transition one region. Verify the state value still contains entries for ALL regions (the transitioned one at its new state, untouched ones at their initial state).
- **Expected behavior**: After transitioning one region, `state.value` contains the current state of every region, not just the transitioned one.
- **Dedup check**: `BasicParallelStatesTest` tests initial parallel state value and `ParallelStatesDocumentationTest` tests parallel value representation. Coverage exists but is indirect (via `matches()`). A direct test asserting the state value array contains all regions after partial transition is not explicit.
- **W1P1 overlap**: None, but partial coverage exists.

## Gap X12: Context assignment from event payload (calculator-like behavior)

- **Priority**: Low
- **XState source**: `assign.test.ts` lines 253-280 ("can assign from event")
- **Type**: Feature test
- **Scenario**: Create a machine where a transition's calculator reads a value from the event payload and sets it into context. Send an event with a specific value. Verify context is updated with the event's value.
- **Expected behavior**: Calculator can read event payload and set context values derived from the event.
- **Dedup check**: `CalculatorTest.php` tests calculators extensively. `BehaviorDependencyInjectionTest.php` tests event injection into behaviors. The specific pattern "calculator reads event payload to set context" is likely covered implicitly but not explicitly tested with this exact scenario.
- **W1P1 overlap**: None, but implicit coverage likely exists.

## Gap X13: Child machine delegation: child sends event to parent, parent transitions

- **Priority**: Medium
- **XState source**: `invoke.test.ts` lines 415-527 (parent-to-child and child-to-parent communication via sendParent)
- **Type**: Feature test
- **Scenario**: Parent invokes child machine. Parent sends event to child. Child processes it, reaches a state, and sends event back to parent. Parent transitions based on the received event.
- **Expected behavior**: Bidirectional parent-child communication works: parent sends event to child, child sends event back to parent, parent transitions.
- **Dedup check**: `MachineDelegationTest` tests child delegation extensively. `SendToTest` tests sendTo. `ProgressReportingTest` tests progress reporting (child->parent). The specific bidirectional roundtrip (parent sends to child, child sends back, parent transitions) may be partially covered in `MachineDelegationTest` but not as an explicit happy-path test.
- **W1P1 overlap**: None, but implicit coverage likely exists.

## Gap X14: Child machine invocation: child reaching final state triggers parent @done transition

- **Priority**: Medium
- **XState source**: `invoke.test.ts` lines 489-527 ("should transition correctly if child invocation causes it to directly go to final state")
- **Type**: Feature test
- **Scenario**: Parent state invokes child machine. Parent sends event to child causing it to reach final. Parent's @done handler transitions the parent to the next state.
- **Expected behavior**: When the invoked child reaches a final state, the parent automatically transitions via @done.
- **Dedup check**: `MachineDelegationTest` and `DoneFinalStateRoutingTest` cover this pattern extensively. This gap IS covered. **SKIP**.

## Gap X15: Parallel regions with invoked child machines: each region can invoke independently

- **Priority**: Medium
- **XState source**: `invoke.test.ts` lines 529-575 ("should work with invocations defined in orthogonal state nodes")
- **Type**: Feature test
- **Scenario**: A parallel machine has a region that invokes a child machine. The child completes with output. The region's @done uses a guard on the output to transition. Verify the parallel region correctly handles the child's completion.
- **Expected behavior**: Invocations in parallel regions work independently. A child machine completing in one region triggers that region's @done handler.
- **Dedup check**: `ParallelMachineDelegationTest` tests parallel machine delegation. This is likely covered. **SKIP**.

---

# Summary

| # | Gap Title | Priority | W1P1 Overlap |
|---|-----------|----------|--------------|
| X1 | Raised event cross-region in parallel (sync mode) | High | None |
| X2 | @always evaluated before raised events | High | None |
| X3 | @always carries actions from prior transition in macrostep | Medium | Partial (W1P1 #3) |
| X4 | Parallel @done fires exactly once on simultaneous completion | Medium | Partial (W1P1 #10) |
| X5 | Nested parallel-within-parallel @done cascade | High | None |
| X6 | Multi-level compound @done cascade (3 levels) | Medium | None |
| X7 | Simultaneous orthogonal transitions (context + state) | Medium | None |
| X8 | @always guard evaluation order (first-match wins) | High | Partial (W1P1 #1) |
| X9 | Context-changing @always loop terminates naturally | Medium | Partial (W1P1 #6) |
| X10 | Parallel completion exit actions in reverse document order | Medium | Partial (W1P1 #4) |
| X11 | Parallel state value includes all regions after partial transition | Low | None |
| X12 | Context assignment from event payload via calculator | Low | None |
| X13 | Bidirectional parent-child machine communication roundtrip | Medium | None |
| X14 | Child final state triggers parent @done | Medium | Covered -- SKIP |
| X15 | Parallel region with invoked child machine | Medium | Covered -- SKIP |

## Actionable Gaps (13 beads)

1. **Gap X1** -- Cross-region raised events in parallel sync mode (High)
2. **Gap X2** -- @always before raised events processing order (High)
3. **Gap X3** -- @always action accumulation across macrostep (Medium)
4. **Gap X4** -- Parallel @done fires exactly once (Medium)
5. **Gap X5** -- Nested parallel-within-parallel @done cascade (High)
6. **Gap X6** -- Multi-level compound @done cascade (Medium)
7. **Gap X7** -- Simultaneous orthogonal transitions (Medium)
8. **Gap X8** -- @always guard evaluation order (High)
9. **Gap X9** -- Context-changing @always loop terminates (Medium)
10. **Gap X10** -- Parallel completion exit ordering (Medium)
11. **Gap X11** -- Parallel state value after partial transition (Low)
12. **Gap X12** -- Context from event payload via calculator (Low)
13. **Gap X13** -- Bidirectional parent-child communication (Medium)
