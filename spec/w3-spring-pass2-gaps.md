# W3 Spring Statemachine Pass 2: Reactive/Async Test Gaps

> Theme: REACTIVE/ASYNC (Spring Statemachine Tier 2, Pass 2)
> Lens: Timer stress tests, event deferral, distributed patterns, ACCEPTED/DENIED/DEFERRED return semantics, security/auth guards
> Generated: 2026-03-25

---

## Source Files Read (complete)

- `/tmp/spring-statemachine/.../ReactiveTests.java` (431 lines, 7 tests)
- `/tmp/spring-statemachine/.../TimerSmokeTests.java` (135 lines, 3 tests)
- `/tmp/spring-statemachine/.../EventDeferTests.java` (589 lines, 9 tests)
- `/tmp/spring-statemachine/.../ensemble/DistributedStateMachineTests.java` (135 lines, 2 tests)
- `/tmp/spring-statemachine/.../ensemble/InMemoryStateMachineEnsemble.java` (71 lines)
- `/tmp/spring-statemachine/.../security/EventSecurityTests.java` (69 lines, 4 tests)
- `/tmp/spring-statemachine/.../security/TransitionSecurityTests.java` (74 lines, 4 tests)
- `/tmp/spring-statemachine/.../security/ActionSecurityTests.java` (210 lines, 2 tests -- @Disabled)
- `/tmp/spring-statemachine/.../security/AbstractSecurityTests.java` (146 lines)
- `/tmp/spring-statemachine/.../security/SecurityRuleTests.java` (49 lines, 3 tests)
- `/tmp/spring-sm-test-summary.md` (full summary from pass 1)
- `/Users/.../spec/w3-spring-pass1-gaps.md` (pass 1 gaps for dedup)

---

## Gap 1: Timer stress test (high-iteration timer + event interleaving)

- **Priority**: High
- **Spring source**: `TimerSmokeTests.testDeadlock` -- 100 iterations of building a machine with `timerOnce(30ms)`, starting it, sending a local "repeate" event while the timer is active, then waiting for completion. Tagged `@Tag("smoke")`. Catches deadlocks and NPEs from timer+event race conditions.
- **Also**: `testNPE` (20 iterations) and `testNPE2` (20 iterations with nested timer states).
- **EventMachine equivalent**: A machine with an `after` timer on a state. In a loop (50+ iterations), create the machine, send an event while the timer is pending (before the timer fires), and verify the machine reaches its final state without exceptions or hangs. Tests the interaction between `machine:process-timers` sweep and manual `send()`.
- **Existing coverage**: Grepped `timer.*stress`, `timer.*smoke`, `timer.*deadlock`, `timer.*iteration`, `timer.*loop` in tests/ -- found `AlwaysLoopOnTimerMachine.php` (stub), `InfiniteLoopQATest.php`, `InfiniteLoopProtectionE2ETest.php`. These test infinite loop detection on timers, NOT timer+event race conditions under repetition. No test sends events while a timer is pending in a stress loop.
- **Dedup**: W3 Spring Pass 1 Gap 1 covers timer re-arm after restore (different concern). W1 gaps do not cover timer stress testing. No overlap.
- **Type**: E2E test (needs artisan timer sweep)
- **Scenario**: Define a machine: `initial --(after:50ms)--> done`, with a local self-transition on `PING` in `initial`. Loop 50 times: create machine, send `PING`, run `machine:process-timers`, assert machine reaches `done` state. No exceptions, no hangs, no stale timer_fires records.

## Gap 2: Send returns indication of event acceptance/rejection (ACCEPTED/DENIED semantics)

