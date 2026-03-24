# W1 Pass 4: Failure / Recovery / Timeout Gaps

> Theme: FAILURE / RECOVERY / TIMEOUT
> Lens: What happens when things fail? Is recovery tested? Exception paths, @fail, @timeout, archive/restore after crash, lock TTL expiry, region failure propagation.
> Generated: 2026-03-25

---

## Dedup Check Against Pass 1 and Pass 2

Pass 1 beads (14 open) -- all focus on happy-path semantic correctness:
- `ht-w1p1-guard-first-match` -- guard ordering (happy)
- `ht-w1p1-guard-no-side-effects` -- guard purity (happy)
- `ht-w1p1-action-ordering-scxml` -- action ordering (happy)
- `ht-w1p1-deep-hierarchy-ordering` -- deep hierarchy (happy)
- `ht-w1p1-lca-sibling` -- LCA sibling (happy)
- `ht-w1p1-initial-always-chain` -- @always chain (happy)
- `ht-w1p1-child-over-parent-priority` -- event priority (happy)
- `ht-w1p1-self-transition-exit-entry` -- self-transition (happy)
- `ht-w1p1-targetless-no-exit-entry` -- targetless (happy)
- `ht-w1p1-parallel-done-all-final` -- parallel @done (happy)
- `ht-w1p1-context-roundtrip-fidelity` -- context round-trip (happy)
- `ht-w1p1-self-transition-resets-child` -- child reset (happy)
- `ht-w1p1-cache-clear-commands` -- cache commands (happy)
- `ht-w1p1-incremental-context-diff` -- incremental diff (happy)

Pass 2 beads (15 open) -- all focus on edge cases and boundary conditions:
- Single-region parallel, all guards false, compound one child, extreme context values, empty payload, single final state, no-op self-transition, max_transition_depth=1, negative depth, guard null context, all-immediate parallel, LCA child-to-ancestor, availableEvents no transitions, @always empty guards, send to final state.

None overlap with the failure/recovery gaps below.

---

## Gap 1: Entry action throws exception -- machine state not corrupted

- **Priority**: High
- **Source**: Problem #4.8, #1.3
- **Type**: Feature test
- **Scenario**: Define a machine where a state's entry action throws a RuntimeException. Transition into that state. Verify: (1) the exception propagates to the caller, (2) the machine state is NOT left in a half-transitioned/corrupted state, (3) subsequent events can still be processed (if the machine was persisted before the crash).
- **Expected behavior**: Entry action exception propagates cleanly. Machine state remains at the source state (transition rolled back) or is in a consistent error state. No partial context writes.
- **Stub machine needed**: No (inline TestMachine::define with throwing inline action)
- **Dedup check**: Grepped for "entry.*action.*throw", "entry.*action.*exception" in tests/ -- not found. `InfiniteLoopProtectionE2ETest.php` tests "exception does not corrupt machine state" but only for MaxTransitionDepthExceededException, not for arbitrary action exceptions. `MachineControllerTest.php` tests throwing actions but only through HTTP endpoint layer, not the raw Machine::transition path.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 2: Exit action throws exception -- machine state consistency

- **Priority**: High
- **Source**: Problem #1.3, #4.8
- **Type**: Feature test
- **Scenario**: Define a machine where a state's exit action throws a RuntimeException. Trigger a transition that exits that state. Verify: (1) the exception propagates, (2) the machine state is not corrupted (either stays at source or enters error), (3) the target state's entry action did NOT execute (since exit failed first).
- **Expected behavior**: When an exit action throws, the transition is aborted. Entry actions of the target state must not have executed.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "exit.*action.*throw", "exit.*action.*exception" in tests/ -- not found. No test exercises an exit action that throws.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 3: Transition action throws exception -- partial action execution

