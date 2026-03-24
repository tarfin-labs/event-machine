# Boost.Statechart Pass 1 — Gaps (Happy Path / Semantic Correctness)

> Generated: 2026-03-25
> Source: /tmp/boost-statechart/test/ (TriggeringEventTest, DeferralTest, DeferralBug, CustomReactionTest)
> Focus: Triggering event access, event deferral/queueing, reaction ordering in hierarchy

---

## Gap Analysis

### Gap 1: triggeringEvent correctness in exit actions

**Boost pattern (TriggeringEventTest.cpp:83-86):**
State A destructor verifies `triggering_event()` points to the EvGoToB event that caused A to exit. State B destructor (on termination) verifies `triggering_event()` is NULL.

**EventMachine coverage:**
- `AlwaysEventPreservationTest` tests triggeringEvent in entry actions and @always chains
- `ResultBehaviorTriggeringEventTest` tests triggeringEvent in result behaviors
- `ActionOrderingTest` tests exit action execution order
- **MISSING:** No test verifies that `triggeringEvent` contains the correct event value inside exit actions. No test checks that triggeringEvent is null/absent after machine reaches final state.

**Dedup check:**
- No open bead covers "triggeringEvent in exit actions"
- No open bead covers "triggeringEvent null on termination"

**Proposed bead:** Test that `triggeringEvent` (via parameter injection `EventBehavior`) is the correct event in exit actions during a normal transition, and is null/absent when machine terminates.

---

### Gap 2: triggeringEvent correctness in transition actions

**Boost pattern (TriggeringEventTest.cpp:37-40):**
Transit action on the state machine level verifies `triggering_event()` points to EvGoToB during transition action execution.

**EventMachine coverage:**
- `ActionOrderingTest` tests transition action ordering but does NOT inject or check `EventBehavior` in transition actions
- `AlwaysEventPreservationTest` only tests @always chain, not normal transition actions

**Dedup check:**
- No open bead covers "triggeringEvent in transition actions"

**Proposed bead:** Test that transition actions receive the correct `EventBehavior` via parameter injection, with correct type and payload.

---

### Gap 3: triggeringEvent correctness during in-state reactions (self-transition)

**Boost pattern (TriggeringEventTest.cpp:55-58):**
B::DoIt receives EvDoIt via `triggering_event()` during an in-state reaction.

**EventMachine coverage:**
- `SelfTransitionTest` tests that exit/entry actions fire on self-transition but does NOT check the event value
- No test checks `EventBehavior` parameter injection during targetless (internal) transitions

**Dedup check:**
- No open bead covers "triggeringEvent in self/internal transitions"

**Proposed bead:** Test that actions on targetless transitions and self-transitions receive the correct `EventBehavior`.

---

### Gap 4: triggeringEvent on initial state entry (null/absent)

**Boost pattern (TriggeringEventTest.cpp:78-81):**
State A constructor (initial state entry) verifies `triggering_event()` is NULL — no event caused the initial state to be entered.

**EventMachine coverage:**
- `AlwaysEventPreservationTest` line 122-130 tests `InitAlwaysMachine` where the @always on init gets `@always` as the event type
- `AlwaysEventPreservationParallelTest` line 96-97 checks `triggeringEvent` is null before any transition
- **PARTIALLY covered** but no dedicated test for "entry actions on the initial state receive null/absent EventBehavior"

**Dedup check:**
- No open bead for "initial state entry triggeringEvent"

**Proposed bead:** Test that entry actions on the initial state (created via `::create()` or `getInitialState()`) receive null or a synthetic event, not a real event.

---

### Gap 5: Event queue FIFO ordering with raise() — multi-raise scenario

**Boost pattern (DeferralBug.cpp):**
Events sent out of order are deferred and replayed in strict FIFO order. The first-deferred event gets processed first after reaching a state that handles it.

