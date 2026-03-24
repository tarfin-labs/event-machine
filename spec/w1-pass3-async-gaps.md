# W1 Pass 3: Async / Concurrent / Race Condition Gaps

> Theme: ASYNC / CONCURRENT / RACE CONDITIONS
> Lens: Timing, locking, parallel dispatch, worker crashes, stale reads, concurrent mutations.
> Generated: 2026-03-25

---

## Dedup Check Against Pass 1, Pass 2, and Existing Beads

Checked all open beads (`bd list --status=open | grep ht-`):
- Pass 1 beads (`ht-w1p1-*`): 14 beads, all happy-path/semantic correctness. No async/race overlap.
- Pass 2 beads (`ht-w1p2-*`): 15 beads, all edge-case/boundary. No async/race overlap.
- `ht-w3-*` beads: Review beads for XState/pysm/MassTransit passes. No overlap with W1 problem-specific async tests.
- `ht-w3-aasm-p1-gaps-*`: Guard-specific test-writing beads. No async overlap.
- Spring persist beads (`em-ht-spring-persist-*`): Persist/restore tests. Different focus.
- Existing LocalQA tests checked:
  - `AsyncEdgeCasesTest.php`: @timeout, @fail, sequential delegation, one concurrent-sends test
  - `MachineLockingTest.php`: Lock release after delegation, 5 parallel delegations no deadlock
  - `ParallelDispatchTest.php`: Region entry via Horizon, @done via events, concurrent region completions
  - `CrossMachineTest.php`: dispatchTo delivery, non-existent machine handling
  - `TimerSweepTest.php`: Timer fire/dedup/selective fire
  - `ReviewFixesTest.php`: CompletionJob retry, TimeoutJob race with completion
- Existing Feature tests checked:
  - `ParallelDispatchLockContTest.php`: Sequential job completion (not true concurrency)
  - `ParallelDispatchContextConflictTest.php`: Context conflict detection (sync execution)
  - `ParallelDispatchRegionTimeoutTest.php`: Timeout job no-op when regions done
  - `ParallelDispatchEventQueueCrossRegionTest.php`: Cross-region event, stale state detection
  - `ParallelDispatchTransactionSafetyTest.php`: Persist failure does not dispatch jobs

---

## Gap 1: Concurrent Machine::send() to same instance -- lock serializes correctly (LocalQA)

- **Priority**: Critical
- **Source**: Problem #4.4, #4.5, Gap #15 from research summary
- **Type**: LocalQA
- **Scenario**: Create a machine in state A with transitions A->B on EVENT_1 and B->C on EVENT_2. Dispatch two SendToMachineJobs simultaneously (EVENT_1 and EVENT_2). Verify: (1) both events are eventually processed (the second waits or retries), (2) the machine reaches state C (not stuck at A or B), (3) no stale locks remain, (4) event history shows both transitions in correct order.
- **Why this differs from existing**: `AsyncEdgeCasesTest.php` has a concurrent-sends test but it only verifies "machine not stuck at idle" -- it does NOT verify both events succeed, does NOT verify final state is deterministic, and uses a machine where the second event (ADVANCE) may not be valid in all states. This test needs a machine with a defined two-step path and verification that BOTH events process successfully in sequence.
- **Dedup check**: `AsyncEdgeCasesTest::concurrent sends to same machine` exists but is weak (only checks machine left idle). No test verifies both events succeed and produce a deterministic final state.

## Gap 2: Machine::send() during parallel region execution -- MachineAlreadyRunningException (LocalQA)

- **Priority**: Critical
- **Source**: Problem #4.2, Scenario 10 from deep edge cases spec
- **Type**: LocalQA
- **Scenario**: Create a machine with a parallel state where one region has a slow entry action (5+ seconds). While that region is executing (lock held), call Machine::send() from another process. Verify: (1) MachineAlreadyRunningException is thrown (timeout=0 means immediate reject), (2) the parallel region completes successfully despite the rejected send, (3) no stale locks remain.
- **Why this is needed**: The spec `upcoming-parallel-send-race-condition-analysis.md` documents this exact race but no LocalQA test validates it with real Horizon workers. Feature tests use sync execution which never exercises the lock timeout=0 path.
- **Dedup check**: No existing test. Scenario 10 in deep edge cases spec is documented but not implemented.

## Gap 3: Parallel region context merge under true concurrency -- both regions' changes survive (LocalQA)

