# W3 Commons SCXML Pass 2: Parallel + Invoke Fixture Gaps

> Source: Apache Commons SCXML (`/tmp/commons-scxml`)
> Focus: Parallel XML fixtures, invoke/ directory, semantics/ directory, issues/ regression tests
> Theme: Pass 2 — Edge cases & boundary conditions (secondary: parallel + invoke fixtures)
> Generated: 2026-03-25

---

## Files Read

### Parallel Fixtures
- [x] `model/parallel-01.xml` — Cross-region In() predicate: region_2 checks if region_1 is in specific state
- [x] `model/parallel-02.xml` — Transition ON the parallel element itself exits entire parallel
- [x] `model/parallel-03.xml` — Entry/exit action counting across parallel lifecycle (initial=5, after foo=7, after bar/done=14)
- [x] `model/ParallelTest.java` — 3 test methods exercising the above fixtures
- [x] `model/stateless-parallel-01.xml` — Shared parallel definition between multiple executors
- [x] `model/StatelessModelTest.java` — Simultaneous parallel executors with interleaved events

### Invoke Fixtures
- [x] `invoke/invoker-01.xml` — Basic invoke with param passing and finalize
- [x] `invoke/invoker-02.xml` — Simple invoke, child auto-terminates
- [x] `invoke/invoker-03.xml` — Nested invocation: parent -> child -> grandchild
- [x] `invoke/invoker-04.xml` — Custom invoker type with param name/expr
- [x] `invoke/invoker-05.xml` — Macrostep: invoke after ALL internal events processed (SCXML 3.13)
- [x] `invoke/invoked-01.xml` — Child that logs received params
- [x] `invoke/invoked-03.xml` — Middle child that invokes grandchild
- [x] `invoke/InvokeTest.java` — 4 test methods covering invoke lifecycle
- [x] `invoke/InvokeParamNameTest.java` — Param name/expr passing to custom invokers

### Semantics Tests
- [x] `semantics/SCXMLSemanticsImplTest.java` — isLegalConfiguration: empty=legal, multiple top-level OR=illegal, multiple OR under same parent=illegal, missing parallel children=illegal

### Issues Tests
- [x] `issues/Issue62Test.java` — External state source with fragment identifiers (3 variants)
- [x] `issues/Issue64Test.java` — Transition accessing datamodel variables (WONTFIX, 2 tests)
- [x] `issues/Issue112Test.java` — Custom actions generating external events during processing
- [x] `issues/queue-01.xml` — External event queue pattern: actions enqueue events processed sequentially
- [x] `issues/issue62-01.xml` through `issue62-03.xml` and their `-ext.xml` fragments

### Tie-Breaker (Pass 1 already covered, re-verified completeness)
- [x] `tie-breaker-01.xml` through `tie-breaker-06.xml` — all re-verified
- [x] `TieBreakerTest.java` — all 6 tests re-verified

### Datamodel / Stateless
- [x] `model/DatamodelTest.java` — Simultaneous executors with interleaved events + serialization between steps

---

## Dedup Checks Performed

### Against Pass 1 gaps (`spec/w3-commons-pass1-gaps.md`)
Pass 1 covered 7 gaps: document order, child+docorder combined, targetless priority, parallel independent tiebreak, raised events first-wins, internal events before invoke, guard error as false. These are NOT duplicated below.

### Against existing EventMachine tests (grep results)
1. `ParallelEscapeTransitionTest.php` — Tests root-level event escaping parallel (covers parallel-02 pattern)
2. `ParallelActionsTest.php` — Tests entry/exit actions fire during parallel region entry and transitions
3. `ParallelCrossRegionRaiseTest.php` — Tests raised event in one region triggers transition in sibling (cross-region communication)
4. `NestedParallelExitActionsTest.php` — Tests exit actions on nested parallel state when @done fires
5. `ParallelDispatchXStateTest.php` — Tests entry/exit count tracking for individual regions
6. `SequentialParentMachine.php` — Stub for sequential delegation (parent -> child A -> child B)
7. `MachineDelegationTest.php` — Basic sync delegation, @done, @fail, context passing
8. `AsyncEdgeCasesTest.php` — Sequential delegation test (LocalQA)
9. `BasicParallelStatesTest.php` — Basic parallel entry/done
10. `ParallelFinalStatesTest.php` — @done fires when all regions reach final
11. `ParallelStatesDocumentationTest.php` — Documentation examples for parallel
12. `ParallelAdvancedTest.php` — Advanced parallel scenarios with multiple events

