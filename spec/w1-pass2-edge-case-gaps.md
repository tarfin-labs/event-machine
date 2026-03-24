# W1 Pass 2: Edge Case & Boundary Condition Gaps

> Theme: EDGE CASES & BOUNDARY CONDITIONS
> Lens: Off-by-one, empty, null, max depth, single-region parallel, extreme values.
> Generated: 2026-03-25

---

## Dedup Check Against Pass 1

Checked all 14 open Pass 1 beads (`bd list --status=open | grep ht-w1p1`):
- `ht-w1p1-action-ordering-scxml` -- happy path action ordering, not edge cases
- `ht-w1p1-child-over-parent-priority` -- happy path priority, not boundary
- `ht-w1p1-deep-hierarchy-ordering` -- happy path 3-level ordering, not edge boundary
- `ht-w1p1-guard-first-match` -- happy path first-match, not overlapping edge
- `ht-w1p1-guard-no-side-effects` -- happy path purity check, not edge
- `ht-w1p1-lca-sibling` -- happy path LCA, not deep edge
- `ht-w1p1-self-transition-resets-child` -- happy path reset, not boundary
- `ht-w1p1-context-roundtrip-fidelity` -- happy path types, not extreme values
- `ht-w1p1-incremental-context-diff` -- happy path diffs, not edge cases
- `ht-w1p1-initial-always-chain` -- happy path chain, not empty guard edge
- `ht-w1p1-parallel-done-all-final` -- happy path done detection, not single-region
- `ht-w1p1-self-transition-exit-entry` -- happy path self-transition, not edge
- `ht-w1p1-targetless-no-exit-entry` -- happy path targetless, not edge
- `ht-w1p1-cache-clear-commands` -- happy path commands, not edge

None of these overlap with the edge-case gaps identified below.

---

## Gap 1: Single-region parallel state (degenerate case)

- **Priority**: Medium
- **Source**: Problem #3.5, #3.2
- **Type**: Feature
- **Scenario**: Define a parallel state with exactly ONE region. The region has states initial -> final. Transition the region to final. Verify @done fires correctly. This tests the degenerate case where "all regions final" means "one region final."
- **Expected behavior**: Parallel state with one region behaves correctly. @done fires when the single region reaches final. No errors from parallel machinery expecting 2+ regions.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "single.*region.*parallel", "one.*region", "degenerate" in tests/ -- found `ParallelStatesDocumentationTest.php` line 111 "single region handling" but that test actually has TWO regions. `BasicParallelStatesTest.php` tests zero regions (must have at least one). `ParallelFinalStatesTest.php` line 845 tests "region where initial state is immediately final" but that's a degenerate region, not a single-region parallel. No test for a parallel state with exactly one region.
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

## Gap 2: @always transition with all guards false and no default target

- **Priority**: High
- **Source**: Problem #1.7
- **Type**: Feature
- **Scenario**: Define a state with multiple guarded @always transitions where ALL guards return false, and there is NO unguarded default fallback. Verify the machine stays in the current state (no transition fires) rather than looping or throwing.
- **Expected behavior**: When all @always guards are false and there is no unguarded fallback, the machine should remain in the current state. No infinite loop, no exception. The state becomes stable.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "all.*guards.*false", "no.*guard.*true" in tests/ -- found `ConditionalOnDoneTest` line 160 "aborts @done when all guards fail" and `ConditionalOnFailTest` line 160 "aborts @fail when all guards fail", and `ActionsTest.php` line 336 "prevent infinite loops when no guards evaluate to true for @always". The `ActionsTest` test covers the exact scenario of all @always guards false. However, it uses `max_transition_depth` protection. There is NO test verifying the machine gracefully stays in the state WITHOUT hitting depth limit when guards are properly exclusive. This is a distinct edge case: @always with ALL guarded branches and no fallback, where guards are false on first check (no loop needed).
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

## Gap 3: Compound state with only one child state

- **Priority**: Medium
- **Source**: Problem #2.4
- **Type**: Feature
- **Scenario**: Define a compound state with exactly one child state. That child is the initial state. Verify the machine enters the compound state and correctly settles into the single child. Test that @done works if the single child is final.
- **Expected behavior**: A compound state with a single child works correctly. If the child is final, @done fires. No errors from compound state machinery expecting 2+ children.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "compound.*one.*child", "one.*child.*compound", "single.*child" in tests/ -- not found. No test for compound state with exactly one child state.
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