- **Priority**: High
- **Source**: Problem #3.1, #3.7, Scenario 5 from deep edge cases spec
- **Type**: LocalQA
- **Scenario**: Create a machine with two parallel regions. Region A's entry action sets `region_a_score = 85`. Region B's entry action sets `region_b_score = 92`. Both run concurrently via real Horizon workers. Wait for both to complete. Verify: (1) both context keys exist with correct values, (2) neither region's changes were lost, (3) context diff was computed against the dispatch-time snapshot.
- **Why this differs from existing**: `ParallelDispatchContextConflictTest.php` and `ParallelDispatchLockContTest.php` run region jobs SEQUENTIALLY in-process using `(new ParallelRegionJob(...))->handle()`. They never exercise true concurrent execution where both regions read the same snapshot and compute diffs independently. `ParallelDispatchTest.php` LocalQA tests check region entry ran but don't verify specific context values survived the concurrent merge.
- **Dedup check**: No LocalQA test verifies context merge correctness under true concurrency. Only sync in-process tests exist.

## Gap 4: Parallel region same-key scalar overwrite under concurrency -- last-writer-wins (LocalQA)

- **Priority**: High
- **Source**: Problem #3.7 (scalar conflict)
- **Type**: LocalQA
- **Scenario**: Create a machine with two parallel regions where BOTH write to the same context key (`score`). Region A sets `score = 85`, Region B sets `score = 92`. Run under real Horizon. Verify: (1) after both complete, `score` has exactly one value (last-writer-wins), (2) the value is deterministic based on which region completes last, (3) a PARALLEL_CONTEXT_CONFLICT event is recorded in history.
- **Why this is needed**: The research doc (Problem 3.7) explicitly calls out scalar overwrites as the dangerous case. Only different-key merges are tested. Same-key overwrites are not tested anywhere.
- **Dedup check**: `ParallelDispatchContextConflictTest.php` tests different-key merges and conflict detection enum. No test for same-key scalar overwrites under real concurrency.

## Gap 5: Both parallel regions fail simultaneously -- only one @fail transition fires (LocalQA)

- **Priority**: High
- **Source**: Problem #3.3, #3.6, Scenario 9 from deep edge cases spec
- **Type**: LocalQA
- **Scenario**: Create a parallel machine where both regions throw exceptions in their entry actions. Run via real Horizon. Verify: (1) the machine transitions to the @fail target exactly once, (2) `PARALLEL_FAIL` event appears exactly once in history (not twice), (3) the double-guard in `ParallelRegionJob::failed()` prevents the second failure from re-transitioning, (4) no stale locks.
- **Why this is needed**: Scenario 9 is documented in spec but not implemented. Feature tests for `ParallelDispatchFailEdgeCasesTest` use sync execution. The double-guard race condition ONLY manifests under true concurrency where both `failed()` handlers compete for the lock.
- **Dedup check**: No existing test. `ParallelDispatchFailEdgeCasesTest` and `ParallelDispatchFailBasicTest` run in-process only.

## Gap 6: Region timeout races with region completion -- only one wins (LocalQA)

- **Priority**: High
- **Source**: Problem #3.11
- **Type**: LocalQA
- **Scenario**: Create a parallel machine with a region timeout of 5 seconds and a region entry action that takes 4 seconds. The region should complete just before the timeout fires. Verify: (1) either region completes (normal) or timeout fires (edge), but NOT both, (2) the machine reaches a consistent final state, (3) no stale locks. Run multiple times to exercise the race window.
- **Why this differs from existing**: `ParallelDispatchRegionTimeoutTest.php` tests timeout no-op when regions already completed, but runs synchronously. `ReviewFixesTest::timeout job is no-op when child completes before timeout` tests child delegation timeout, not parallel region timeout. No test exercises the actual race between `ParallelRegionTimeoutJob` and `ParallelRegionJob` completion under real concurrency.
- **Dedup check**: No LocalQA test for parallel region timeout race.

## Gap 7: Lock TTL expiry after worker crash -- stale lock cleanup (LocalQA)

- **Priority**: High
- **Source**: Problem #4.6
- **Type**: LocalQA
- **Scenario**: Create a machine. Manually insert a stale lock row in `machine_locks` with an expired `locked_until` timestamp (simulating a crashed worker). Then call `Machine::send()`. Verify: (1) the stale lock is cleaned up by `MachineLockManager`, (2) the send() succeeds (acquires a fresh lock), (3) the machine transitions correctly.
- **Why this is needed**: `ParallelDispatchLockInfraTest.php` tests stale lock detection but runs in-process with SQLite. No LocalQA test verifies stale lock cleanup works with real MySQL row-level locking, which has different semantics (MySQL `FOR UPDATE` vs SQLite advisory locks).
- **Dedup check**: No LocalQA test for stale lock cleanup with real MySQL.

