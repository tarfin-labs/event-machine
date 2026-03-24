# W1 Pass 1: Happy-Path Test Gaps

> Theme: HAPPY PATH / SEMANTIC CORRECTNESS
> Lens: Basic behavior that should work but isn't tested.
> Generated: 2026-03-25

---

## Gap 1: First-match guard wins in document order

- **Priority**: High
- **Source**: Problem #1.1 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Define a machine with two guarded transitions on the same event from the same state, where both guards return true. Verify the first transition in array order is selected.
- **Expected behavior**: When both guards are satisfiable, the first transition defined in the config array fires. The machine transitions to the first target, not the second.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "first.*guard.*wins", "guard.*order", "overlapping.*guard" in tests/ -- found `ConditionalOnDoneTest` and `ConditionalOnFailTest` testing first-match for @done/@fail guards, and `CalculatorsWithGuardedTransitions` testing calculator execution order. No test for basic `on` event with two simultaneously-true guards verifying first-match semantics.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 2: Guard evaluation does not mutate context when guard returns false

- **Priority**: High
- **Source**: Problem #1.2 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Define a machine with multiple guarded transitions. The first guard returns false. Verify context is unchanged after the losing guard's evaluation. Then verify the winning guard's transition fires correctly.
- **Expected behavior**: Context is identical before and after a guard that returns false is evaluated. Only the winning transition's actions modify context.
- **Stub machine needed**: No (inline TestMachine::define with inline guards)
- **Dedup check**: Grepped for "guard.*context.*unchanged", "guard.*no.*side.effect", "guard.*pure", "guard.*mutation" in tests/ -- not found.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 3: Exit/entry/transition action ordering (SCXML compliance)

- **Priority**: High
- **Source**: Problem #1.3 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a machine with states A and B. A has an exit action, the A->B transition has a transition action, B has an entry action. All actions append to a shared log array. Verify the execution order is: exit A, transition action, entry B.
- **Expected behavior**: Log array equals `['exit:A', 'transition:A->B', 'entry:B']` in that exact order.
- **Stub machine needed**: No (inline TestMachine::define with logging actions)
- **Dedup check**: Grepped for "action.*order", "exit.*entry.*order", "action.*sequence", "execution.*order" in tests/ -- found `EventProcessingOrderTest.php` which tests entry-before-raise ordering, but does NOT test exit-transition-entry ordering for a simple A->B transition. `RootEntryExitTest.php` tests root-level entry/exit, not transition-level ordering.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 4: Entry/exit action ordering in deep (3+ level) hierarchy

- **Priority**: High
- **Source**: Problem #2.1 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a 3-level deep hierarchy: root > A > A1 > A1a, and root > B. Transition from A.A1.A1a to B. Record exit/entry actions at every level. Verify SCXML ordering: exit A1a, exit A1, exit A, enter B.
- **Expected behavior**: Log array equals `['exit:A1a', 'exit:A1', 'exit:A', 'entry:B']` in that exact order. Parent states' exit runs bottom-up, entry runs top-down.
- **Stub machine needed**: No (inline TestMachine::define with deep nesting)
- **Dedup check**: Grepped for "RootEntryExit", "hierarchical.*entry", "nested.*entry.*exit" in tests/ -- found `RootEntryExitTest.php` and `RootEntryExitInternalEventsTest.php` but they only test root-level entry/exit, not multi-level hierarchical ordering. `HierarchyTest.php` tests state definition lookups, not action ordering.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 5: LCA calculation -- sibling transition does NOT exit/enter parent

- **Priority**: High
- **Source**: Problem #2.2 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a compound state P with children A and B. P has entry/exit actions. Transition from P.A to P.B. Verify P's exit and entry actions do NOT fire (since P is the LCA and should not be exited/entered for a sibling transition).
- **Expected behavior**: Only A's exit and B's entry fire. P's exit/entry do NOT fire.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "LCA", "least.common.ancestor", "sibling.*transition", "cousin.*transition" in tests/ -- not found (only calculator-related false matches).
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 6: Initial state with @always chain completes in one macrostep