**EventMachine coverage:**
- `EventProcessingOrderTest` tests that entry actions complete before raised events process, and basic raise ordering
- `gen-statem-gaps-raise-before-external-order` bead exists for raise-before-external
- **MISSING:** No test for multiple raise() calls in a single action and verifying they process in FIFO order

**Dedup check:**
- Bead `gen-statem-gaps-raise-before-external-order` covers raise vs external ordering
- No bead covers "multiple raise() calls process in declaration/FIFO order"

**Proposed bead:** Test that multiple raise() calls within a single action process in FIFO order (first raised = first processed).

---

### Gap 6: Event forwarding / discarding in hierarchical states

**Boost pattern (CustomReactionTest.cpp):**
Events bubble from innermost state to outermost: C -> B -> A. Each state can `forward_event()` (continue bubbling) or `discard_event()` (stop). With parallel state D active, events visit regions E/F first, then D, then B, then A.

**EventMachine coverage:**
- EventMachine does not have explicit forward/discard semantics — transitions are defined on states and the most specific match wins
- `ParallelEventHandlingTest` exists but tests event handling within parallel regions
- No test verifies event dispatch priority in nested compound states (most-specific-first)

**Dedup check:**
- No open bead for "hierarchical event dispatch priority"

**Proposed bead:** Test that in a nested compound state hierarchy (A > B > C), events are handled by the most specific (deepest) state that defines a transition for that event, and parent state transitions are only considered when the child has no handler.

---

### Gap 7: triggeringEvent across the full lifecycle — comprehensive per-phase test

**Boost pattern (TriggeringEventTest.cpp):**
Single test verifies triggering_event() at EVERY phase: initial entry (null), transition action (correct), target entry (correct), in-state reaction (correct), exception handler (exception_thrown), destructor on exit (correct event), destructor on termination (null).

**EventMachine coverage:**
- Tests exist for individual phases (entry via AlwaysEventPreservationTest, result via ResultBehaviorTriggeringEventTest)
- **MISSING:** No single comprehensive test that verifies `EventBehavior` parameter injection at every lifecycle phase in one machine flow

**Dedup check:**
- No open bead covers "comprehensive lifecycle triggeringEvent"

**Proposed bead:** Create a comprehensive test that traces `EventBehavior` (via parameter injection) through every lifecycle phase of a single machine flow: initial entry, transition action, exit action, target entry action, @always action, result behavior — verifying correct values at each phase.

---

### Gap 8: Parallel region event dispatch ordering

**Boost pattern (CustomReactionTest.cpp:357-369):**
With parallel state D (regions E, F): EvDiscardNever visits E/F (one of them first), then D, then B, then A. The exact order between E and F is non-deterministic but exactly one of them is visited first.

**EventMachine coverage:**
- `ParallelEventHandlingTest` tests event handling in regions
- `ParallelDispatchScxmlOrderingTest` tests ordering in dispatch mode
- **MISSING:** No test verifies the event evaluation order when multiple parallel regions all have potential handlers for the same event in sync mode

**Dedup check:**
- No open bead covers "sync parallel region event evaluation order"

**Proposed bead:** Test that when a sync parallel state has multiple regions, and an event has transitions in multiple regions, the regions all handle the event (not just the first one), and the parent state's handler is only checked if no region handles it.

---

## Summary: 8 Gaps Found

| # | Gap | Phase | Priority |
|---|-----|-------|----------|
| 1 | triggeringEvent in exit actions (+ null on termination) | exit | HIGH |
| 2 | triggeringEvent in transition actions | transition | HIGH |
| 3 | triggeringEvent in self/internal transitions | transition | MEDIUM |
| 4 | triggeringEvent on initial state entry (null/absent) | entry | MEDIUM |
| 5 | Multiple raise() FIFO ordering | event queue | HIGH |
| 6 | Hierarchical event dispatch priority (most-specific-first) | event dispatch | MEDIUM |
| 7 | Comprehensive lifecycle triggeringEvent trace | all phases | HIGH |
| 8 | Parallel region event evaluation order (sync) | parallel | MEDIUM |

---

## Bead Plan