## Gap 8: Timer sweep concurrent with Machine::send() -- dedup prevents double-fire (LocalQA)

- **Priority**: High
- **Source**: Problem #7.2, #4.9
- **Type**: LocalQA
- **Scenario**: Create a machine in a state with an `after` timer. Backdate `state_entered_at` so the timer is eligible. Run `machine:process-timers` while simultaneously sending an event that transitions the machine out of the timed state. Verify: (1) either the timer fires OR the event transitions the machine (not both causing double-transition), (2) `machine_timer_fires` dedup record prevents duplicate, (3) no inconsistent state.
- **Why this is needed**: `TimerSweepTest.php` tests dedup for double-sweep but not for sweep-vs-send concurrency. The race between timer processing and external events is untested.
- **Dedup check**: No test for timer sweep racing with Machine::send().

## Gap 9: dispatchTo event delivery ordering under load -- FIFO preserved (LocalQA)

- **Priority**: Medium
- **Source**: Problem #5.5
- **Type**: LocalQA
- **Scenario**: Create a machine in state A with transitions A->B on EVENT_1, B->C on EVENT_2, C->D on EVENT_3. Dispatch three SendToMachineJobs in order (EVENT_1, EVENT_2, EVENT_3) with zero delay between dispatches. Verify: (1) all three events are processed, (2) the machine reaches state D, (3) events appear in history in the correct order (EVENT_1 before EVENT_2 before EVENT_3).
- **Why this differs from existing**: `CrossMachineTest.php` tests single dispatchTo delivery. No test verifies ordering of multiple sequential dispatchTo events under Horizon.
- **Dedup check**: No test for multi-event dispatchTo ordering.

## Gap 10: Scheduled event fires while machine is processing another event (LocalQA)

- **Priority**: Medium
- **Source**: Problem #7.5
- **Type**: LocalQA
- **Scenario**: Create a machine with a scheduled event. While the machine is processing a slow action (holding a lock), trigger the schedule sweep. Verify: (1) the scheduled event job either waits for the lock or is rejected with MachineAlreadyRunningException, (2) the machine does not end up in a corrupt state, (3) the scheduled event is eventually processed on a subsequent sweep if it was rejected.
- **Why this is needed**: `ScheduledEventsTest.php` tests schedule firing in isolation. No test exercises the race between a scheduled event and an in-progress Machine::send().
- **Dedup check**: No existing test.

## Gap 11: Parallel dispatch -- region entry actions see consistent snapshot (not each other's changes)