- **Priority**: Medium
- **Source**: Problem #2.4 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a compound state where the initial child has an @always transition with a guard that evaluates to true, which chains to a second state also with @always. Verify the machine reaches the final stable state in one macrostep, and entry actions at each intermediate state fire.
- **Expected behavior**: Machine ends at the final state of the @always chain. All intermediate entry actions execute. The machine never "rests" at the intermediate @always states.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "initial.*always", "always.*initial", "compound.*always" in tests/ -- found `TransitionDefinitionTest` with "always transitions with initial jump" and `AlwaysEventPreservation/InitAlwaysMachine`. The `InitAlwaysMachine` tests initial-with-always but existing tests focus on event preservation, not the basic happy-path semantics of multi-step @always chains from initial state. Partial coverage exists.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 7: Child state has priority over parent state for same event

- **Priority**: High
- **Source**: Problem #2.5 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a compound state P with child C. Both P and C handle the same event E. When the machine is in P.C and receives E, the child's transition fires (not the parent's). When the machine is in P.D (a sibling that does NOT handle E), the parent's transition fires as fallback.
- **Expected behavior**: Child-defined transition wins over parent-defined transition for the same event. Parent's transition acts as fallback only when the active child doesn't handle the event.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "child.*priority", "parent.*fallback", "parent.*child.*same.*event", "event.*resolution.*hierarch", "child.*state.*handles" in tests/ -- not found. `EventResolutionTest.php` tests event class resolution, not hierarchical transition priority.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 8: Self-transition triggers exit/entry actions (external self-transition)

- **Priority**: Medium
- **Source**: Problem #1.4 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a state with an entry action and an exit action. Define an external self-transition (target is the same state). Send the event. Verify exit and entry actions both fire.
- **Expected behavior**: Exit action fires, then entry action fires. The state value remains the same, but the entry/exit side effects execute.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "self.transition", "re-enter", "reenter" in tests/ -- found `ListenTest` line 415 "fires all three listeners on self-transition" which tests listeners, not entry/exit actions directly. `ParallelDispatchXStateTest` line 213 "Self-transition: exit then entry" tests in parallel context. No basic flat-state self-transition entry/exit action test.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 9: Targetless (internal) transition does NOT trigger exit/entry actions

- **Priority**: Medium
- **Source**: Problem #1.4 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a state with entry/exit actions and a targetless transition that runs an action. Send the event. Verify the transition action runs but exit/entry actions do NOT fire.
- **Expected behavior**: Only the transition's inline action runs. Exit and entry actions are NOT triggered. The state remains unchanged.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "targetless" in tests/ -- found `TargetlessTransitionTest.php` which tests that the state doesn't change, but does NOT verify that exit/entry actions are skipped. Tests only check `assertState('idle')`, not action non-execution.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 10: Parallel state @done fires when ALL regions reach final (basic happy path)

- **Priority**: Medium
- **Source**: Problem #3.5 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a simple parallel state with two regions, each with 2 states (initial -> final). Send events to transition each region to its final state one at a time. Verify @done fires only after the second region reaches final, not the first.
- **Expected behavior**: After first region reaches final, machine stays in parallel state. After second region reaches final, @done fires and machine transitions to the @done target.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "areAllRegionsFinal" in tests/ -- found multiple tests in `ParallelDispatchApiSurfaceTest`, `ParallelFinalStatesTest`, etc. These test the API method but focus on nested final states and dispatch mode. A basic sync-mode happy-path test that explicitly verifies @done does NOT fire after only one region is final, then DOES fire after both, is not clearly present. `BasicParallelStatesTest.php` may cover this partially. Partial coverage.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 11: Event broadcasting to all parallel regions (sync mode)

