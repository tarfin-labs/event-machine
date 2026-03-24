# W3 Commons SCXML Pass 1: Tie-Breaker + W3C Runner Gaps

> Source: Apache Commons SCXML (`/tmp/commons-scxml`)
> Focus: Document order priority, child-over-parent priority, parallel region independent resolution, macrostep semantics
> Theme: Pass 1 — Happy path / semantic correctness (primary strength area)
> Generated: 2026-03-25

---

## Files Read

### Java Test Files
- [x] `TieBreakerTest.java` — 6 test methods covering document order, hierarchy, parallel, targetless, raised events
- [x] `SCXMLExecutorTest.java` — microwave samples, transition tests, conditional transitions, send tests
- [x] `w3c/W3CTests.java` — W3C IRP test runner framework (downloads, transforms, runs 192 tests)
- [x] `w3c/tests.xml` — test configuration tracking pass/fail per datamodel

### XML Fixtures
- [x] `tie-breaker-01.xml` — document order: first transition wins
- [x] `tie-breaker-02.xml` — child state wins over parent for same event
- [x] `tie-breaker-03.xml` — child wins + document order combined
- [x] `tie-breaker-04.xml` — targetless transitions at parent and child levels
- [x] `tie-breaker-05.xml` — parallel region independent tie-breaking
- [x] `tie-breaker-06.xml` — multiple raised events, first internal event triggers transition
- [x] `invoke/invoker-05.xml` — macrostep: invoke executes AFTER all internal events

---

## Dedup Checks Performed

### Against existing EventMachine tests (grep results)
1. `GuardPriorityTest.php` — Tests first-match guard wins in document order (guarded transitions only)
2. `HierarchicalEventPriorityTest.php` — Tests child-over-parent event priority (2 tests)
3. `SelfTransitionTest.php` — Tests targetless/self-transition action behavior
4. `ParallelDispatchPlanComplianceTest.php` — Tests multiple raised events processed in order (parallel dispatch mode)
5. `ParallelEventHandlingTest.php` — Tests event handling in parallel regions
6. `InitialAlwaysChainTest.php` — Tests @always chain macrostep behavior

### Against W1 Pass 1 gaps (`spec/w1-pass1-happy-path-gaps.md`)
- Gap 1: First-match guard wins — covers guarded document order (NOT unguarded)
- Gap 7: Child state has priority over parent — same concept as tie-breaker-02
- Gap 9: Targetless transition skips exit/entry — related to tie-breaker-04

---

## Gap Analysis

### Gap 1: Document Order — Two Unguarded Transitions on Same Event (tie-breaker-01)

- **Priority**: High
- **Source**: `tie-breaker-01.xml` — Two transitions from same state on same event, no guards. First in array order wins.
- **Pattern**: `{ 'EVENT' => ['target_a', 'target_b'] }` or `{ 'EVENT' => [['target' => 'a'], ['target' => 'b']] }`
- **EventMachine architecture**: `TransitionDefinition::getFirstValidTransitionBranch()` iterates branches in array order and returns the first valid one. This SHOULD work, but there is no test for two unguarded (guard-free) transitions to different targets on the same event.
- **Dedup**: `GuardPriorityTest.php` tests document order WITH guards (both return true). No test for pure unguarded document order. W1 Gap 1 is about guarded transitions. This is a distinct scenario.
- **Stub needed**: No (inline TestMachine::define)

### Gap 2: Child + Document Order Combined (tie-breaker-03)

- **Priority**: High
- **Source**: `tie-breaker-03.xml` — Both parent and child define multiple transitions for same event. Child's first transition wins (hierarchy + document order combined).
- **Pattern**: Parent has two transitions for EVENT (to A and B), child has two transitions for EVENT (to C and D). Expected: child's first transition (C) wins.
- **Dedup**: `HierarchicalEventPriorityTest.php` tests child-over-parent with single transitions at each level. No test combines hierarchy priority WITH document order (multiple transitions at both levels). W1 Gap 7 is child-over-parent with single transitions. This is distinct.
- **Stub needed**: No (inline TestMachine::define)

### Gap 3: Targetless Transition Priority — Child Targetless Wins Over Parent Targetless (tie-breaker-04)

- **Priority**: Medium
- **Source**: `tie-breaker-04.xml` — Both parent and child define targetless transitions for the same event. Child's targetless transition takes priority.
- **Pattern**: Parent state has `'EVENT' => []` (targetless). Child state also has `'EVENT' => []` (targetless). When machine is in child state and receives EVENT, child's targetless handler runs, not parent's.
- **Dedup**: `TargetlessTransitionTest.php` tests targetless at a single level. `SelfTransitionTest.php` tests targetless action behavior. Neither tests parent vs child targetless priority. W1 Gap 9 is about exit/entry actions on targetless, not priority hierarchy.
- **Stub needed**: No (inline TestMachine::define)

### Gap 4: Parallel Region Independent Tie-Breaking (tie-breaker-05)

- **Priority**: High
- **Source**: `tie-breaker-05.xml` — Parallel state with nested parallels. Each region independently resolves document-order tie-breaking. Event causes different transitions in different regions.
- **Pattern**: Outer parallel has region S1 (s11 with two transitions for event1 to s12 and s13) and region S2 (nested parallel with s2111 having two transitions for event1 to s2112 and s2113, plus s212 which has no transition for event1). After event1: s11->s12 (first wins), s2111->s2112 (first wins), s212 stays.
- **Dedup**: `ParallelEventHandlingTest.php` tests event broadcasting to regions. No test verifies that tie-breaking within each region is independent (each region picks its own first-match). No test combines document-order tie-breaking with parallel regions. `ParallelAdvancedTest.php` tests both regions transitioning but with unambiguous targets.
- **Stub needed**: Yes (requires nested parallel with document-order ambiguity per region)