- **Priority**: High
- **Spring source**: `ReactiveTests.testMonosAllAccepted` -- `sendEvent()` returns `ResultType.ACCEPTED` when the event triggers a valid transition. `testMonosSomeDenied` -- returns `ResultType.DENIED` when no valid transition exists for the event in the current state. `testFluxsSomeDenied` -- batch of events returns a mix of ACCEPTED and DENIED.
- **EventMachine equivalent**: `Machine::send()` currently returns a `State` object. When an event has no valid transition from the current state, it throws `NoTransitionDefinitionFoundException`. EventMachine uses exception-based signaling rather than return codes. The gap is testing that: (a) valid events succeed and return the new State, (b) invalid events throw the correct exception, (c) the machine state is unchanged after an invalid event.
- **Existing coverage**: Grepped `NoTransitionDefinitionFoundException`, `invalid.*event`, `send.*wrong.*event`, `unhandled.*event` in tests/ -- found `SendToTest.php` and `AsyncMachineDelegationTest.php` mention invalid events but no test explicitly verifies: send invalid event -> exception thrown -> machine state unchanged -> send valid event -> works. `ForwardEndpointQATest.php` tests "unhandled event" at HTTP level.
- **Dedup**: W1 Pass 1 mentioned `availableEvents()` but no gap for the send-and-reject flow. No overlap.
- **Type**: Feature test
- **Scenario**: Create machine in state A with transition `A --(EVENT_X)--> B`. Send `EVENT_Y` (not handled). Assert `NoTransitionDefinitionFoundException`. Assert machine is still in state A. Send `EVENT_X`. Assert machine transitions to B. Test both fresh and persisted machines.

## Gap 3: Event deferral pattern (event consumed later after state change)

- **Priority**: High
- **Spring source**: `ReactiveTests.testMonosSomeDefer` -- sends E3 while in S1 (where E3 is deferred). Returns `ResultType.DEFERRED`. Then sends E2 which transitions to S2. The deferred E3 is automatically consumed in S2 (which handles E3), transitioning to S3.
- **Also**: `EventDeferTests.testDeferWithFlat` -- verifies deferred events accumulate. `testDeferEventCleared` -- deferred event consumed after state change, then cleared from queue. `testSubDeferOverrideSuperTransition` -- sub-state defers event that parent would handle, then upon sub-state exit, deferred event fires at parent level.
- **EventMachine equivalent**: EventMachine does NOT have a first-class event deferral mechanism. Events that are not handled throw `NoTransitionDefinitionFoundException`. There is no deferred event queue. This is a fundamental architectural difference.
- **Existing coverage**: Grepped `defer`, `DEFERRED`, `deferred` in tests/ -- found references in `ParallelDispatchSyncDriverTest.php` and `ParallelDispatchIntegrationTest.php` but these refer to Laravel's deferred service provider, not event deferral semantics.
- **Gap assessment**: This is NOT a test gap -- it is a feature gap. EventMachine intentionally does not implement SCXML event deferral. **No bead needed** -- document as intentional architectural difference.
- **Type**: N/A (architectural difference, not a test gap)

## Gap 4: Parallel region event routing -- per-region ACCEPTED/DENIED results

- **Priority**: Medium
- **Spring source**: `ReactiveTests.testRegions` and `testRegionsAsCollect` -- sends event E1 to a parallel machine. Region 1 handles E1 (ACCEPTED), Region 2 does not (DENIED). `sendEventCollect()` returns a list of 2 results, one ACCEPTED and one DENIED. Proves per-region event routing semantics.
- **EventMachine equivalent**: When an event is sent to a parallel machine, some regions may handle it and others may not. EventMachine should route the event to all regions and only apply it where a matching transition exists. Regions that don't handle it should be unchanged.
- **Existing coverage**: Grepped `parallel.*send.*event`, `region.*event.*routing`, `parallel.*event.*accept` in tests/ -- `ParallelDispatchEventQueueCrossRegionTest.php` tests cross-region event routing but focuses on event queues, not the basic "event hits some regions but not others" case. No test verifies that sending an event handled by only one region transitions that region while leaving others unchanged, with no exception.
- **Dedup**: W3 Spring Pass 1 gaps focus on persistence. W1 gaps don't cover this. No overlap.
- **Type**: Feature test
- **Scenario**: Create parallel machine with region_a (state_a1 -> state_a2 on EVENT_X) and region_b (state_b1 -> state_b2 on EVENT_Y). Send EVENT_X. Assert region_a transitions to state_a2, region_b stays at state_b1. No exception thrown.

## Gap 5: Concurrent event sending to same machine (ConcurrentModification smoke)