- **Priority**: Medium
- **Source**: Problem #3.4 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a parallel state with two regions. Both regions handle the same event type. Send the event once. Verify both regions transition.
- **Expected behavior**: A single event triggers transitions in ALL regions that handle it, not just the first matching region.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "broadcast.*event.*parallel", "event.*both.*region", "both.*region.*transition" in tests/ -- found `ParallelAdvancedTest` line 74 "CHANGE event should trigger transitions in BOTH regions", `ParallelStatesDocumentationTest` line 152 "same event triggers both regions", `ParallelDispatchXStateTest` line 46 "Single GO event transitions BOTH regions". This gap IS covered. Skipping.

## Gap 12: Context round-trip fidelity through persist/restore

- **Priority**: Medium
- **Source**: Problem #6.1 and #6.2 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a machine with context containing various data types: integers, floats, strings, booleans, null, nested arrays. Persist the machine. Restore it. Verify the restored context matches the original exactly (type-level equality, not just value equality).
- **Expected behavior**: All context values survive the persist/restore round-trip with correct types. Integers remain integers (not strings). Nulls remain nulls. Nested arrays preserve structure.
- **Stub machine needed**: No (inline definition)
- **Dedup check**: Grepped for "context.*serial", "serializ", "round.trip", "context.*restore" in tests/ -- found `SerializationTest.php` (tests Machine JSON serialization, not context data type fidelity) and `PersistenceTest.php` (tests basic persist/restore but uses simple TrafficLights context, not comprehensive data types).
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 13: Calculator runs before guard in same transition

- **Priority**: Medium
- **Source**: Problem #1.3 (action execution order) from hardened-testing-research.md, and architecture docs
- **Type**: Feature test
- **Scenario**: Define a transition with both a calculator and a guard. The calculator sets a context value. The guard reads that value. Verify the calculator runs first, making the guard's context check succeed.
- **Expected behavior**: Calculator executes before guard. Guard sees the value set by the calculator.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "calculator.*before.*guard", "calculator.*guard", "pre.compute" in tests/ -- found `CalculatorsWithGuardedTransitions.php` which tests calculator execution when guards fail, and `CalculatorTest.php`. However, the specific happy-path test -- calculator sets context, guard uses that context, transition succeeds -- is tested indirectly. The `CalculatorsWithGuardedTransitions.php` file does cover calculator-before-guard ordering. Partial coverage, close enough to skip.

## Gap 14: Compound state @done fires when child reaches final state

- **Priority**: Medium
- **Source**: Problem #3.5 (done detection) from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a compound (non-parallel) state with children: processing -> done (final). When the child reaches the final state, the compound state's @done transition should fire, moving the machine to the next top-level state.
- **Expected behavior**: When the active child of a compound state reaches a final state, @done fires automatically, transitioning the parent out of the compound state.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "compound.*@done", "@done.*compound", "processCompoundOnDone" in tests/ -- found `CompoundOnDoneEventQueueTest.php` which tests compound @done with raise() and @always chains. This gap IS covered by existing tests. Skipping.

## Gap 15: sendTo delivers event to another machine instance (basic happy path)

- **Priority**: Medium
- **Source**: Problem #5.2 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create two machine instances (Machine A and Machine B). Machine A's action uses sendTo to send an event to Machine B. Verify Machine B receives the event and transitions.
- **Expected behavior**: Machine B transitions to the expected state after receiving the event from Machine A via sendTo.
- **Stub machine needed**: No (uses existing stubs)
- **Dedup check**: Grepped for "sendTo" in tests/ -- found `SendToTest.php` with "sends event to a target machine synchronously via sendTo". This gap IS covered. Skipping.

## Gap 16: Machine identity -- machineId() and parentMachineId() accessible in context

- **Priority**: Low
- **Source**: Architecture docs (Machine identity section)
- **Type**: Feature test
- **Scenario**: Create a machine and verify that `$context->machineId()` returns the correct machine class name, and that `$context->parentMachineId()` returns null for a top-level machine.
- **Expected behavior**: `machineId()` returns the FQCN of the machine class. `parentMachineId()` returns null when no parent exists.
- **Stub machine needed**: No
- **Dedup check**: Grepped for "machineId", "parentMachineId" in tests/ -- found `MachineIdentityRestoreTest.php` and `LocalQA/MachineIdentityTest.php`. This gap IS covered. Skipping.