## Gap 4: Context extreme values (PHP_INT_MAX, deep nesting, float precision)

- **Priority**: Medium
- **Source**: Problem #6.2
- **Type**: Feature
- **Scenario**: Create a machine with context containing extreme values: PHP_INT_MAX, PHP_INT_MIN, very large floats (1e308), very small floats (1e-308), PHP_FLOAT_EPSILON, deeply nested arrays (10+ levels), very long strings (100KB). Persist and restore. Verify all values survive the round-trip.
- **Expected behavior**: PHP_INT_MAX stays as integer (not float overflow). Float precision is maintained within PHP's JSON encoding limits. Deep nesting survives. Long strings are not truncated.
- **Stub machine needed**: No (inline definition)
- **Dedup check**: Grepped for "PHP_INT_MAX", "PHP_FLOAT", "extreme", "boundary" in tests/ -- not found in context/serialization tests. `ContextRoundTripTest.php` exists but tests only basic types (int 42, float 3.14, etc). `ArchiveEdgeCasesTest.php` tests unicode and compression boundaries but not context extreme values. Pass 1 Gap 12 (`ht-w1p1-context-roundtrip-fidelity`) covers basic type fidelity, NOT extreme values.
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

## Gap 5: Event with empty payload sent to machine

- **Priority**: Medium
- **Source**: Problem #4.2, #6.2
- **Type**: Feature
- **Scenario**: Send an event with an empty payload (`[]`) and also with a null payload to a machine. Verify the machine handles both correctly: the transition fires, actions can read the payload without errors, and context is not corrupted.
- **Expected behavior**: Events with empty or null payloads are handled gracefully. Actions that access `$event->payload` receive an empty array or null. No TypeError or undefined index.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "empty.*payload", "payload.*empty", "null.*payload" in tests/ -- found `EventTest.php` line 33 "all method returns empty array when payload is null" and `ForwardEndpointEdgeCasesTest.php` line 435 "child event without rules accepts empty payload". The EventTest covers the EventBehavior::all() edge case. But no test verifies Machine::send() with empty/null payload through a full transition cycle (entry actions, guards, context update). The existing tests are unit-level, not integration through the machine.
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

## Gap 6: Machine with only a single state (no transitions possible)

- **Priority**: Low
- **Source**: Problem #1.5
- **Type**: Feature
- **Scenario**: Define a machine with exactly one state: a final state that is also the initial state. Verify the machine starts, immediately finishes (MACHINE_FINISH fires), and any attempt to send an event throws the appropriate exception.
- **Expected behavior**: Machine starts at the single final state. MACHINE_FINISH event fires. Sending any event to a finished machine throws NoTransitionDefinitionFoundException.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "single.*state", "one.*state.*machine", "initial.*final" in tests/ -- found `StateDefinitionTest.php` line 602 "initial state of type final triggers machine finish" and `RootEntryExitTest.php` line 88 "runs root exit before MACHINE_FINISH when initial state is final". These test that MACHINE_FINISH fires for initial=final. But no test verifies sending an event to a machine at a final state throws correctly. `ResultBehaviorTriggeringEventTest.php` line 67 tests `Machine::result()` with initial final state but not `Machine::send()`.
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

## Gap 7: Transition to same state with no actions defined (no-op self-transition)

- **Priority**: Low
- **Source**: Problem #1.4
- **Type**: Feature
- **Scenario**: Define a state with a self-transition (target = same state) that has NO actions, NO entry actions, NO exit actions. Send the event. Verify: (1) the transition fires successfully, (2) the state value remains unchanged, (3) no errors occur from empty action lists, (4) the event appears in history.
- **Expected behavior**: The self-transition completes silently. State remains the same. History records the transition event. No errors from null/empty action processing.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "self.*no.*action", "same.*state.*no.*action", "no-op.*transition" in tests/ -- not found. Pass 1 has `ht-w1p1-self-transition-exit-entry` which tests that exit/entry DO fire on self-transition with actions. No test for the degenerate case of self-transition with zero actions.
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