### Bead 1: triggeringEvent in exit actions and on termination
- **Type:** test-writing
- **Priority:** P1
- **Tags:** boost, triggering-event, exit-action, test-writing
- **Description:** Write tests verifying that: (a) exit actions receive the correct EventBehavior via parameter injection — the event that caused the transition out of the state, (b) after machine reaches a final state, triggeringEvent reflects the last event (not null, since EventMachine doesn't have Boost's "termination nulls triggeringEvent" semantic — verify actual behavior and document).
- **Test location:** tests/Features/TriggeringEventExitActionTest.php

### Bead 2: triggeringEvent in transition actions
- **Type:** test-writing
- **Priority:** P1
- **Tags:** boost, triggering-event, transition-action, test-writing
- **Description:** Write tests verifying that transition actions (the `actions` key on a transition definition) receive the correct EventBehavior via parameter injection, with correct type and payload. Test with both simple and complex payloads.
- **Test location:** tests/Features/TriggeringEventTransitionActionTest.php

### Bead 3: triggeringEvent in self and internal transitions
- **Type:** test-writing
- **Priority:** P2
- **Tags:** boost, triggering-event, self-transition, test-writing
- **Description:** Write tests verifying that: (a) self-transition (target = same state) exit and entry actions receive correct EventBehavior, (b) targetless/internal transition actions receive correct EventBehavior.
- **Test location:** tests/Features/TriggeringEventSelfTransitionTest.php

### Bead 4: triggeringEvent on initial state entry
- **Type:** test-writing
- **Priority:** P2
- **Tags:** boost, triggering-event, initial-state, test-writing
- **Description:** Write tests verifying what EventBehavior value entry actions receive when the initial state is entered via machine creation (no external event). Document whether it's null, a synthetic event, or @init. Partially covered but needs a dedicated, explicit test.
- **Test location:** tests/Features/TriggeringEventInitialEntryTest.php

### Bead 5: Multiple raise() FIFO ordering
- **Type:** test-writing
- **Priority:** P1
- **Tags:** boost, event-queue, raise, fifo, test-writing
- **Description:** Write tests verifying that when a single action calls raise() multiple times (e.g., raise FIRST, then raise SECOND), the events are processed in FIFO order (FIRST before SECOND). Also test that raise() from different actions in sequence maintains order.
- **Test location:** tests/Features/RaiseFifoOrderingTest.php

### Bead 6: Hierarchical event dispatch priority
- **Type:** test-writing
- **Priority:** P2
- **Tags:** boost, hierarchy, event-dispatch, test-writing
- **Description:** Write tests verifying that in nested compound states (grandparent > parent > child), events are handled by the deepest state that defines a transition. If the child has no handler, the parent's handler is used. If parent has no handler, grandparent's is used. This is implicit in EventMachine but untested explicitly.
- **Test location:** tests/Features/HierarchicalEventDispatchTest.php

### Bead 7: Comprehensive lifecycle triggeringEvent trace
- **Type:** test-writing
- **Priority:** P1
- **Tags:** boost, triggering-event, lifecycle, comprehensive, test-writing
- **Description:** Write a single comprehensive test that creates a machine and traces the EventBehavior parameter injection value at every lifecycle phase: (1) initial entry action, (2) transition action on event, (3) exit action on source state, (4) entry action on target state, (5) @always transition action, (6) result behavior. All in one machine flow, capturing the event type at each phase into context for assertion.
- **Test location:** tests/Features/TriggeringEventLifecycleTraceTest.php

### Bead 8: Parallel region sync event evaluation order
- **Type:** test-writing
- **Priority:** P2
- **Tags:** boost, parallel, event-dispatch, sync, test-writing
- **Description:** Write tests verifying that in a sync parallel state with multiple regions, when an event has handlers in multiple regions, ALL regions process the event (not just the first). Also verify that if no region handles the event, the parent state's handler is checked.
- **Test location:** tests/Features/ParallelStates/ParallelSyncEventEvaluationOrderTest.php