## Gap 17: @done.{finalState} routes parent based on child's final state

- **Priority**: Medium
- **Source**: Architecture docs and Problem #3.5 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Parent machine delegates to a child that has multiple final states. Depending on which final state the child reaches, the parent should route to different states via @done.{finalState}.
- **Expected behavior**: If child reaches `approved`, parent follows `@done.approved` route. If child reaches `rejected`, parent follows `@done.rejected` route.
- **Stub machine needed**: Uses existing `MultiOutcomeChildMachine` and `DoneDotParentMachine`
- **Dedup check**: Grepped for "@done.\\{", "done\\..*state" in tests/ -- found `MachineDelegationTest.php` and `DoneFinalStateRoutingTest.php`. This gap IS covered. Skipping.

## Gap 18: Listener fires on state entry (basic happy path)

- **Priority**: Low
- **Source**: Architecture docs (Listener System)
- **Type**: Feature test
- **Scenario**: Define a machine with a `listen` config on a state, with an `entry` listener. Enter that state. Verify the listener is called.
- **Expected behavior**: The entry listener fires when the state is entered.
- **Stub machine needed**: No
- **Dedup check**: Grepped for "listen.*entry", "listener.*fire" in tests/ -- found `ListenTest.php` and `ListenChildDelegationTest.php`. This gap IS covered. Skipping.

## Gap 19: EventBuilder creates events with correct payload for testing

- **Priority**: Low
- **Source**: Architecture docs (Testing Infrastructure)
- **Type**: Feature test
- **Scenario**: Use an EventBuilder subclass to create a test event with specific payload values. Verify the event has the correct type and payload.
- **Expected behavior**: EventBuilder produces correctly typed events usable in Machine::send().
- **Stub machine needed**: No
- **Dedup check**: Grepped for "EventBuilder" in tests/ -- found `EventBuilderTest.php`. This gap IS covered. Skipping.

## Gap 20: Machine::test() and startingAt() fluent API basic happy path

- **Priority**: Low
- **Source**: Architecture docs (Testing Infrastructure)
- **Type**: Feature test
- **Scenario**: Use `MyMachine::test()` to create a test machine, send events, and assert state. Use `MyMachine::startingAt('some_state')` to start at a specific state and verify assertions work.
- **Expected behavior**: Fluent API works as documented. Assertions pass for correct states and contexts.
- **Stub machine needed**: No
- **Dedup check**: Grepped for "startingAt", "Machine::test()" in tests/ -- found `TestMachineV2Test.php`, `TestMachineTest.php`, `MachineCreateFakeTest.php`. This gap IS covered. Skipping.

## Gap 21: Max transition depth protects against @always infinite loop

- **Priority**: Medium
- **Source**: Problem #1.7 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a machine with @always transitions that form a cycle (A -> B -> A) with no guard returning true to break out. Verify the max_transition_depth limit fires and a clear exception is thrown.
- **Expected behavior**: `MaxTransitionDepthExceededException` is thrown with a message identifying the loop.
- **Stub machine needed**: No
- **Dedup check**: Grepped for "MaxTransitionDepth" in tests/ -- found `MaxTransitionDepthTest.php` with extensive coverage. This gap IS covered. Skipping.

## Gap 22: ValidationGuardBehavior throws MachineValidationException (basic happy path)

- **Priority**: Low
- **Source**: Architecture docs (Parallel States section)
- **Type**: Feature test
- **Scenario**: Define a machine with a `ValidationGuardBehavior` on a transition. Send an event that fails validation. Verify `MachineValidationException` is thrown and the machine stays in its current state.
- **Expected behavior**: Machine does not transition. `MachineValidationException` is thrown with validation error details.
- **Stub machine needed**: No
- **Dedup check**: Grepped for "ValidationGuard", "MachineValidationException" in tests/ -- found `ParallelValidationGuardTest.php`, `ActionsTest.php`, `MachineControllerTest.php`. This gap IS covered. Skipping.