- **Priority**: High
- **Source**: Problem #1.3, #4.8
- **Type**: Feature test
- **Scenario**: Define a transition with multiple actions where the second action throws. Verify: (1) the first action executed, (2) the second action threw, (3) the machine state is consistent (not half-transitioned).
- **Expected behavior**: Exception propagates. If transaction protection exists, all context mutations from the first action are rolled back.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "transition.*action.*throw", "action.*fail.*during.*transition" in tests/ -- not found. `CalculatorsWithGuardedTransitions` tests calculator failures preventing guard/action execution, but not mid-action-list exceptions.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 4: Guard that throws exception (not ValidationGuard) -- behavior

- **Priority**: High
- **Source**: Problem #1.2
- **Type**: Feature test
- **Scenario**: Define a guard that throws a RuntimeException (not a ValidationGuardBehavior, just a regular GuardBehavior). Verify: (1) the exception propagates, (2) the machine does not transition, (3) no subsequent guards or actions execute, (4) the machine state is unchanged.
- **Expected behavior**: A throwing guard stops evaluation. Exception propagates. Machine stays in its current state.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: Grepped for "guard.*throw", "guard.*exception" in tests/ -- found `ParallelAlwaysTransitionsTest.php` "always transition with guard in parallel state does not throw when guard fails" but that tests guards returning false, not guards THROWING. No test for a guard that throws an exception (as opposed to returning false or a ValidationGuardBehavior).
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 5: Calculator throws exception -- blocks guard and action execution

- **Priority**: Medium
- **Source**: Problem #1.3
- **Type**: Feature test -- verify existing behavior explicitly for failure path
- **Scenario**: Already partially covered by `CalculatorTest.php` line 197 and `CalculatorsWithGuardedTransitions` line 133. However, the existing tests verify the NEXT transition branch is tried. What's missing: verify that when ALL branches' calculators fail, the machine stays in its current state and the exception is properly handled (not a silent no-op).
- **Expected behavior**: When all calculators across all transition branches fail, the event is unhandled. Machine stays in state. Appropriate exception or event recorded.
- **Stub machine needed**: No
- **Dedup check**: Existing tests cover single-branch calculator failure with fallback. No test covers ALL branches failing.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 6: @fail with guarded branches -- all @fail guards false

- **Priority**: High
- **Source**: Problem #3.6, #5.1
- **Type**: Feature test
- **Scenario**: Define a parent machine delegating to a child. The @fail has multiple guarded branches but ALL guards return false. The child throws. Verify the exception is re-thrown (since no @fail branch matched) rather than silently swallowed.
- **Expected behavior**: When child fails and no @fail guard matches, the exception should propagate to the caller. The machine should not silently stay in the delegating state.
- **Stub machine needed**: No (inline definition with FailingChildMachine)
- **Dedup check**: `ConditionalOnFailTest.php` tests "aborts @fail when all guards fail" but this is for PARALLEL state @fail, not child delegation @fail. `MachineDelegationTest.php` tests @fail with guards but always has at least one matching guard or an unguarded default. No test for child delegation where ALL @fail guards are false.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 7: @timeout fires when child does not complete (sync unit test)

- **Priority**: High
- **Source**: Problem #4.8, #4.9
- **Type**: Feature test
- **Scenario**: Use `simulateChildTimeout` to test parent @timeout routing in a unit test (not LocalQA). Verify the parent transitions to the @timeout target state and context captures timeout information.
- **Expected behavior**: Parent machine transitions to the @timeout target state. Timeout event payload contains the child machine class.
- **Stub machine needed**: No (uses existing AsyncTimeoutParentMachine)
- **Dedup check**: `TestMachineV2Test.php` line 403 tests `simulateChildTimeout` for machine delegation and line 533 for job actors. However, these only test the basic transition. Missing: (1) @timeout with guarded branches, (2) @timeout when parent already moved to another state (timeout arrives late), (3) @timeout with actions that modify context. The basic case IS covered by V17. Adjusted to focus on the UNCOVERED sub-scenarios below.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 8: @timeout with guarded branches -- routing by timeout context