### Against W1 Pass 1/2 gaps
- `w1-pass1-happy-path-gaps.md` — No overlap with gaps below
- `w1-pass2-edge-case-gaps.md` — Checked, no overlap

---

## Gap Analysis

### Gap 1: Parallel Entry/Exit Action Counting Across Full Lifecycle (parallel-03)

- **Priority**: High
- **Source**: `parallel-03.xml` + `ParallelTest.testParallel03` — Tracks cumulative count of entry/exit actions across entire parallel lifecycle: initial entry (parallel+region+leaf = 5), after intra-region transition (exit old leaf + enter new leaf = 7), after @done exits everything (14 total).
- **Pattern**: A counter incremented by every entry and exit action across the parallel state, both regions, and all leaf states. Verifies the EXACT number of actions fired at each lifecycle point.
- **EventMachine mapping**: Entry/exit actions on the parallel state itself, region states, and leaf states should all fire. Transitions within a region should fire exit on old leaf + entry on new leaf. @done completion should fire exit on all active leaves, all regions, and the parallel state itself.
- **Dedup**: `ParallelActionsTest.php` tests that entry actions fire on initial entry and transition actions fire during region transitions, but does NOT verify cumulative counts across the full lifecycle (entry -> intra-region transition -> @done completion). `ParallelDispatchXStateTest.php` counts entry/exit for individual states, not across the full parallel hierarchy. `NestedParallelExitActionsTest.php` tests exit actions on @done but only verifies which states exited, not cumulative action counts. No test combines entry counts, transition counts, AND exit-on-@done counts into a single cumulative assertion.
- **Stub needed**: No (inline MachineDefinition::define with counter closures)

### Gap 2: Cross-Region Guard Checking Sibling State (parallel-01 In() predicate)

- **Priority**: Medium
- **Source**: `parallel-01.xml` — Region 2 has an `@always` (or guard-based) transition that checks whether region 1 is in a specific state (`In('para12')`). After region 1 transitions to para12 via event "foo", region 2's guarded transition becomes valid and fires.
- **Pattern**: Region B has a guard that reads region A's current state. Only when region A reaches a specific state does region B's transition become available. This is an @always pattern where the guard depends on sibling region state.
- **EventMachine mapping**: Guards in EventMachine receive `State` which contains the full parallel value array. A guard could check `$state->matches('active.region_a.specific_state')` to conditionally allow a transition. Alternatively, an @always transition with a guard checking sibling region state.
- **Dedup**: `ParallelCrossRegionRaiseTest.php` tests cross-region raise() communication (event-based). No test verifies guard-based cross-region state checking — where region B's guard inspects region A's current state without an explicit event. This is a fundamentally different pattern: event-based vs state-inspection-based cross-region coordination.
- **Stub needed**: No (inline MachineDefinition::define with guard closure checking state)

### Gap 3: Nested Invocation — Parent -> Child -> Grandchild Delegation Chain (invoker-03)

- **Priority**: High
- **Source**: `invoker-03.xml` + `invoked-03.xml` + `invoked-03-01.xml` — Three-level delegation chain. Parent invokes child, which invokes grandchild. Grandchild completes -> child completes -> parent completes.
- **Pattern**: Parent state delegates to Child machine. Child machine has its own delegation state that delegates to Grandchild machine. Grandchild auto-completes, which triggers @done in child, which triggers @done in parent.
- **EventMachine mapping**: Parent uses `machine` key to delegate to ChildMachine class. ChildMachine definition also uses `machine` key to delegate to GrandchildMachine. Three-level delegation chain with @done cascading up.
- **Dedup**: `MachineDelegationTest.php` tests single-level delegation (parent -> child). `SequentialParentMachine.php` tests sequential delegation (parent -> child A, then -> child B) but NOT nested (parent -> child -> grandchild). `ParallelDelegationTest.php` tests parallel + delegation but not nested depth. No test verifies three-level nesting where child itself is a delegating machine.
- **Stub needed**: Yes (GrandchildMachine + ChildDelegatingMachine + ParentMachine)

### Gap 4: Param/Context Passing to Delegated Child Machine (invoker-01/04)