- **Priority**: High
- **Spring source**: `EventDeferTests.testDeferSmokeExecutorConcurrentModification` -- Two threads each send 200 events (E1/E2 pairs) to the same machine simultaneously. Asserts no ConcurrentModificationException or any other throwable. Tagged `@Tag("smoke")` with 20s timeout. Regression test for executor thread safety.
- **EventMachine equivalent**: Two processes/workers sending events to the same machine root_event_id simultaneously. Tests the locking mechanism (`MachineLockManager`) and prevents data corruption.
- **Existing coverage**: `AsyncEdgeCasesTest.php` (LocalQA) tests async child delegation but not concurrent sends to the same machine. `ParallelDispatchEventQueueCrossRegionTest.php` mentions "race condition" in concept but tests it at region level. No test has two concurrent `Machine::send()` calls to the same machine instance.
- **Dedup**: W1 Pass 3 (async/race) may cover this later but it hasn't been created yet. No existing gap covers this. No overlap.
- **Type**: LocalQA test (needs real database + locking)
- **Scenario**: Create machine with states A -> B -> A (cycle on two events). Dispatch two concurrent jobs that each send 20 event cycles to the same machine. Assert no `MachineAlreadyRunningException` corruption (one should win the lock, the other should get the exception or wait). Assert machine is in a valid state after both complete.

## Gap 6: Distributed state machine synchronization (ensemble pattern)

- **Priority**: Low (architectural N/A)
- **Spring source**: `DistributedStateMachineTests.testMachines` -- Two machine instances share state via `InMemoryStateMachineEnsemble`. Event sent to machine1 is reflected in machine2's state. `testJoin` -- machine2 joins after machine1 has already transitioned; machine2 catches up to current state.
- **EventMachine equivalent**: EventMachine uses database-backed persistence (not in-memory ensemble). Two Machine instances reading the same `root_event_id` see the same state via event replay. There is no explicit "ensemble" concept.
- **Gap assessment**: EventMachine's persistence model (event sourcing via `machine_events` table) inherently provides this: any Machine instance that restores from the same root_event_id gets the same state. The "join" pattern (late joiner catches up) is also inherently handled by event replay.
- **Existing coverage**: Event replay and restore-from-rootEventId are tested in various persistence tests. The distributed sync concern is implicitly covered.
- **Type**: N/A (covered by design). However, a small test verifying two Machine instances reading the same root_event_id after transitions see identical state would be worthwhile.
- **Scenario**: Create machine, transition to state B, persist. Create two separate Machine::restoreState() calls for the same rootEventId. Assert both return identical state. Send event to one, persist. Restore from same rootEventId in the other. Assert states match.

## Gap 7: Event routing semantics for sub-states vs parent (defer vs bubble)

- **Priority**: Medium
- **Spring source**: `EventDeferTests.testSubNotDeferOverrideSuperTransition` -- sub-state SUB11 does NOT defer E15, so E15 is handled by parent SUB1's transition (SUB1 -> SUB5). `testSubDeferOverrideSuperTransition` -- sub-state SUB12 DOES defer E15, so parent transition is blocked. When sub-state changes back to SUB11 (which doesn't defer), the deferred E15 fires.
- **EventMachine equivalent**: EventMachine uses hierarchical state matching where child states can handle events before parents. If a child doesn't handle an event, it bubbles to the parent. There is no "defer" to prevent parent handling.
- **Existing coverage**: Compound state event bubbling is tested in `CompoundStateTransitionTest.php` and similar. But no test explicitly verifies: (a) child state has no handler -> event bubbles to parent, (b) this works correctly when switching between child states that do/don't handle the event.
- **Dedup**: No existing gap covers child-to-parent event bubbling with child state switching. No overlap.
- **Type**: Feature test
- **Scenario**: Compound state P with child states C1 and C2. Parent P has transition on EVENT_X -> target. C1 has no transition on EVENT_X. C2 has a transition on EVENT_X -> C1. In C1: send EVENT_X -> assert parent transition fires. In C2: send EVENT_X -> assert child transition fires (stays in P). Switch to C1 again -> send EVENT_X -> parent transition fires.

## Gap 8: Region deferral semantics -- event deferred only when ALL regions defer

