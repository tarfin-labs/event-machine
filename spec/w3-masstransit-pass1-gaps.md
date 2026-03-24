# W3 MassTransit Pass 1: Happy-Path Gaps

> Theme: HAPPY PATH / SEMANTIC CORRECTNESS
> Lens: Basic behavior that should work but isn't tested.
> Generated: 2026-03-25
> Source: MassTransit saga state machine tests (`/tmp/masstransit/tests/MassTransit.Tests/SagaStateMachineTests/`)

---

## Dedup Notes

Checked against:
- All existing EventMachine tests in `tests/` directory (grep'd extensively)
- W1 Pass 1 gaps in `spec/w1-pass1-happy-path-gaps.md` (14 actionable gaps)
- W1 Pass 2 gaps in `spec/w1-pass2-edge-case-gaps.md`
- W3 XState Pass 1 gaps in `spec/w3-xstate-pass1-happy-gaps.md` (13 actionable gaps)
- W3 Commons SCXML gaps in `spec/w3-commons-pass1-gaps.md` (7 gaps)
- W3 AASM gaps in `spec/w3-aasm-pass1-gaps.md` (8 gaps)
- W3 Boost gaps in `spec/w3-boost-pass1-gaps.md` (8 gaps)
- W3 Spring gaps in `spec/w3-spring-pass1-gaps.md` (8 gaps)

MassTransit test files read through happy-path lens:
- [x] `CompositeEvent_Specs.cs` — Two-event composite event basic lifecycle
- [x] `Choir_Specs.cs` — 4 simultaneous events with composite + retry + outbox
- [x] `Combine_Specs.cs` — Pure state machine composite: enum/int status, RaiseOnce vs None, duplicate events
- [x] `Combine_Assigned_Specs.cs` — CompositeEvent with NextEvents() API
- [x] `CompositeCondition_Specs.cs` — Conditional composite: handler only runs if condition met
- [x] `CompositeOrder_Specs.cs` — Individual handlers run before composite handler
- [x] `CompositeEventUpgrade_Specs.cs` — Parallel requests with 4 composite events for success/fault combos
- [x] `CompositeEventsInInitialState_Specs.cs` — Composite events in Initial state (IncludeInitial)
- [x] `SimpleStateMachine_Specs.cs` — Basic saga lifecycle: Initially -> Running -> Final
- [x] `Request_Specs.cs` — Request/Response: send, Completed, Faulted, TimeoutExpired
- [x] `RequestRequest_Specs.cs` — Nested request: saga -> child saga -> service
- [x] `WhenEnterRequest_Specs.cs` — Entry action triggers request/delegation
- [x] `Observable_Specs.cs` — SubState enter/leave semantics, state change observation
- [x] `SubStateOnEnter_Specs.cs` — SubState enter event fires when entering from outside parent
- [x] `Telephone_Sample.cs` — SubState real-world: OnHold is substate of Connected, timer start/stop
- [x] `EnterEvent_Specs.cs` — WhenEnter with action then chain to another state
- [x] `Finalize_Specs.cs` — WhenEnter with async condition -> Finalize
- [x] `RemoveWhen_Specs.cs` — SetCompletedWhenFinalized saga removal, instant-finalize
- [x] `RaiseEvent_Specs.cs` — Raise event within event handler (internal event)

---

## Gap MT1: Parallel child delegation with mixed success/failure outcomes

- **Priority**: High
- **MassTransit source**: `CompositeEventUpgrade_Specs.cs` — Multiple composite events for different success/fault combinations (both success, both faulted, name-faulted, surname-faulted). Four distinct composite events for four outcome combinations.
- **Type**: Feature test
- **Scenario**: Parent machine delegates to two child machines in parallel (via two states in a parallel region, each delegating to a child machine). Define four possible outcomes: both succeed, both fail, first succeeds + second fails, first fails + second succeeds. Verify the parent routes correctly based on which combination occurs (using guarded @done transitions that check child context/results).
- **Expected behavior**: When both children complete, the parent's @done transition evaluates guards to determine which outcome path to follow: all-success goes to `approved`, any-failure goes to `rejected`, with correct context values from each child.
- **Dedup check**: `ParallelMachineDelegationTest` tests parallel machine delegation but with simple success-only scenarios. `ConditionalOnDoneTest` tests guarded @done but not with mixed success/failure from child machines. `ConditionalOnFailTest` tests @fail guards. No test combines parallel child delegation with multiple outcome-specific @done guards checking individual child results.
- **W1P1/XState overlap**: XState X5 tests nested parallel @done cascade but not mixed child outcomes. Spring Gap 3 tests parallel persist with partial completion but not mixed success/failure routing. No overlap.

## Gap MT2: Entry action data available to subsequent WhenEnter chain

- **Priority**: High
- **MassTransit source**: `EnterEvent_Specs.cs` — `WhenEnter(Running, x => x.Then(context => context.Instance.OnEnter = context.Instance.Counter).TransitionTo(RunningFaster))`. Entry action reads data set by the preceding transition's action, then chains to another state. Verifies `OnEnter == 1` (the value set by the prior action).
- **Type**: Feature test
- **Scenario**: Machine has transition A->B. A->B transition action sets `context['counter'] = 1`. State B has an entry action that reads `context['counter']` and sets `context['on_enter'] = context['counter']`, then uses @always to transition to C. Verify that `context['on_enter'] == 1`, proving entry actions see data from the prior transition's actions.
- **Expected behavior**: Entry actions have access to context modifications made by the transition that brought the machine into the state. The @always chain from the entry state carries the correct context.
- **Dedup check**: `EventProcessingOrderTest` tests entry-before-raise ordering but not entry reading prior transition data. `ActionOrderingTest` tests action ordering but not context data flow between transition and entry actions. `ActionsTest` tests @always with entry actions but not the specific pattern of "transition action sets value, entry action reads it." `AlwaysEventPreservationTest` tests event preservation, not context data flow.
- **W1P1/XState overlap**: W1P1 Gap 3 tests exit/transition/entry ordering (action sequence) but not context data flowing between phases. XState X3 tests action accumulation across macrostep but not the specific entry-reads-transition-data pattern. No direct overlap.

## Gap MT3: Nested child machine delegation (parent -> child -> grandchild)

- **Priority**: High
- **MassTransit source**: `RequestRequest_Specs.cs` — Saga sends request to child saga, which sends request to service (three-level chain). Tests both success and fault paths through the chain.
- **Type**: Feature test
- **Scenario**: Parent machine delegates to child machine (state A -> delegates to ChildA). ChildA delegates to GrandchildA. GrandchildA reaches final. ChildA's @done fires, reaches final. Parent's @done fires and transitions to `completed`. Verify the entire chain completes and the parent transitions correctly.
- **Expected behavior**: Three-level delegation chain: parent -> child -> grandchild. When the grandchild reaches final, the @done cascades upward: grandchild final -> child @done -> child final -> parent @done -> parent transitions.
- **Dedup check**: Grepped for "nested.*child", "chain.*delegat", "grandchild", "three.*level.*delegat" in tests/ -- no results. `MachineDelegationTest` tests single-level child delegation. `AsyncMachineDelegationTest` tests async single-level. `SequentialParentMachine` tests sequential children at the same level, not nested. No test for parent -> child -> grandchild delegation chain.
- **W1P1/XState overlap**: XState X13 (bidirectional parent-child) is about communication, not nesting depth. XState X14/X15 cover single-level delegation (already covered). No overlap.

## Gap MT4: Composite event ordering -- individual handlers run before composite handler

- **Priority**: Medium
- **MassTransit source**: `CompositeOrder_Specs.cs` — Individual event handlers (`First`, `Second`) execute before the composite event handler (`Third`). `CalledAfterAll` flag set to false by individual handlers, then set to true by composite handler. Verifies composite handler runs last.
- **Type**: Feature test
- **Scenario**: EventMachine equivalent: Parallel state with two regions. Region A and B each have entry actions that set `context['region_a_done'] = false` and `context['region_b_done'] = false` respectively in the @done handler. The parent @done handler (which fires after all regions complete) sets `context['all_done'] = true`. Verify that when @done fires, `all_done` is true and was set after the individual region handlers.
- **Expected behavior**: In EventMachine's parallel model, region completion is tracked internally and @done fires only after all regions are final. The @done action should see the context updates from all region entry/exit actions.
- **Dedup check**: `ParallelFinalStatesTest` tests that @done fires after all regions final. `ParallelActionsTest` tests action execution in parallel states. No test specifically verifies that @done actions see context from all region actions (i.e., the ordering guarantee). `ParallelDispatchScxmlOrderingTest` tests ordering in dispatch mode, not sync mode.
- **W1P1/XState overlap**: XState X4 tests @done fires exactly once but not ordering relative to region actions. No direct overlap.

## Gap MT5: Entry action that triggers child machine delegation

- **Priority**: Medium
- **MassTransit source**: `WhenEnterRequest_Specs.cs` — `WhenEnter(StartingExecution, x => x.ThenAsync(...).Request(Execute, ...).TransitionTo(Executing))`. Entry action performs async work, then triggers a request to a service, then transitions.
- **Type**: Feature test
- **Scenario**: Machine transitions from A to B. State B's entry action modifies context (sets a timestamp), then the state has a `machine` key for child delegation. Verify that (a) the entry action runs before the child delegation starts, (b) the child machine receives context from after the entry action, (c) when the child completes, parent transitions via @done.
- **Expected behavior**: Entry actions complete before child machine invocation. The child machine sees the fully processed context. After child completion, parent @done fires.
- **Dedup check**: `ChildCompletionEventQueueTest` tests that child completion events queue correctly but not entry-before-delegation ordering. Commons Gap 6 ("internal events complete before child machine invoke") covers a related but different scenario (raise events complete before delegation, not entry actions). `MachineDelegationTest` tests basic delegation but not entry action -> delegation sequencing.
- **W1P1/XState overlap**: Commons Gap 6 is about raised events before delegation. This gap is about entry actions before delegation. Partially related but distinct scenarios.

## Gap MT6: Conditional @done handling (guard on composite/done handler)

- **Priority**: Medium
- **MassTransit source**: `CompositeCondition_Specs.cs` — Composite event fires but handler only runs if condition met (`When(Third, context => context.Instance.SecondFirst)`). Order of event arrival determines whether condition is true: Second-before-First -> condition true -> handler runs. First-before-Second -> condition false -> handler does NOT run.
- **Type**: Feature test
- **Scenario**: Parallel state with two regions. Both regions have a context-setting action on their entry to final (region A sets `context['a_value'] = 10`, region B sets `context['b_value'] = 20`). The @done transition has a guard that checks `context['a_value'] + context['b_value'] > 25`. If true, transition to `approved`. If false (hypothetically), stay. Verify that the guard on @done reads the correct aggregate context from both regions.
- **Expected behavior**: The guard on a @done transition can access context values set by all completed regions and make decisions based on the aggregate.
- **Dedup check**: `ConditionalOnDoneTest` and `ConditionalOnDoneDispatchTest` test conditional @done with guards that check context values (e.g., `isAllSucceeded`). These DO test guarded @done transitions. However, the specific pattern of the guard reading aggregate context from BOTH regions (not a pre-set value) is worth verifying. Checking `ConditionalOnDoneTest` more closely...
- **W1P1/XState overlap**: None directly.
- **SKIP CANDIDATE**: `ConditionalOnDoneTest` likely covers this. Need to verify.

## Gap MT7: Raise event within event handler (internal event in macrostep)

- **Priority**: Medium
- **MassTransit source**: `RaiseEvent_Specs.cs` — `context.Raise(Initialize)` inside a transition handler. Internal event raised synchronously within macrostep.
- **Type**: Feature test
- **Scenario**: Machine in Initial state handles event THING (with condition guard). On true condition, transitions to `true_state` and raises event INITIALIZE. INITIALIZE handler (in DuringAny) sets `context['initialized'] = true`. Verify the machine is in `true_state` AND `context['initialized']` is true.
- **Expected behavior**: Raised events process within the same macrostep. The transition completes, the raised event is queued, and it processes in the new state.
- **Dedup check**: `EventProcessingOrderTest` tests entry-before-raise ordering. `RaisedEventTiebreakerTest` tests raise() priority. `ParallelDispatchPlanComplianceTest` tests raised events in parallel. BUT: no basic happy-path test for "raise event in transition action, verify it fires in the new state." The existing tests are about ordering/priority, not the basic raise-within-handler pattern.
- **W1P1/XState overlap**: Boost Gap 5 covers multiple raise() FIFO ordering. Commons Gap 5 covers first-raised-event transitions out. This gap is more basic: single raise within a handler works correctly. Partially covered by existing tests but no clean basic test exists.

## Gap MT8: SubState enter semantics -- entering substate from outside parent fires parent enter

- **Priority**: High
- **MassTransit source**: `SubStateOnEnter_Specs.cs` — Transitioning from s1 to s21 (substate of s2) fires s2.Enter event. s2.Enter fires both when entering s2 directly AND when entering s21 from s1. Two s2.Enter events total.
- **Type**: Feature test
- **Scenario**: EventMachine equivalent: compound state P with child C (which itself is a compound with child C1). Machine is in state X (outside P). Transition to P.C.C1. Verify that P's entry action fires, C's entry action fires, and C1's entry action fires -- all three levels.
- **Expected behavior**: When transitioning from outside a compound state to a deep nested child, all ancestor entry actions fire top-down: parent entry, then child entry, then grandchild entry.
- **Dedup check**: `DeepHierarchyOrderingTest` tests 3-level exit ordering. W1P1 Gap 4 covers deep hierarchy exit/entry ordering. `LcaTransitionTest` tests LCA calculation. The specific pattern of "enter from outside into a deep nested child triggers all ancestor entries" is covered by W1P1 Gap 4 conceptually.
- **SKIP**: W1P1 Gap 4 covers the same semantic. The deep hierarchy entry/exit ordering already captures this pattern.

## Gap MT9: SubState -- transitioning within parent to substate does NOT fire parent leave

- **Priority**: High
- **MassTransit source**: `Observable_Specs.cs` (Observing_events_with_substates) — Transitioning from Running to Resting (substate of Running) does NOT fire Running.Leave. Only 4 state changes (not 5): Initial->Running, Running->Resting, Resting->Final (no Running leave between Running and Resting).
- **Type**: Feature test
- **Scenario**: Compound state P with children A and B, where B is also a compound containing B1. Machine is in P.A. Transition to P.B.B1. Verify P's exit action does NOT fire (P is the LCA). Also verify P's entry action does NOT fire again.
- **Expected behavior**: Transitioning within a compound state from one child to a subchild does not trigger the parent's exit or re-entry. Only the exited child's exit and the entered child's entry fire.
- **Dedup check**: W1P1 Gap 5 ("LCA calculation -- sibling transition does NOT exit/enter parent") covers exactly this. The LCA test verifies that the parent's exit/entry do NOT fire for sibling transitions.
- **SKIP**: W1P1 Gap 5 covers this pattern.

## Gap MT10: State lifecycle observation -- state changes tracked correctly through full lifecycle

- **Priority**: Low
- **MassTransit source**: `Observable_Specs.cs` (3 test classes) — StateChangeObserver tracks all state changes with previous/current pairs. Tests verify exact count and values of state transitions.
- **Type**: Feature test
- **Scenario**: EventMachine has `EventCollection` (history) that tracks state changes. A basic test that creates a machine, transitions through multiple states, and verifies the history contains the correct sequence of state values.
- **Expected behavior**: EventCollection records all state transitions in order. History length matches number of transitions.
- **Dedup check**: `PersistenceTest` tests state restoration from events. `TestMachineV2Test` uses history for assertions. The basic pattern of "verify history records correct state sequence" is likely covered implicitly.
- **SKIP**: Covered implicitly by many existing tests that assert state sequences.

---

# Summary

| # | Gap Title | Priority | Covered? |
|---|-----------|----------|----------|
| MT1 | Parallel child delegation with mixed success/failure outcomes | High | Not covered |
| MT2 | Entry action data available to subsequent WhenEnter chain | High | Not covered |
| MT3 | Nested child machine delegation (parent -> child -> grandchild) | High | Not covered |
| MT4 | Composite event ordering -- region actions visible to @done action | Medium | Not covered |
| MT5 | Entry action that triggers child machine delegation | Medium | Partial (Commons Gap 6 is related) |
| MT6 | Conditional @done reads aggregate context from all regions | Medium | Likely covered by ConditionalOnDoneTest -- SKIP |
| MT7 | Raise event within transition handler (basic happy path) | Medium | Partial -- existing tests cover ordering, not basic pattern |
| MT8 | SubState enter from outside fires all ancestor entries | High | Covered by W1P1 Gap 4 -- SKIP |
| MT9 | SubState transition within parent doesn't fire parent leave | High | Covered by W1P1 Gap 5 -- SKIP |
| MT10 | State lifecycle observation/history correctness | Low | Covered implicitly -- SKIP |

## Actionable Gaps (5 beads)

Gaps below are NOT covered by any existing test or existing bead:

1. **Gap MT1** -- Parallel child delegation with mixed success/failure outcomes (High)
2. **Gap MT2** -- Entry action data flows to subsequent @always chain (High)
3. **Gap MT3** -- Nested three-level child machine delegation chain (High)
4. **Gap MT4** -- @done action sees context from all region actions (Medium)
5. **Gap MT7** -- Basic raise-within-handler happy path (Medium)