## Gap 8: max_transition_depth set to 1 (minimum useful value)

- **Priority**: Low
- **Source**: Problem #1.7
- **Type**: Feature
- **Scenario**: Set `max_transition_depth` to 1. Define a machine where the initial state has a simple transition to a second state (no @always). Verify the normal event-driven transition works (depth=1 should allow one transition step). Then define a machine with initial -> @always -> second state. Verify it throws (one @always step already uses depth 1).
- **Expected behavior**: With depth=1, a single event-driven transition works. A single @always chain step exceeds the limit and throws MaxTransitionDepthExceededException. The value 0 is already tested (clamped to 1).
- **Stub machine needed**: No (inline)
- **Dedup check**: Grepped for "max_transition_depth.*=.*1" in tests/ -- not found directly. `MaxTransitionDepthTest.php` tests depth=0 (clamped), depth=5, depth=10, depth=100. Does NOT test depth=1 explicitly as a boundary. The "zero clamped to 1" test verifies depth=0 becomes 1, but the behavior of explicitly setting depth=1 with event-driven transitions (not @always) is not tested.
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

## Gap 9: Negative max_transition_depth config value

- **Priority**: Low
- **Source**: Problem #1.7
- **Type**: Feature
- **Scenario**: Set `max_transition_depth` to a negative value (-1). Verify the system handles it gracefully: either clamps to a minimum (like 0 is clamped to 1) or throws a configuration error.
- **Expected behavior**: Negative depth values are handled safely -- either clamped to minimum or rejected with a clear error.
- **Stub machine needed**: No (inline)
- **Dedup check**: Grepped for "negative.*depth", "depth.*negative", "max_transition_depth.*-" in tests/ -- not found. Only depth=0, depth=5, depth=10, depth=100 are tested.
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

## Gap 10: Guard receiving null/missing context key it depends on

- **Priority**: Medium
- **Source**: Problem #1.2, #6.2
- **Type**: Feature
- **Scenario**: Define a guard that reads a context key that is null or was never set in the initial context. Verify the guard does not throw a TypeError but handles the null gracefully (returns false or a predictable result).
- **Expected behavior**: A guard that reads `$context->get('nonexistent_key')` receives null. The guard should not throw. If it returns false (due to null comparison), the next transition branch should be evaluated. No uncaught TypeError.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "guard.*null", "guard.*missing.*context", "context.*get.*null" in tests/ -- found `TestMachineV2Test.php` line 972 "fakingAllGuards spy returns null -- guard fails by default" but this tests faked guards, not real guards reading null context. No test verifies a real guard receiving null from `$context->get()` for a missing key.
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

## Gap 11: Parallel region where initial state IS final (immediate @done)

- **Priority**: Medium
- **Source**: Problem #3.5
- **Type**: Feature
- **Scenario**: Define a parallel state where ALL regions have their initial state as final. Verify @done fires immediately upon entering the parallel state (no events needed).
- **Expected behavior**: Machine enters parallel state, all regions are already at final, @done fires in the same macrostep, machine transitions to @done target.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "initial.*final", "initial.*is.*final", "immediately.*final" in tests/ -- found `ParallelFinalStatesTest.php` line 844 "region where initial state is immediately final triggers parallel onDone" which tests ONE region with initial=final and another manual region. Also found `E2EParallelDispatchE2ETest.php` line 728 "handles region initial state that is also final". However, no test verifies ALL regions having initial=final (immediate complete parallel state). The existing tests have mixed regions (one immediate, one requiring events).
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

## Gap 12: LCA calculation for child-to-ancestor transition