- **Priority**: Medium
- **Spring source**: `EventDeferTests.testRegionOneDeferTransition` -- in a parallel state, if one region defers E3 but the other doesn't have E3 configured, the event is NOT deferred (deferList is empty). `testRegionAllDeferTransition` -- when ALL regions defer E3, THEN it is deferred. `testRegionNotDeferTransition` -- when neither region defers, event handled normally.
- **EventMachine equivalent**: In EventMachine parallel states, an event is routed to all regions. If no region handles it, it bubbles to the parent. The "all regions must defer" semantics don't apply since EventMachine has no deferral. However, the underlying question is: what happens when an event is sent to a parallel state and NO region handles it? Does it bubble to parent or throw?
- **Existing coverage**: No test explicitly sends an event to a parallel machine where no region handles it and verifies the behavior (bubble to parent vs exception).
- **Dedup**: Gap 4 covers "some regions handle, some don't". This gap covers "no regions handle at all". Different scenario.
- **Type**: Feature test
- **Scenario**: Parallel machine with regions A and B. Neither region handles EVENT_Z. Parent has transition on EVENT_Z -> target. Send EVENT_Z. Verify it bubbles to parent transition. If no parent transition either, verify appropriate exception or no-op behavior.

## Gap 9: Endpoint/HTTP middleware as event-level security (Spring security analog)

- **Priority**: Low
- **Spring source**: `EventSecurityTests` -- events can be secured by role attributes or SpEL expressions. `testNoSecurityContext` -- no auth context means event DENIED. `testEventDeniedViaAttributes` -- wrong role means DENIED. `testEventAllowedViaAttributes` -- correct role means transition allowed.
- **Also**: `TransitionSecurityTests` -- transitions secured by role. `ActionSecurityTests` (disabled) -- actions secured by @Secured annotation.
- **EventMachine equivalent**: EventMachine uses Laravel middleware on HTTP endpoints (`MachineRouter` + `EndpointDefinition`). Guards (`ValidationGuardBehavior`) can also reject events. Middleware handles auth, guards handle business rules.
- **Existing coverage**: `MachineRouterTest.php` tests middleware configuration. `EndpointDefinitionTest.php` tests middleware parsing. But no test verifies the full flow: unauthenticated request -> middleware rejects -> machine state unchanged. Authenticated request -> transitions succeed.
- **Dedup**: No existing gap covers middleware-as-security for machine endpoints. No overlap.
- **Type**: Feature test (HTTP)
- **Scenario**: Define machine endpoint with `auth` middleware. Send unauthenticated request -> 401/403, machine unchanged. Send authenticated request -> transition succeeds, 200. Tests the EventMachine analog of Spring's event security.

---

# Summary

| # | Gap Title | Priority | Type | Actionable? |
|---|-----------|----------|------|-------------|
| 1 | Timer stress test (50+ iterations, timer+event interleave) | High | E2E | Yes |
| 2 | Send event ACCEPTED/DENIED semantics (exception + state unchanged) | High | Feature | Yes |
| 3 | Event deferral pattern | High | N/A | No (architectural difference) |
| 4 | Parallel region per-region event routing (some handle, some don't) | Medium | Feature | Yes |
| 5 | Concurrent event sending smoke test (locking) | High | LocalQA | Yes |
| 6 | Distributed/ensemble state sync | Low | N/A | Partial (small verify test) |
| 7 | Sub-state vs parent event routing (bubble semantics) | Medium | Feature | Yes |
| 8 | Parallel state -- event unhandled by all regions (bubble to parent) | Medium | Feature | Yes |
| 9 | Endpoint middleware as event security | Low | Feature | Yes |

## Actionable Gaps (7 beads needed)

1. **Gap 1** -- Timer stress test: 50+ iteration timer+event interleaving (E2E, High)
2. **Gap 2** -- Send event ACCEPTED/DENIED: invalid event -> exception -> state unchanged -> valid event works (Feature, High)
3. **Gap 4** -- Parallel region event routing: event handled by some regions, not others (Feature, Medium)
4. **Gap 5** -- Concurrent event sending smoke: two workers send to same machine (LocalQA, High)
5. **Gap 7** -- Sub-state vs parent event bubbling with child state switching (Feature, Medium)
6. **Gap 8** -- Parallel unhandled event bubbles to parent (Feature, Medium)
7. **Gap 9** -- Endpoint middleware security flow (Feature, Low)

## Non-Actionable (documented as architectural differences)

- **Gap 3** -- Event deferral: EventMachine intentionally does not implement SCXML event deferral
- **Gap 6** -- Distributed ensemble: Inherently covered by EventMachine's event-sourced persistence model