- **Priority**: Medium
- **Source**: `invoker-01.xml` — Parent passes `foo='foo'` and `bar='bar'` params to invoked child. Child logs received params. `invoker-04.xml` — Passes computed expression (`foo.bar`) as param to custom invoker.
- **Pattern**: Parent machine passes specific context values to child machine when delegating. Child receives those values in its own context.
- **EventMachine mapping**: Context is passed from parent to child during delegation. The child machine's context is initialized from the parent's context (or specific keys from it). The `machine` key delegation should allow specifying which context keys are forwarded.
- **Dedup**: `MachineDelegationTest.php` tests delegation with context but focuses on @done completion, not verifying specific param values arrive in child. `ForwardContextTest.php` tests ForwardContext but that's about endpoint routing, not delegation. `AsyncMachineDelegationTest.php` tests async delegation context but not specific param assertion on child side. No test explicitly asserts that specific context values from parent arrive correctly in child's initial context.
- **Stub needed**: No (can extend existing delegation test with assertions)

### Gap 5: Configuration Legality — Missing Parallel Region Activation (SCXMLSemanticsImpl)

- **Priority**: Medium
- **Source**: `SCXMLSemanticsImplTest.java` — Tests that a parallel state with not-all-regions active is an illegal configuration. Also tests multiple OR states active under same parent, and multiple top-level states.
- **Pattern**: Validate that when a parallel state is entered, ALL regions must have an active child. If some regions are missing, the configuration is illegal.
- **EventMachine mapping**: MachineDefinition validation should catch invalid parallel configurations at definition time. At runtime, entering a parallel state should always activate all regions. There should be either a validator check or a runtime invariant that prevents partial parallel activation.
- **Dedup**: `StateConfigValidatorTest.php` tests configuration validation but focuses on missing initial states, invalid targets, etc. `ParallelDispatchE2ETest.php` mentions `isLegalConfiguration` in a comment but tests E2E behavior. No test explicitly verifies that the system rejects or prevents a state where a parallel has some-but-not-all regions active.
- **Stub needed**: No (unit test on MachineDefinition or State validation)

### Gap 6: Actions Generating External Events During Processing (Issue 112 — queue-01)

- **Priority**: Medium
- **Source**: `Issue112Test.java` + `queue-01.xml` — Actions executed during event processing can generate additional external events that are processed sequentially after the current macrostep. Uses an external queue pattern: custom `<enqueue>` action adds events to a queue, those events are fired one at a time.
- **Pattern**: An entry action generates new events to be processed. Those events trigger further transitions, potentially generating more events. The pattern tests cascading event generation where actions produce events that feed back into the machine.
- **EventMachine mapping**: This maps to `raise()` within actions or `sendTo()` patterns. An action can raise events that are queued and processed after the current macrostep. The pattern also maps to scenarios where actions dispatch external events (e.g., via `sendTo`/`dispatchTo`) that circle back to the machine.
- **Dedup**: `EventProcessingOrderTest.php` tests entry-before-raise ordering. `RaisedEventTiebreakerTest.php` tests raised event processing order. `ParallelDispatchInternalEventsTest.php` tests internal event processing in parallel. None tests the pattern of actions generating events that generate MORE events in a cascading chain across multiple states. The specific pattern of action-generated-event -> transition -> action-generated-event -> transition is not tested.
- **Stub needed**: No (inline MachineDefinition::define with raise() chain)

### Gap 7: Simultaneous Machine Instances from Shared Definition with Interleaved Events (DatamodelTest/StatelessModelTest)

- **Priority**: Low
- **Source**: `DatamodelTest.java` + `StatelessModelTest.java` — Two executor instances sharing the same SCXML definition object. Events are fired to exec01, then exec02, then exec01 again — interleaved execution. Tests that instances don't interfere with each other's state.
- **Pattern**: Create Machine A and Machine B from the same MachineDefinition. Fire events to A, verify A's state. Fire events to B, verify B's state. Interleave events between A and B. Verify complete independence.
- **EventMachine mapping**: `MachineDefinition::define()` returns a definition. Multiple `Machine::create()` calls from the same definition should produce independent instances. This is fundamental to EventMachine's architecture (each Machine has its own MachineEvent history). But there's no test that explicitly interleaves events between two instances from the same definition and verifies independence.
- **Dedup**: `BasicParallelStatesTest.php` mentions "simultaneous" but tests a single instance. No test creates two Machine instances from the same definition class and fires interleaved events. The pattern is implicitly tested by the entire test suite (each test creates a fresh instance), but explicit interleaving is not verified.
- **Stub needed**: No (inline test with two Machine instances)

### Gap 8: Parallel State with Transition on Parallel Element Itself (parallel-02 — Dummy Regions)