- **Priority**: Medium
- **Source**: Problem #4.8
- **Type**: Feature test
- **Scenario**: Define @timeout with multiple guarded branches that route to different states based on context (e.g., retry count). Simulate child timeout. Verify the correct @timeout branch is selected based on context.
- **Expected behavior**: @timeout guard evaluation follows the same first-match semantics as regular transitions. The matching branch's target state is entered.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: No test for @timeout with guarded branches. All existing @timeout tests use a simple target string.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 9: Child completion after parent @timeout -- late @done ignored

- **Priority**: High
- **Source**: Problem #4.8
- **Type**: Feature test (or LocalQA if async needed)
- **Scenario**: Parent delegates to child with @timeout. Simulate timeout first (parent moves to timed_out). Then simulate child done. Verify: (1) parent stays in timed_out state, (2) the late @done event is silently ignored (or throws NoTransitionDefinition), (3) no state corruption.
- **Expected behavior**: Once the parent has transitioned via @timeout, a late @done/child completion should not cause a second transition. The parent is no longer in the delegating state.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: `LocalQA/ReviewFixesTest.php` line 324 tests "timeout job is no-op when child completes before timeout" (opposite direction -- timeout after completion). `WebhookAutoCompletionTest.php` line 207 mentions "child already failed (parent already routed @fail)" for discard. But no test for: parent already timed-out, then child completes. This is the inverse scenario.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 10: Child @fail after parent already transitioned -- late failure ignored

- **Priority**: Medium
- **Source**: Problem #4.8, #5.1
- **Type**: Feature test
- **Scenario**: Parent delegates to child. An external event transitions parent out of the delegating state (to a different state). Then child fails. Verify the late @fail does not corrupt the parent.
- **Expected behavior**: If the parent is no longer in the delegating state, the child's failure event cannot find a @fail handler. It should be silently ignored or throw a clear error (not corrupt state).
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: `WebhookAutoCompletionTest.php` line 207 has a comment about "child already failed (parent already routed @fail)" but tests the case where the parent has already handled ONE failure. No test for parent having left the delegation state entirely via a different mechanism.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 11: Parallel region failure propagation -- Region A fails, Region B already done

- **Priority**: High
- **Source**: Problem #3.6
- **Type**: Feature test
- **Scenario**: Parallel state with two regions. Region B reaches final state first. Then Region A fails. Verify: (1) @fail fires (not @done, even though B is final), (2) machine transitions to the @fail target, (3) Region B's final state results are accessible in the @fail action's context.
- **Expected behavior**: A region failure always takes precedence over partial completion. Even though one region is final, the other's failure triggers @fail. The machine does NOT stay waiting for @done.
- **Stub machine needed**: No (inline or existing ParallelDispatchWithFailMachine)
- **Dedup check**: `ParallelDispatchFailSiblingTest.php` tests "Region A fails, Region B sees machine already in failed". `ParallelDispatchFailBasicTest.php` tests basic fail. `ParallelDispatchFailEdgeCasesTest.php` tests edge cases. However, the specific scenario of "Region B already at final when Region A fails" is not explicitly tested in the feature tests (only E2E with real dispatch). A unit-level test for this ordering is missing.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 12: Both parallel regions fail simultaneously -- only one @fail fires

- **Priority**: High
- **Source**: Problem #3.3, #3.6
- **Type**: Feature test
- **Scenario**: Both regions fail at the same time (in sync mode). Verify: (1) @fail fires exactly once, (2) machine transitions to error state exactly once, (3) PARALLEL_FAIL event appears exactly once in history.
- **Expected behavior**: Only one @fail transition fires even when both regions fail. The second region's failure is suppressed because the machine has already transitioned out of the parallel state.
- **Stub machine needed**: No (uses E2EBothFailMachine or inline)
- **Dedup check**: `E2E/ParallelDispatchE2ETest.php` tests both-fail E2E with real dispatch. `ParallelDispatchPlanComplianceTest.php` line 499 tests both-fail scenario. However, these test with the dispatch (async) driver. No sync-mode test for both-regions-fail verifying exactly one @fail event in history.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 13: Region timeout race with completion -- timeout fires but region just completed