## Gap 23: Machine::fake() prevents actual machine execution

- **Priority**: Low
- **Source**: Architecture docs (Testing Infrastructure)
- **Type**: Feature test
- **Scenario**: Call `Machine::fake()` on a machine class. Attempt to create or interact with the machine. Verify no actual state machine logic runs.
- **Expected behavior**: Faked machines return predetermined results without executing actions, guards, or transitions.
- **Stub machine needed**: No
- **Dedup check**: Grepped for "Machine::fake", "MachineFaking" in tests/ -- found `MachineFakingTest.php`, `MachineCreateFakeTest.php`, `MachineFakeWithClosureTest.php`. This gap IS covered. Skipping.

## Gap 24: Self-transition on compound state resets child to initial

- **Priority**: High
- **Source**: Problem #1.4 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a compound state P with children: initial A, and B. Transition to P.B via an event. Then trigger an external self-transition on P (target = P). Verify the machine resets to P.A (the initial child), not staying at P.B.
- **Expected behavior**: After external self-transition, the compound state re-enters from its initial child. The machine is at P.A, not P.B.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "self.transition", "child.*reset", "reset.*child" in tests/ -- found listener self-transition test and parallel self-transition, but no test verifying child state reset on external self-transition of a compound state.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 25: Available events reflect current state correctly

- **Priority**: Low
- **Source**: Architecture docs (Machine.availableEvents())
- **Type**: Feature test
- **Scenario**: Create a machine in state A. Call `availableEvents()`. Verify it returns only events that A handles. Transition to state B. Call `availableEvents()` again. Verify it returns B's events.
- **Expected behavior**: `availableEvents()` returns the event types valid from the current state, and updates as the machine transitions.
- **Stub machine needed**: No
- **Dedup check**: Grepped for "availableEvents" in tests/ -- found `AvailableEventsTest.php` and `AvailableEventsTestMachineTest.php`. This gap IS covered. Skipping.

## Gap 26: CommunicationRecorder records sendTo and raise calls

- **Priority**: Medium
- **Source**: Architecture docs (Testing Infrastructure)
- **Type**: Feature test
- **Scenario**: Use `CommunicationRecorder` to record sendTo and raise calls during a machine test. Verify the recorder captures the correct target machine, event type, and payload for each call.
- **Expected behavior**: `CommunicationRecorder` captures all sendTo/raise calls with full details, allowing assertions on cross-machine communication without side effects.
- **Stub machine needed**: No
- **Dedup check**: Grepped for "CommunicationRecorder" in tests/ -- found `TestMachineV2Test.php` and `MachineCreateFakeTest.php`. These test CommunicationRecorder, but checking scope: `TestMachineV2Test.php` uses it extensively. This gap IS covered. Skipping.

## Gap 27: Machine discovery cache and clear commands (basic happy path)

- **Priority**: Low
- **Source**: Architecture docs (Artisan Commands)
- **Type**: Feature test
- **Scenario**: Run `machine:cache` command to cache machine class discovery. Verify the cache file is created. Run `machine:clear` to clear it. Verify the cache file is removed.
- **Expected behavior**: `machine:cache` creates a discovery cache. `machine:clear` removes it. Both commands exit with success status.
- **Stub machine needed**: No
- **Dedup check**: Grepped for "machine:cache", "machine:clear", "MachineDiscovery" in tests/ -- not found. No test file exists for these commands.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 28: Incremental context diff applied correctly on restore

