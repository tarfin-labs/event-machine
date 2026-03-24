# W3 XState Pass 3: Async / Concurrent / Race Condition Gaps

> Theme: ASYNC / CONCURRENT / RACE CONDITIONS
> Lens: Actor lifecycle, concurrent events, snapshot race, parallel dispatch timing, event ordering.
> Generated: 2026-03-25
> Source: XState v5 test suite — invoke.test.ts, actor.test.ts, system.test.ts, interpreter.test.ts, predictableExec.test.ts

---

## Dedup Check

Checked against:
- `spec/w1-pass3-async-gaps.md` (16 LocalQA gaps — all require real MySQL/Redis/Horizon, no unit-level overlap)
- `spec/w3-xstate-pass1-happy-gaps.md` (13 happy-path gaps — different theme)
- All existing EventMachine test files in `tests/` (grep'd extensively for each pattern below)
- W1 Pass 1 and Pass 2 gap documents

---

## Gap XA1: Child delegation not started when delegating state is immediately exited via @always

- **Priority**: High
- **XState source**: `invoke.test.ts` lines 2772-2838 ("should not invoke an actor if it gets stopped immediately by transitioning away in immediate microstep" and "...in subsequent microstep")
- **Type**: Feature test
- **Scenario**: Create a machine with a state that has both a `machine` delegation key AND an `@always` transition to another state. The @always fires immediately, exiting the delegating state. Verify: (1) the child machine is NOT started (no `machine_children` row, no events), (2) the parent reaches the @always target state correctly.
- **Expected behavior**: If a state with child delegation is immediately exited by an @always transition, the child should never be started. This is an optimization AND a correctness requirement — starting a child that will immediately be orphaned wastes resources and may cause race conditions.
- **Dedup check**: Grepped "transient.*skip.*delegation", "always.*skip.*child", "child.*not.*start.*always" — no results. No existing test covers this pattern. `AlwaysEventPreservationTest` tests event preservation, not delegation skipping. `MachineDelegationTest` tests normal delegation flows.
- **W1P3 overlap**: None (W1P3 is all LocalQA tests with real concurrency)

## Gap XA2: Events from completed/stopped child delegation are silently discarded by parent

- **Priority**: High
- **XState source**: `actor.test.ts` lines 396-432 ("should not deliver events sent to the parent after the callback actor gets stopped")
- **Type**: Feature test
- **Scenario**: Parent delegates to sync child. Child completes (reaches final, parent processes @done and moves on). Then simulate a late `sendToParent` or `ChildMachineCompletionJob` arriving after the parent has already left the delegating state. Verify: (1) parent does not crash, (2) parent does not transition, (3) no exception thrown, (4) the late event is a no-op.
- **Expected behavior**: Events arriving from a child machine after the parent has left the delegating state should be silently ignored.
- **Dedup check**: Grepped "event.*from.*stopped.*child", "stopped.*child.*event", "dead.*letter" — no results. `AsyncEdgeCasesTest::@timeout fires` tests timeout-only. W1P3 Gap 13 covers "child completion after parent timeout" as LocalQA; this is the Feature-level equivalent testing the no-op behavior without requiring Horizon.
- **W1P3 overlap**: W1P3 Gap 13 is the LocalQA version. This is a Feature test covering the same semantic but without real async infrastructure.

## Gap XA3: Child machine that immediately completes via @always during parent initialization does not crash

- **Priority**: High
- **XState source**: `actor.test.ts` lines 1299-1339 ("should not crash on child machine sync completion during self-initialization"), lines 1341-1360 (sync promise completion), lines 1362-1388 (sync observable completion)
- **Type**: Feature test
- **Scenario**: Create a child machine whose initial state has `@always -> final_state`. Parent delegates to this child synchronously. The child immediately reaches final during the parent's `enterState()` call. Verify: (1) no exception/crash, (2) parent processes @done correctly and transitions, (3) child's completion event is properly queued and handled.
- **Expected behavior**: A child that synchronously completes during parent initialization should not cause infinite loops or crashes. The @done event should be queued and processed after the parent finishes its initial entry.
- **Dedup check**: Grepped "child.*sync.*complet.*init", "sync.*done.*during.*init", "immediate.*child.*final.*init" — no results. `ImmediateChildMachine` stub exists and is used in `MachineDelegationTest` for immediate completion, but the specific pattern of @always-to-final (not just an initial final state) during parent init is not tested. This is specifically the case where the child's @always chain runs during the parent's `handleMachineInvoke` call.
- **W1P3 overlap**: None

## Gap XA4: @done event from parallel child delegation is scoped to the correct region, not broadcast

- **Priority**: High
- **XState source**: `invoke.test.ts` lines 3121-3174 ("xstate.done.actor events should only select onDone transition on the invoking state when invokee is referenced using a string") — two parallel regions invoke the same source; done events go to correct region only
- **Type**: Feature test
- **Scenario**: Create a parallel machine with two regions, each delegating to a child machine of the same type. Region A's child completes first. Verify: (1) only Region A's @done handler fires, not Region B's, (2) Region B's child continues running, (3) when Region B's child also completes, its @done fires independently.
- **Expected behavior**: @done events from child delegations are scoped to the parallel region that invoked them. They should not be broadcast to sibling regions.
- **Dedup check**: Grepped "done.*event.*correct.*region", "done.*only.*invoking", "done.*scoped.*region" — no results. `ParallelMachineDelegationTest` tests parallel delegation but doesn't verify done-event scoping (both children complete simultaneously). No test where children complete at different times to verify scoping.
- **W1P3 overlap**: None

## Gap XA5: Rapid sequential events processed in FIFO order within same macrostep

- **Priority**: Medium
- **XState source**: `interpreter.test.ts` lines 982-1017 ("should receive and process all events sent simultaneously"), `invoke.test.ts` lines 2457-2498 ("should schedule events in a FIFO queue")
- **Type**: Feature test
- **Scenario**: Create a machine with states A->B->C. Send two events rapidly: EVENT_1 (A->B) then EVENT_2 (B->C). Both are sent synchronously in the same test method (no queue). Verify: (1) machine reaches C (both events processed), (2) event history shows EVENT_1 before EVENT_2 in correct order.
- **Expected behavior**: Multiple events sent synchronously to Machine::send() in rapid succession are processed in FIFO order. Each event sees the state left by the previous event.
- **Dedup check**: Grepped "FIFO", "event.*order.*queue" — one result in `StateConfigValidatorTest` (unrelated). No test specifically verifies FIFO ordering of multiple synchronous send() calls. `TransitionsTest` sends individual events but doesn't verify ordering of rapid sequential events.
- **W1P3 overlap**: W1P3 Gap 9 is the LocalQA version (dispatchTo ordering under Horizon). This is the Feature-level unit test.

## Gap XA6: Events raised during entry actions are deferred until after all entry actions complete

- **Priority**: High
- **XState source**: `interpreter.test.ts` lines 1845-1881 ("should not process events sent directly to own actor ref before initial entry actions are processed")
- **Type**: Feature test
- **Scenario**: Create a machine where the root state's entry action raises an event (via `raise`). The raised event would trigger a transition on the root level. Verify: (1) the entry action of the nested initial child state runs BEFORE the raised event is processed, (2) the raised event is processed AFTER all initial entry actions complete.
- **Expected behavior**: Entry actions complete fully before raised events from those entry actions are processed. This is the macrostep semantics — entry actions are part of the current microstep, raised events go into the event queue for the next microstep.
- **Dedup check**: `AlwaysBeforeRaiseTest` tests @always before raised events. `EventProcessingOrderTest` tests entry-before-raise. `RaisedEventTiebreakerTest` tests raise ordering. BUT: none of these specifically test that ALL nested entry actions (root + child) complete before the raised event fires. The XState test verifies root entry start, root entry end, nested entry, THEN raised event. Let me check more specifically.
- **Specific grep**: `AlwaysBeforeRaiseTest` verifies @always > raise priority. `RaisedEventTiebreakerTest` verifies raise ordering between events. Neither verifies "all entry actions (including nested child) complete before raise from parent entry is processed."
- **W1P3 overlap**: None

## Gap XA7: Parallel region: stopping invoke in one region while starting invoke in another (same macrostep)

- **Priority**: Medium
- **XState source**: `invoke.test.ts` lines 2840-2901 ("should invoke a service if other service gets stopped in subsequent microstep #1180")
- **Type**: Feature test
- **Scenario**: Create a parallel machine with two regions. Region "one" has a running delegation. Region "two" is idle. On event NEXT: Region "one" raises STOP_ONE (exiting the invoking state, stopping its delegation), and Region "two" transitions to "active" (starting a new delegation). Verify: (1) Region one's delegation is stopped, (2) Region two's delegation starts and completes successfully, (3) the overall machine reaches @done.
- **Expected behavior**: Within the same macrostep, one parallel region can stop an invoke while another starts one. Both operations should complete correctly without interference.
- **Dedup check**: Grepped "parallel.*one.*stop.*other.*start", "parallel.*region.*stop.*invoke.*start" — no results. `ParallelAdvancedTest` tests various parallel patterns but not the specific stop-in-one-start-in-other pattern. `ParallelDispatchEventQueueCrossRegionTest` tests cross-region events but not invoke lifecycle coordination.
- **W1P3 overlap**: None

## Gap XA8: Child delegation restart on re-entering the delegating state (single macrostep)

- **Priority**: Medium
- **XState source**: `invoke.test.ts` lines 3242-3268 ("should get reinstantiated after reentering the invoking state in a microstep"), `actor.test.ts` lines 1468-1533 (spawned actor restart within macrostep)
- **Type**: Feature test
- **Scenario**: Machine in state A has child delegation. Event GO_AWAY transitions to B. B has @always back to A. Within one macrostep: exit A (stop child), enter B, @always to A (start new child). Verify: (1) the old child is stopped, (2) a new child is started, (3) the new child is a fresh instance (not the old one), (4) order is: stop old, start new.
- **Expected behavior**: When re-entering a state with child delegation via @always within a single macrostep, the old child is stopped and a new child is started. The stop-before-start ordering is guaranteed.
- **Dedup check**: `LcaTransitionTest` tests LCA transitions and mentions "reenter" but covers state entry/exit, not child delegation restart. `ParallelDispatchXStateTest` mentions "reentering" for parallel states. No test specifically verifies child delegation restart on state re-entry within a single macrostep.
- **W1P3 overlap**: None

---

# Summary

| # | Gap Title | Priority | Type |
|---|-----------|----------|------|
| XA1 | Child delegation not started when @always exits delegating state | High | Feature |
| XA2 | Events from completed child are silently discarded | High | Feature |
| XA3 | Child sync completion via @always during parent init -- no crash | High | Feature |
| XA4 | @done from parallel child scoped to correct region | High | Feature |
| XA5 | Rapid sequential events processed in FIFO order | Medium | Feature |
| XA6 | Raised events deferred until all entry actions complete | High | Feature |
| XA7 | Parallel: stop invoke in one region, start in another (same macrostep) | Medium | Feature |
| XA8 | Child delegation restart on state re-entry within macrostep | Medium | Feature |

## Actionable Gaps (8 beads)

All 8 gaps are Feature-level tests (no LocalQA required).
No duplicates with W1 Pass 3 (which is all LocalQA), W3 XState Pass 1 (happy-path theme), or existing tests.