- **Priority**: Medium
- **Source**: Problem #3.11
- **Type**: Feature test
- **Scenario**: All regions complete (machine transitions via @done). Then `ParallelRegionTimeoutJob` fires. Verify: (1) timeout is a no-op, (2) machine stays in the @done target state, (3) no error.
- **Expected behavior**: The timeout job checks if the machine is still in the parallel state. Since it already transitioned to @done target, the timeout is ignored.
- **Stub machine needed**: No
- **Dedup check**: `ParallelDispatchRegionTimeoutTest.php` line 59 tests "timeout job is no-op when all regions are already final" which tests the case where regions are final but @done hasn't fired yet. Missing: the case where @done HAS already fired and the machine left the parallel state entirely. This is a slightly different scenario (machine is in a post-parallel state, not still in parallel-with-all-final).
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 14: Archive restore after corrupted archive -- graceful error

- **Priority**: Medium
- **Source**: Problem #6.5
- **Type**: Feature/Integration test
- **Scenario**: Archive a machine. Corrupt the archive data (e.g., truncate compressed data, set to random bytes). Attempt to restore. Verify: (1) a clear exception is thrown, (2) original events are not affected, (3) the machine can still be used if events exist in machine_events table.
- **Expected behavior**: Corrupted archive restoration throws a specific exception with diagnostic information. It does not silently return empty data or partial events.
- **Stub machine needed**: No
- **Dedup check**: `ArchiveEdgeCasesTest.php` line 222 tests "throws exception when decompressing corrupted data" and line 255 tests "invalid JSON in CompressionManager decompress". These test the compression/decompression layer but do NOT test the full `ArchiveService::restoreMachine()` path with a corrupted archive. The existing tests use `MachineEventArchive::restoreEvents()` directly. Missing: full-stack test via `ArchiveService::restoreMachine()` with corrupted archive, verifying the machine instance is not left in a broken state.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 15: Archive of machine with active child -- child reference integrity

- **Priority**: Medium
- **Source**: Problem #6.5
- **Type**: Integration test
- **Scenario**: Parent machine delegates to async child. While child is running, archive the parent. Then child completes. Verify: (1) parent archive contains the delegation state, (2) child completion triggers auto-restore of parent, (3) parent correctly processes @done after restore.
- **Expected behavior**: Archiving a parent with an active child is either rejected (safeguard) or works with auto-restore handling the child's completion event.
- **Stub machine needed**: No (uses existing async delegation stubs)
- **Dedup check**: `AutoRestoreTest.php` tests auto-restore on new event creation. `ArchiveLifecycleTest.php` tests the full lifecycle. Neither tests archiving a machine while it has an active child delegation. `ArchiveConcurrencyTest.php` tests concurrent archive operations but not child-reference integrity.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 16: Lock TTL expiry -- stale lock cleaned up, next operation succeeds

- **Priority**: Medium
- **Source**: Problem #4.6
- **Type**: Feature test
- **Scenario**: Simulate a crashed worker by inserting a lock row with an expired `expires_at`. Attempt a new operation on the same machine. Verify: (1) the stale lock is detected and cleaned, (2) the new operation acquires the lock successfully, (3) the machine operates normally after cleanup.
- **Expected behavior**: Expired locks are cleaned up transparently. No manual intervention needed.
- **Stub machine needed**: No
- **Dedup check**: `ParallelDispatchLockInfraTest.php` line 99 tests "stale lock is cleaned up before new acquisition". This covers the basic case. HOWEVER, it tests via `MachineLockManager::acquire()` directly. Missing: a test that goes through `Machine::send()` to verify the full path (send -> lock acquisition -> stale cleanup -> transition succeeds) works end-to-end.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 17: MachineAlreadyRunningException -- caller gets clear rejection with context