### Gap 5: Multiple Raised Events in Entry — Only First Triggers Transition Per Macrostep (tie-breaker-06)

- **Priority**: High
- **Source**: `tie-breaker-06.xml` — `onentry` raises both `internal_event1` and `internal_event2`. State has transitions for both. Only `internal_event1`'s transition fires (first raised event is processed first).
- **Pattern**: Entry action raises event A then event B. State handles both A and B with transitions. After macrostep: the transition for A fires. B's transition may or may not fire depending on whether A's transition leaves the state.
- **EventMachine mapping**: Entry actions can use `raise()` to queue internal events. The event queue processes events one at a time. If the first raised event causes a state change, the second event is evaluated against the new state.
- **Dedup**: `ParallelDispatchPlanComplianceTest.php` test #60 tests multiple raised events processed in order (in parallel dispatch mode). `EventProcessingOrderTest.php` tests entry-before-raise. Neither tests the specific scenario where the first raised event causes a transition that makes the second raised event's transition unavailable/irrelevant. The existing tests verify ordering, not the "first wins and exits state" semantic.
- **Stub needed**: No (inline TestMachine::define)

### Gap 6: Internal Events Complete Before Child Machine Invoke (invoker-05)

- **Priority**: High
- **Source**: `invoker-05.xml` — SCXML spec 3.13: invoke handlers execute AFTER all internal events are processed in the macrostep. Entry raises internal events, handler sets context value, invoke reads updated value.
- **Pattern**: State entry raises events. Those events' transitions modify context. Then child machine delegation starts and reads the modified context (not the pre-raise context).
- **EventMachine mapping**: When entering a state with a `machine` key (child delegation), all entry actions and @always chains should complete before the child machine is created/started. The child should see the fully processed context.
- **Dedup**: No test verifies that entry action raise() events are fully processed before child machine delegation starts. `InitialAlwaysChainTest.php` tests @always chains but not raise-then-delegate ordering. `MachineDelegationTest.php` tests delegation but not the ordering guarantee with raised events.
- **Stub needed**: Yes (machine with entry actions that raise, plus child delegation reading context set by raised event handler)

### Gap 7: Conditional Transitions — Guard Error Treated as False (SCXMLExecutorTest)

- **Priority**: Medium
- **Source**: `SCXMLExecutorTest.testSCXMLExecutorTransitionsWithCond01Sample` — Expression syntax error in guard condition is treated as `false`, not an exception. Machine stays in current state.
- **Pattern**: A guard that throws an exception during evaluation should be treated as returning `false`, allowing fallthrough to next transition or staying in current state.
- **Dedup**: Grepped for "guard.*error", "guard.*exception", "guard.*throw", "guard.*false" in tests/ — `ValidationGuardTest` tests validation guard failures (throws MachineValidationException). No test verifies that a regular GuardBehavior throwing a runtime exception is treated as `false` (non-matching). This is a distinct semantic from ValidationGuardBehavior.
- **Stub needed**: No (inline TestMachine::define with a guard that throws)

---

## Summary

| # | Gap Title | Priority | Overlaps W1? |
|---|-----------|----------|--------------|
| 1 | Document order: unguarded transitions, first wins | High | Extends W1 Gap 1 (that covers guarded only) |
| 2 | Child + document order combined (hierarchy + array order) | High | Extends W1 Gap 7 (that covers single transition per level) |
| 3 | Targetless transition priority: child targetless over parent | Medium | Distinct from W1 Gap 9 (that covers action behavior) |
| 4 | Parallel region independent tie-breaking | High | No overlap |
| 5 | Multiple raised events — first transitions, exits state | High | No overlap |
| 6 | Internal events complete before child machine invoke | High | No overlap |
| 7 | Guard error treated as false (not exception) | Medium | No overlap |

## Beads Plan

Seven test-writing beads needed:

### Bead 1: Document Order — Unguarded First Transition Wins
Write a Feature test with two unguarded transitions on the same event from the same state. Verify the first transition in config array order is selected. Use inline `TestMachine::define`.

### Bead 2: Child + Document Order Combined Priority
Write a Feature test where both parent and child state define multiple unguarded transitions for the same event. Verify: (a) child wins over parent, and (b) within the child, document order wins. Use inline `TestMachine::define`.

### Bead 3: Targetless Transition Hierarchy Priority
Write a Feature test where both parent and child define targetless transitions for the same event, each with a distinct action. Verify the child's targetless action runs, not the parent's. Use inline `TestMachine::define`.

### Bead 4: Parallel Region Independent Document-Order Tie-Breaking
Write a Feature test with a parallel state where each region has a state with two transitions for the same event (to different targets). Verify each region independently resolves to its first transition. Requires a stub machine with nested parallel structure.

### Bead 5: Raised Events — First Causes State Exit, Second Not Processed
Write a Feature test where an entry action raises two events. The state handles both with transitions to different targets. Verify only the first raised event's transition fires (because it exits the state). Use inline `TestMachine::define`.

### Bead 6: Internal Events Complete Before Child Machine Delegation
Write a Feature test where a state has entry actions that raise events, handlers that modify context, AND a `machine` key for child delegation. Verify the child machine receives the context as modified by the raised event handlers, not the pre-entry context. Requires a stub parent + child machine pair.

### Bead 7: Guard Runtime Exception Treated as False
Write a Feature test where a guard throws a RuntimeException. Verify the machine treats it as a non-matching guard (returns false), falls through to the next transition or stays in current state. No exception should propagate. Use inline `TestMachine::define`.