- **Priority**: Medium
- **Source**: Problem #2.2
- **Type**: Feature
- **Scenario**: Create a hierarchy: root > A > A1 > A1a. From A1a, transition directly to A (ancestor). Verify the correct exit sequence: exit A1a, exit A1 (but NOT exit A since it's the target). Verify A's entry action fires (since we're re-entering it via external transition).
- **Expected behavior**: Transitioning from a deeply nested child to an ancestor follows SCXML LCA rules. The ancestor's exit/entry behavior depends on whether it's an external or internal transition.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "child.*ancestor", "ancestor.*transition", "transition.*parent.*state" in tests/ -- not found as a dedicated edge case. Pass 1 has `ht-w1p1-lca-sibling` which tests sibling transitions only. No test for child-to-ancestor transitions which have different LCA semantics.
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

## Gap 13: availableEvents() on state with no transitions defined

- **Priority**: Low
- **Source**: Problem #1.5
- **Type**: Feature
- **Scenario**: Create a machine where a state has zero transitions defined (no `on` key). Call `availableEvents()` while in that state. Verify it returns an empty array without errors.
- **Expected behavior**: `availableEvents()` returns `[]` when the current state has no transitions. No null pointer or undefined property error.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "availableEvents.*empty", "no.*transition.*available", "availableEvents.*\\[\\]" in tests/ -- `ForwardEndpointEdgeCasesTest.php` line 247 tests "available_events on state with only @always guarded returns empty array", but that's specifically about @always states. No test for a plain state with literally zero `on` transitions. `AvailableEventsTest.php` and `AvailableEventsTestMachineTest.php` test states that DO have events defined.
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

## Gap 14: @always transition with empty guards array ([] instead of guard name)

- **Priority**: Medium
- **Source**: Problem #1.7
- **Type**: Feature
- **Scenario**: Define an @always transition with `guards => []` (empty array, not null/missing). Verify the system treats this as an unguarded transition (always true) and does not error on empty guard evaluation.
- **Expected behavior**: An @always transition with `guards => []` behaves like an unguarded @always transition -- it fires unconditionally. No error from attempting to evaluate an empty guard list.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "guards.*\\[\\]", "empty.*guard.*array", "guard.*empty" in tests/ -- `StateConfigValidatorTest.php` line 340 tests "empty array is treated as targetless transition, not empty guarded transition" which is about the top-level event config, not about the `guards` key being an empty array within a transition branch. No test for `['target' => 'x', 'guards' => []]`.
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

## Gap 15: Sending event to machine at final state

- **Priority**: Medium
- **Source**: Problem #4.2, #1.5
- **Type**: Feature
- **Scenario**: Create a machine, transition it to a final state. Then attempt to send another event. Verify the machine rejects the event with a clear exception (NoTransitionDefinitionFoundException) and the machine state is not corrupted.
- **Expected behavior**: Sending an event to a machine at a final state throws NoTransitionDefinitionFoundException. Machine state remains at the final state. Event history is not corrupted.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "send.*final", "send.*after.*final", "event.*finished.*machine", "final.*state.*reject" in tests/ -- not found as a dedicated test. `TransitionDefinitionTest.php` tests unknown events/states but not the specific scenario of sending to a final state. `ResultBehaviorTriggeringEventTest.php` tests result on final state but not send().
- **Workflow**: Use /agentic-commits. Run composer quality. Use /beads-from-plan for child beads.

---

# Summary

| # | Gap Title | Priority | Dedup Status |
|---|-----------|----------|--------------|
| 1 | Single-region parallel state (@done fires) | Medium | Not covered |
| 2 | @always with all guards false, no default (stays in state) | High | Partially covered (MaxTransitionDepth only) |
| 3 | Compound state with one child state | Medium | Not covered |
| 4 | Context extreme values (PHP_INT_MAX, deep nesting, float precision) | Medium | Not covered |
| 5 | Event with empty/null payload through full transition | Medium | Not covered (unit-level only) |
| 6 | Machine with single final state (send throws) | Low | Partially covered |
| 7 | No-op self-transition (no actions defined) | Low | Not covered |
| 8 | max_transition_depth = 1 (boundary) | Low | Not covered |
| 9 | Negative max_transition_depth config | Low | Not covered |
| 10 | Guard receiving null context key | Medium | Not covered |
| 11 | All-immediate parallel regions (all initial=final) | Medium | Not covered |
| 12 | LCA for child-to-ancestor transition | Medium | Not covered |
| 13 | availableEvents() on state with no transitions | Low | Not covered |
| 14 | @always with empty guards array ([]) | Medium | Not covered |
| 15 | Sending event to machine at final state | Medium | Not covered |

## Actionable Gaps (15 beads)

All 15 gaps above require new test beads. No duplicates with Pass 1.