- **Priority**: Medium
- **Source**: Problem #4.2, #4.4
- **Type**: Feature test
- **Scenario**: Hold a lock on a machine (simulate in-progress processing). Send another event to the same machine instance with timeout=0. Verify: (1) MachineAlreadyRunningException is thrown, (2) the exception message includes the machine ID, (3) the first operation completes successfully after the second is rejected.
- **Expected behavior**: Concurrent send with immediate timeout throws MachineAlreadyRunningException with diagnostic context. The original processing is not affected.
- **Stub machine needed**: No
- **Dedup check**: `TransitionsTest.php` line 138 tests MachineAlreadyRunningException is thrown. `ParallelDispatchLockModesTest.php` line 52 tests immediate lock mode throws MachineAlreadyRunningException. These cover the exception being thrown. Missing: verification that the exception contains machine ID / diagnostic info, and that the first operation completes correctly after rejection.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 18: @fail action can access error details from child exception

- **Priority**: Medium
- **Source**: Problem #5.1
- **Type**: Feature test
- **Scenario**: Child machine throws with a specific error message. Parent's @fail action receives the event. Verify: (1) the @fail event payload contains the error message, (2) the @fail action can store error details in context, (3) the error class name is accessible.
- **Expected behavior**: The @fail event payload includes `error_message` and possibly `error_class` from the child's exception.
- **Stub machine needed**: No (uses FailingChildMachine)
- **Dedup check**: `MachineDelegationTest.php` line 130 tests "@fail routes and captures error message". `ParallelDispatchFailContextTest.php` tests context in parallel fail. The basic case IS covered. However, no test verifies the exception class name is available in the @fail payload. Also, no test for a child that throws a CUSTOM exception (not RuntimeException) and verifies the class name propagates.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 19: @fail with ProvidesFailureContext -- structured error data

- **Priority**: Medium
- **Source**: Problem #5.1 and Architecture docs (ProvidesFailureContext contract)
- **Type**: Feature test
- **Scenario**: Create a child machine exception that implements `ProvidesFailureContext`. The exception provides structured context (error code, metadata). Verify the parent's @fail action receives this structured context in the event payload.
- **Expected behavior**: When a child exception implements ProvidesFailureContext, its `failureContext()` data is merged into the @fail event payload.
- **Stub machine needed**: Yes (a FailureContext-implementing exception class)
- **Dedup check**: Grepped for "ProvidesFailureContext" in tests/ -- found only in stubs/machines definitions, no dedicated test file. No test exercises this interface.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 20: Job actor @fail -- failing Laravel job routes parent correctly

- **Priority**: Medium
- **Source**: Problem #4.8
- **Type**: Feature test
- **Scenario**: Parent machine delegates to a Laravel job (via `job` key). The job fails. Verify: (1) @fail fires, (2) parent transitions to error state, (3) error details from the job exception are captured.
- **Expected behavior**: Job failure triggers @fail on parent, same as child machine failure.
- **Stub machine needed**: Uses existing FailingJobActorParentMachine
- **Dedup check**: `JobActorTest.php` line 186-200 tests job actor failure with RuntimeException. `TestMachineV2Test.php` line 501 tests `simulateChildFail` for job actors. However, `JobActorTest.php` tests the real flow with `ChildJobJob::failed()`. These cover the basic case. Missing: (1) job @fail with guarded branches, (2) job timeout (not failure) routing.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 21: Job actor @timeout -- job exceeds time limit

- **Priority**: Medium
- **Source**: Problem #4.8
- **Type**: Feature test
- **Scenario**: Parent machine delegates to a job with @timeout configured. Simulate job timeout via `simulateChildTimeout`. Verify parent transitions to @timeout target.
- **Expected behavior**: Job timeout routes to @timeout target, same as child machine timeout.
- **Stub machine needed**: No
- **Dedup check**: `TestMachineV2Test.php` line 511 "V18c: simulateChildTimeout routes @timeout for job actors" covers this basic case. COVERED -- skip.

## Gap 22: Fire-and-forget child failure -- no parent impact