- **Priority**: Medium
- **Source**: Problem #6.1 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a machine. Send multiple events that each modify different context keys (event 1 sets key_a, event 2 sets key_b, event 3 modifies key_a). Persist and restore. Verify the restored context has all changes applied in correct order: key_a has the value from event 3, key_b has the value from event 2.
- **Expected behavior**: Incremental context diffs are applied in chronological order. The final context after restore matches the context before persist, including all intermediate modifications.
- **Stub machine needed**: No (inline definition or use TrafficLights)
- **Dedup check**: Grepped for "persist", "restore.*state" in tests/Integration -- found `PersistenceTest.php` which tests basic persist/restore but uses TrafficLightsMachine with a simple counter. Does not verify incremental diffs across multiple context keys being applied in order.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

---

# Summary

| # | Gap Title | Priority | Covered? |
|---|-----------|----------|----------|
| 1 | First-match guard wins in document order | High | Not covered |
| 2 | Guard evaluation does not mutate context when guard returns false | High | Not covered |
| 3 | Exit/entry/transition action ordering (SCXML compliance) | High | Not covered |
| 4 | Entry/exit action ordering in deep (3+ level) hierarchy | High | Not covered |
| 5 | LCA calculation -- sibling transition does NOT exit/enter parent | High | Not covered |
| 6 | Initial state with @always chain completes in one macrostep | Medium | Partial |
| 7 | Child state has priority over parent state for same event | High | Not covered |
| 8 | Self-transition triggers exit/entry actions | Medium | Not covered |
| 9 | Targetless (internal) transition does NOT trigger exit/entry | Medium | Not covered |
| 10 | Parallel @done fires only when ALL regions final | Medium | Partial |
| 11 | Event broadcasting to all parallel regions | Medium | Covered -- SKIP |
| 12 | Context round-trip fidelity through persist/restore | Medium | Not covered |
| 13 | Calculator runs before guard in same transition | Medium | Covered -- SKIP |
| 14 | Compound state @done fires when child reaches final | Medium | Covered -- SKIP |
| 15 | sendTo delivers event to another machine | Medium | Covered -- SKIP |
| 16 | Machine identity accessible in context | Low | Covered -- SKIP |
| 17 | @done.{finalState} routes parent correctly | Medium | Covered -- SKIP |
| 18 | Listener fires on state entry | Low | Covered -- SKIP |
| 19 | EventBuilder creates events correctly | Low | Covered -- SKIP |
| 20 | Machine::test() and startingAt() fluent API | Low | Covered -- SKIP |
| 21 | Max transition depth protects against loops | Medium | Covered -- SKIP |
| 22 | ValidationGuardBehavior throws exception | Low | Covered -- SKIP |
| 23 | Machine::fake() prevents execution | Low | Covered -- SKIP |
| 24 | Self-transition on compound state resets child to initial | High | Not covered |
| 25 | Available events reflect current state | Low | Covered -- SKIP |
| 26 | CommunicationRecorder records calls | Medium | Covered -- SKIP |
| 27 | Machine discovery cache/clear commands | Low | Not covered |
| 28 | Incremental context diff applied correctly on restore | Medium | Not covered |

## Actionable Gaps (13 beads)

The following gaps require new tests:

1. **Gap 1** -- First-match guard wins (High)
2. **Gap 2** -- Guard evaluation purity (High)
3. **Gap 3** -- Exit/transition/entry action ordering (High)
4. **Gap 4** -- Deep hierarchy action ordering (High)
5. **Gap 5** -- LCA sibling transition (High)
6. **Gap 6** -- Initial state @always chain (Medium)
7. **Gap 7** -- Child-over-parent event priority (High)
8. **Gap 8** -- Self-transition exit/entry actions (Medium)
9. **Gap 9** -- Targetless transition skips exit/entry (Medium)
10. **Gap 10** -- Parallel @done only when all regions final (Medium)
11. **Gap 12** -- Context data type round-trip fidelity (Medium)
12. **Gap 24** -- Self-transition resets child to initial (High)
13. **Gap 27** -- Machine discovery cache/clear commands (Low)
14. **Gap 28** -- Incremental context diff on restore (Medium)