- **Priority**: Low
- **Source**: `parallel-02.xml` — A parallel state with dummy (leaf-only, no transitions) regions. A transition is defined ON the parallel element. When the event fires, the entire parallel exits to a final state, even though no individual region handles the event.
- **Pattern**: Parallel has regions that do not handle event X. But the parallel state itself has a transition on event X. Event X should exit the entire parallel.
- **EventMachine mapping**: This maps to having an `on` event on a parallel state definition (not on individual regions). When the event fires and no region handles it, the parallel-level transition takes effect.
- **Dedup**: `ParallelEscapeTransitionTest.php` tests root-level `on` events escaping parallel, but it's defined on the root state machine, not on the parallel state itself. The parallel-02 pattern has the transition ON the parallel element directly. Let me check more carefully...
- Actually, re-reading `ParallelEscapeTransitionTest.php`, it defines the escape event on the root level `'on'` key, not on the parallel state's own config. The parallel-02 pattern is: the parallel state has `'on' => ['EVENT' => 'target']` at the parallel level. This should be equivalent in EventMachine to having the transition on the parent state of the parallel, or on the parallel state definition itself. **However**, EventMachine parallel states ARE compound states — transitions can be defined on them. The question is whether an event unhandled by regions but handled by the parallel state's own `on` config works correctly.
- **Stub needed**: No (inline MachineDefinition::define)

---

## Summary

| # | Gap Title | Priority | Overlaps Pass 1? |
|---|-----------|----------|-------------------|
| 1 | Parallel entry/exit action counting across full lifecycle | High | No |
| 2 | Cross-region guard checking sibling state (In() predicate) | Medium | No |
| 3 | Nested invocation: parent -> child -> grandchild chain | High | No |
| 4 | Param/context passing assertions in delegated child | Medium | No |
| 5 | Configuration legality: missing parallel region activation | Medium | No |
| 6 | Actions generating cascading external events | Medium | No |
| 7 | Simultaneous instances from shared definition, interleaved events | Low | No |
| 8 | Parallel-level transition with dummy regions | Low | No |

## Beads Plan

### Bead 1: Parallel Entry/Exit Action Count Across Full Lifecycle
Write a Feature test with a parallel state having two regions. Attach entry/exit actions (via inline closures) to: the parallel state itself, each region state, and each leaf state. Use a shared counter variable. Assert exact counts at three lifecycle points: (1) after initial entry = N entries, (2) after one intra-region transition = N + exit(old_leaf) + entry(new_leaf), (3) after @done completion = N + all exits. Model after Commons parallel-03.xml counting pattern.

### Bead 2: Cross-Region Guard Checking Sibling Region State
Write a Feature test with a parallel state. Region A transitions on an event. Region B has an @always transition with a guard that checks if region A is in a specific state. After sending the event (region A transitions), verify that region B's @always guard now passes and region B transitions too. This is distinct from cross-region raise — no explicit event is raised; the guard inspects sibling state.

### Bead 3: Three-Level Nested Delegation Chain
Write a Feature test with three machine levels: GrandchildMachine (auto-completes), ChildDelegatingMachine (delegates to Grandchild, @done transitions to final), ParentMachine (delegates to ChildDelegating, @done transitions to final). Verify the full chain completes: grandchild done -> child done -> parent done. Requires three new stub machines.

### Bead 4: Context Values Arrive Correctly in Delegated Child
Write a Feature test where a parent machine sets specific context values, then delegates to a child machine. Assert that the child machine's initial context contains the expected values from the parent. Use existing delegation stubs or create minimal inline definitions.

### Bead 5: Parallel Region Activation Completeness Validation
Write a Feature/Unit test that verifies the system ensures all parallel regions are active when entering a parallel state. If possible, test that a manually constructed incomplete state (where some regions lack active children) is detected as invalid by the definition or runtime validation.

### Bead 6: Cascading Event Generation — Actions Producing Events That Produce More Events
Write a Feature test where an entry action raises event A. Event A's transition handler raises event B. Event B's transition moves to a new state, whose entry action raises event C. Verify the full cascade processes correctly and the machine reaches the expected final state. Model after Commons Issue 112 pattern.

### Bead 7: Interleaved Events on Two Instances from Same Definition
Write a Feature test that creates two Machine instances from the same MachineDefinition class. Fire events to instance A, then to instance B, then to instance A again (interleaved). Verify complete state independence — instance A's transitions don't affect instance B's state and vice versa.

### Bead 8: Event on Parallel State Itself Exits All Regions
Write a Feature test with a parallel state that has leaf-only regions (no transitions on them). Define an event transition on the parallel state level (via parent compound state or the parallel config's own `on` key). Send that event. Verify the machine exits the entire parallel and reaches the target state, even though no individual region handled the event.