- **Priority**: Medium
- **Source**: Problem #5.3
- **Type**: Feature test
- **Scenario**: Parent uses fire-and-forget delegation (no @done). Child fails. Verify: (1) parent is unaffected, (2) parent state is unchanged, (3) MachineChild record still exists for tracking.
- **Expected behavior**: Fire-and-forget child failure does not affect parent. No @fail handler fires (none defined).
- **Stub machine needed**: No (uses existing FireAndForgetParentMachine)
- **Dedup check**: `FireAndForgetMachineDelegationTest.php` line 388 tests "fire-and-forget child failure: parent stays in final state". This covers the basic case. Missing: verification that MachineChild record is retained for observability after failure. Also missing: child failure with error logging (does the exception get silently swallowed or logged?).
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 23: @always chain exception -- max depth vs action exception

- **Priority**: Medium
- **Source**: Problem #1.7, #4.8
- **Type**: Feature test
- **Scenario**: @always chain: state A -> @always -> state B (entry action throws). Verify: (1) the exception propagates (not caught by max-depth), (2) the error is different from MaxTransitionDepthExceededException, (3) the machine state is consistent.
- **Expected behavior**: An action exception during @always processing propagates immediately. It does not trigger the max-depth protection (that's for loops, not crashes).
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: `MaxTransitionDepthTest.php` only tests the depth limit. `InfiniteLoopProtectionE2ETest.php` tests depth protection E2E. No test combines @always chains with actions that throw exceptions.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 24: Restore machine from events when definition has changed (unknown state)

- **Priority**: Medium
- **Source**: Problem #6.4
- **Type**: Integration test
- **Scenario**: Create a machine, persist it in state "processing". Change the machine definition to remove the "processing" state. Attempt to restore. Verify: (1) a clear error is thrown (not a null reference), (2) the error message identifies the unknown state, (3) the event history is not corrupted.
- **Expected behavior**: Restoring a machine whose current state no longer exists in the definition produces a clear, actionable error.
- **Stub machine needed**: Two versions of a machine definition (before/after state removal)
- **Dedup check**: Grepped for "restore.*unknown", "unknown.*state.*restore", "schema.*migration" in tests/ -- not found. `TransitionDefinitionTest.php` tests "unknown state" for transitions, not restoration.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 25: Partial archive recovery -- archive process crashes midway

- **Priority**: Low
- **Source**: Problem #6.5
- **Type**: Integration test
- **Scenario**: Start archiving a machine (events copied to archive table). Simulate a crash before the original events are deleted. Verify: (1) both archive and original events exist, (2) a subsequent archive attempt detects the duplicate, (3) the machine can still be restored from the original events.
- **Expected behavior**: Partial archive does not cause data loss. The system can detect and recover from partial archive state.
- **Stub machine needed**: No
- **Dedup check**: `ArchiveConcurrencyTest.php` tests "does not fail when restoreAndDelete is called for non-existent archive". No test for the inverse: archive exists AND original events exist simultaneously.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 26: Timer fire after state exit -- stale timer skipped

- **Priority**: Medium
- **Source**: Problem #4.9, #7.3
- **Type**: Feature test (in-memory timer testing)
- **Scenario**: Machine enters state with `after` timer. Before timer fires, an event transitions the machine to another state. Timer sweep runs. Verify: (1) the timer does NOT fire, (2) MachineCurrentState was updated so the timer check detects the state change.
- **Expected behavior**: Timer fires check the current state before executing. If the machine has left the timer's state, the fire is skipped.
- **Stub machine needed**: No (uses existing AfterTimerMachine or inline)
- **Dedup check**: E2E timer tests cover this implicitly (timer cancelled state). `TimerVerificationTest.php` line 263 tests timer cancellation resulting in cancelled state. `TimerTestingInMemoryTest.php` tests in-memory timer helpers. However, no test explicitly verifies: enter state with timer, exit via event, then advance timers, assert timer did NOT fire. The existing tests verify the machine reaches a "cancelled" state, but that's because a CANCEL event was sent before the timer -- not because the timer was stale.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 27: Recurring timer `every` with max -- fires exactly N times then stops

- **Priority**: Medium
- **Source**: Problem #7.4
- **Type**: Feature test (in-memory timer testing)
- **Scenario**: Define `every` timer with `max: 3`. Use in-memory timer helpers to advance time. Verify: (1) timer fires exactly 3 times, (2) after 3 fires, advancing time further produces no more fires, (3) the count is tracked correctly.
- **Expected behavior**: `every` timer respects the `max` count and stops after N fires.
- **Stub machine needed**: No (uses existing EveryWithMaxMachine)
- **Dedup check**: `E2E/TimerEveryE2ETest.php` tests every-with-max E2E. `TimerEdgeCasesTest.php` may test edge cases. Let me check -- Grepped for "max.*timer", "timer.*max", "every.*max" in tests/Features -- found `TimerEdgeCasesTest.php` and `TestMachineTimerHelpersTest.php`. The in-memory test coverage may exist. This needs careful verification. If E2E covers it fully, this may be a duplicate. Marking as medium priority for verification.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 28: sendTo to non-existent machine -- error handling

- **Priority**: Medium
- **Source**: Problem #5.2
- **Type**: Feature test
- **Scenario**: Use `sendTo` (sync) to send an event to a machine ID that does not exist in the database. Verify: (1) a clear exception is thrown, (2) the exception includes the target machine ID, (3) the sending machine's state is not affected.
- **Expected behavior**: Sending to a non-existent machine throws a descriptive exception. The sender's transaction is unaffected.
- **Stub machine needed**: No
- **Dedup check**: Grepped for "sendTo.*non.existent", "sendTo.*missing", "target.*not.*found" in tests/ -- not found. `SendToTest.php` tests successful sendTo. No test for sendTo failure when target doesn't exist.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 29: dispatchTo archived machine -- auto-restore triggered

- **Priority**: Medium
- **Source**: Problem #5.2
- **Type**: Integration test
- **Scenario**: Create machine B, archive it. Machine A uses `dispatchTo` to send event to Machine B. Verify: (1) auto-restore kicks in, (2) Machine B processes the event correctly after restoration, (3) Machine B's archive is deleted after restoration.
- **Expected behavior**: Sending an event to an archived machine transparently restores it and processes the event.
- **Stub machine needed**: No
- **Dedup check**: `AutoRestoreTest.php` tests auto-restore when new event is saved. `ArchiveTransparencyTest.php` line 102 tests "auto-restores archived events when new event is created". These test the auto-restore mechanism on event creation. Missing: testing the full dispatchTo -> SendToMachineJob -> auto-restore -> process-event chain.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 30: ValidationGuardBehavior in non-parallel context -- @fail-like behavior

- **Priority**: Medium
- **Source**: Problem #3.6 (parallel guard semantics section)
- **Type**: Feature test
- **Scenario**: Use a ValidationGuardBehavior on a transition in a NON-parallel state. The validation fails. Verify: (1) MachineValidationException is thrown, (2) machine stays in current state, (3) no actions executed.
- **Expected behavior**: ValidationGuardBehavior throws MachineValidationException in non-parallel context (same as parallel context for sync mode).
- **Stub machine needed**: No
- **Dedup check**: `ParallelValidationGuardTest.php` tests ValidationGuard in parallel context. `MachineControllerTest.php` tests through HTTP. `ActionsTest.php` tests basic ValidationGuard. Missing: explicit test for ValidationGuardBehavior in a simple (non-parallel, non-endpoint) context verifying the machine state is unchanged after the exception.
- **Workflow**: Use /agentic-commits. Run composer quality.

---

# Summary

| # | Gap Title | Priority | Dedup Status |
|---|-----------|----------|--------------|
| 1 | Entry action throws -- state not corrupted | High | Not covered |
| 2 | Exit action throws -- state consistency | High | Not covered |
| 3 | Transition action throws -- partial execution | High | Not covered |
| 4 | Guard throws RuntimeException -- stops evaluation | High | Not covered |
| 5 | All calculators fail across all branches | Medium | Not covered |
| 6 | @fail guarded branches all false -- exception re-thrown | High | Not covered |
| 7 | @timeout fires for child (basic) | High | COVERED by V17 -- SKIP |
| 8 | @timeout with guarded branches | Medium | Not covered |
| 9 | Child completion after parent @timeout -- late @done | High | Not covered |
| 10 | Child @fail after parent already transitioned | Medium | Not covered |
| 11 | Region A fails, Region B already done | High | Not covered (unit-level) |
| 12 | Both regions fail -- single @fail (sync mode) | High | Not covered (sync) |
| 13 | Region timeout after @done -- no-op | Medium | Partially covered |
| 14 | Archive restore corrupted -- full ArchiveService path | Medium | Partially covered |
| 15 | Archive with active child -- reference integrity | Medium | Not covered |
| 16 | Lock TTL expiry via Machine::send() path | Medium | Partially covered |
| 17 | MachineAlreadyRunningException diagnostic context | Medium | Partially covered |
| 18 | @fail action accesses error details | Medium | Partially covered |
| 19 | ProvidesFailureContext structured error data | Medium | Not covered |
| 20 | Job actor @fail with guarded branches | Medium | Not covered |
| 21 | Job actor @timeout | Medium | COVERED -- SKIP |
| 22 | Fire-and-forget child failure observability | Medium | Partially covered |
| 23 | @always chain entry action exception | Medium | Not covered |
| 24 | Restore with unknown state (definition changed) | Medium | Not covered |
| 25 | Partial archive recovery | Low | Not covered |
| 26 | Timer fire after state exit -- stale skipped | Medium | Not covered (unit) |
| 27 | Every timer max fires exactly N | Medium | Needs verification |
| 28 | sendTo non-existent machine | Medium | Not covered |
| 29 | dispatchTo archived machine auto-restore | Medium | Not covered |
| 30 | ValidationGuard non-parallel context | Medium | Partially covered |

## Actionable Gaps (26 beads)

Excluding Gap 7 (covered) and Gap 21 (covered):

1. **Gap 1** -- Entry action exception state consistency (High)
2. **Gap 2** -- Exit action exception state consistency (High)
3. **Gap 3** -- Transition action partial execution (High)
4. **Gap 4** -- Guard RuntimeException stops evaluation (High)
5. **Gap 5** -- All calculators fail across branches (Medium)
6. **Gap 6** -- @fail all guards false re-throws (High)
7. **Gap 8** -- @timeout guarded branches (Medium)
8. **Gap 9** -- Child completion after parent timeout (High)
9. **Gap 10** -- Child fail after parent transitioned (Medium)
10. **Gap 11** -- Region fail with sibling already done (High)
11. **Gap 12** -- Both regions fail single @fail sync (High)
12. **Gap 13** -- Region timeout after @done no-op (Medium)
13. **Gap 14** -- Archive restore corrupted full path (Medium)
14. **Gap 15** -- Archive with active child (Medium)
15. **Gap 16** -- Lock TTL expiry full Machine::send path (Medium)
16. **Gap 17** -- MachineAlreadyRunningException diagnostics (Medium)
17. **Gap 18** -- @fail error details from child (Medium)
18. **Gap 19** -- ProvidesFailureContext interface (Medium)
19. **Gap 20** -- Job actor @fail guarded branches (Medium)
20. **Gap 22** -- Fire-and-forget failure observability (Medium)
21. **Gap 23** -- @always chain action exception (Medium)
22. **Gap 24** -- Restore unknown state after definition change (Medium)
23. **Gap 25** -- Partial archive recovery (Low)
24. **Gap 26** -- Timer stale fire skipped (Medium)
25. **Gap 28** -- sendTo non-existent machine (Medium)
26. **Gap 29** -- dispatchTo archived machine auto-restore (Medium)