- **Priority**: Medium
- **Source**: Problem #3.8, #3.10
- **Type**: LocalQA
- **Scenario**: Create a parallel machine where Region A's entry action reads `shared_flag` from context (initially null). Region A sets `shared_flag = true`. Region B's entry action also reads `shared_flag`. Under dispatch mode, Region B should see `shared_flag = null` (dispatch-time snapshot), NOT `true` (Region A's change). Verify: (1) Region B's view of context matches the dispatch-time snapshot, (2) both regions' diffs are computed independently.
- **Why this is needed**: Problem 3.8 explicitly warns that sync mode creates implicit dependencies between regions that break under dispatch mode. No test verifies that regions see the snapshot, not each other's changes.
- **Dedup check**: No existing test. `ParallelDispatchContextConflictTest` tests merge outcomes but not snapshot isolation.

## Gap 12: Concurrent schedule sweeps for same machine -- no duplicate processing (LocalQA)

- **Priority**: Medium
- **Source**: Problem #7.2
- **Type**: LocalQA
- **Scenario**: Create a machine with a scheduled event. Run `machine:process-scheduled` twice concurrently (simulating overlapping cron runs). Verify: (1) the event is processed exactly once, (2) the machine transitions once, (3) no duplicate state history entries.
- **Why this is needed**: Timer dedup is tested for `after` timers via `machine_timer_fires`, but scheduled events use a different code path (`MachineScheduler` -> `Bus::batch`). No test verifies scheduled event dedup under concurrent sweeps.
- **Dedup check**: No existing test.

## Gap 13: Child machine completion arrives after parent timeout -- graceful no-op (LocalQA)

- **Priority**: Medium
- **Source**: Problem #4.8, #5.1
- **Type**: LocalQA
- **Scenario**: Parent delegates to async child with @timeout. Let the timeout fire (parent moves to `timed_out`). Then manually complete the child machine. When the ChildMachineCompletionJob fires, it should find the parent is no longer in the `delegating` state and be a no-op. Verify: (1) parent stays in `timed_out`, (2) no exception, (3) child marked as completed in `machine_children` even though parent already moved on.
- **Why this differs from existing**: `ReviewFixesTest::timeout job is no-op when child completes before timeout` tests the OPPOSITE direction (completion before timeout). `AsyncEdgeCasesTest::@timeout fires` tests timeout only. No test verifies what happens when completion arrives AFTER timeout.
- **Dedup check**: No test for late child completion after parent timeout.

## Gap 14: Parallel dispatch with 3+ regions -- all regions merge context correctly (LocalQA)

- **Priority**: Medium
- **Source**: Problem #3.1, #3.7
- **Type**: LocalQA
- **Scenario**: Create a parallel machine with 3 regions, each writing to different context keys. Run under real Horizon. Verify: (1) all three regions' context changes survive the triple merge, (2) the lock serialization works correctly for 3 lock acquisitions, (3) @done fires after all 3 reach final.
- **Why this is needed**: All existing parallel dispatch tests use exactly 2 regions. The merge logic processes diffs sequentially, but with 3 regions there are more interleaving possibilities. The third region reads a snapshot that may already have region 1 OR region 2's changes applied (depending on timing).
- **Dedup check**: No existing test with 3+ parallel regions under dispatch mode.

## Gap 15: Fire-and-forget child failure -- no parent impact, child failure logged (LocalQA)

- **Priority**: Low
- **Source**: Problem #5.3
- **Type**: LocalQA
- **Scenario**: Parent dispatches fire-and-forget child. Child throws an exception. Verify: (1) parent is unaffected (stays in its state), (2) child's failed job is handled by Horizon (appears in failed_jobs or is retried), (3) no orphaned locks.
- **Why this differs from existing**: `FireAndForgetQATest.php` tests successful fire-and-forget only. No test verifies behavior when the fire-and-forget child FAILS.
- **Dedup check**: No test for fire-and-forget child failure under real Horizon.

## Gap 16: Concurrent `every` timer fire with event that exits the state -- timer skipped (LocalQA)

- **Priority**: Medium
- **Source**: Problem #7.3, #7.4
- **Type**: LocalQA
- **Scenario**: Create a machine in state `retrying` with an `every` timer. While the timer sweep is processing, send an event that transitions the machine to `completed`. The timer fire should check `machine_current_states` and find the machine is no longer in `retrying`, skipping execution. Verify: (1) machine is in `completed`, (2) no stale timer fire entry, (3) timer action did not execute after state exit.
- **Why this is needed**: `TimerSweepTest.php` tests dedup and selective firing but not the race between an active timer sweep and a concurrent state-changing event.
- **Dedup check**: No existing test.

---

# Summary

| # | Gap Title | Priority | Type |
|---|-----------|----------|------|
| 1 | Concurrent send() both succeed in sequence | Critical | LocalQA |
| 2 | send() during parallel region -- MachineAlreadyRunningException | Critical | LocalQA |
| 3 | Parallel context merge under true concurrency | High | LocalQA |
| 4 | Parallel same-key scalar overwrite (last-writer-wins) | High | LocalQA |
| 5 | Both regions fail simultaneously -- single @fail | High | LocalQA |
| 6 | Region timeout races with region completion | High | LocalQA |
| 7 | Stale lock cleanup after worker crash (real MySQL) | High | LocalQA |
| 8 | Timer sweep concurrent with Machine::send() | High | LocalQA |
| 9 | dispatchTo event delivery ordering under load | Medium | LocalQA |
| 10 | Scheduled event during active Machine::send() | Medium | LocalQA |
| 11 | Region snapshot isolation (no cross-region reads) | Medium | LocalQA |
| 12 | Concurrent schedule sweeps -- no duplicate | Medium | LocalQA |
| 13 | Child completion after parent timeout -- no-op | Medium | LocalQA |
| 14 | 3+ region parallel dispatch context merge | Medium | LocalQA |
| 15 | Fire-and-forget child failure -- no parent impact | Low | LocalQA |
| 16 | Every timer race with state-exit event | Medium | LocalQA |

## Actionable Gaps (16 beads)

All 16 gaps require new LocalQA tests. All involve real MySQL + Redis + Horizon.
No duplicates with Pass 1 (happy-path) or Pass 2 (edge-cases) beads.
