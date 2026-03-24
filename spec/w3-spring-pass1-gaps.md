# W3 Spring Statemachine Pass 1: Persistence + Region Test Gaps

> Theme: PERSISTENCE + REGION TESTS (Spring Statemachine Tier 2, Pass 1)
> Lens: Happy-path persistence/restore semantics for parallel/region states, fork/join ordering, timer re-arm after restore
> Generated: 2026-03-25

---

## Source Files Read

- `/tmp/spring-statemachine/.../StateMachineResetTests.java` (828 lines, 11 tests)
- `/tmp/spring-statemachine/.../RegionMachineTests.java` (563 lines, 6 tests)
- `/tmp/spring-statemachine/.../persist/StateMachinePersistTests.java` (811 lines, 10 tests)
- `/tmp/spring-statemachine/.../persist/StateMachinePersistTests4.java` (733 lines, 10 tests)
- `/tmp/spring-statemachine/.../ForkJoinEntryExitTests.java` (171 lines, 4 tests)
- `/tmp/spring-sm-test-summary.md` (full summary)

---

## Gap 1: Timer re-arm after restore

- **Priority**: High
- **Spring source**: `StateMachineResetTests.testRestoreWithTimer` -- restores machine to state S1 which has `timerOnce(1000)`, starts machine, waits 1.1s, asserts machine transitioned to S2 via timer.
- **EventMachine equivalent**: A machine with an `after` timer on a state is archived/restored. After restore, the timer should re-register and fire.
- **Existing coverage**: Grepped `timer.*restore`, `restore.*timer`, `re-?arm`, `timer.*after.*restor` -- found `TimerVerificationTest.php` which restores a cancelled timer machine, and `TimerSweepTest.php` + `EdgeCasesTest.php` (LocalQA). BUT: no test specifically creates a machine in a timer-bearing state, archives it, restores it, and verifies the timer fires after restore. The existing timer tests verify timer cancellation on state exit, not re-arm on restore.
- **Type**: Feature test (or E2E if timer sweep needed)
- **Scenario**: Create a machine with state A (has `after: 1000ms -> B`). Advance machine to state A. Archive or persist. Restore. Verify that `machine_timer_fires` is created on restore and the sweep command transitions the machine to B.
- **Dedup**: W1 Gap 28 (incremental context diff on restore) is different -- it covers context fidelity, not timer re-arm. No overlap.

## Gap 2: Idempotent restore (restore same snapshot twice)

- **Priority**: High
- **Spring source**: `StateMachinePersistTests4.testJoinAfterPersistRegionsNotEnteredJoinStatesRestoreTwice` and `testJoinAfterPersistRegionsPartialEnteredJoinStatesRestoreTwice` -- persist at a point, restore, run to completion, restore AGAIN from same snapshot, run to completion again. Proves restore is idempotent.
- **EventMachine equivalent**: Restore a machine from the same root event ID twice. Both restores should produce identical state. The second restore should not corrupt or double-apply events.
- **Existing coverage**: Grepped `idempotent.*restore`, `restore.*twice`, `double.*restore` -- NOT FOUND. No test restores from the same snapshot more than once.
- **Type**: Feature test
- **Scenario**: Create machine, advance to mid-state, capture rootEventId. Restore once -- verify state. Send more events. Restore again from same rootEventId -- verify state matches original snapshot. Send events again -- verify same transitions work.
- **Dedup**: No overlap with any existing bead or W1 gap.

## Gap 3: Persist/restore parallel state with per-region progress (partial join)

- **Priority**: High
- **Spring source**: `StateMachinePersistTests4.testJoinAfterPersistRegionsPartialEnteredJoinStates` -- one region at join state (S21), other not (S30). Persist. Restore. Complete remaining region. Machine transitions through join to S4.
- **EventMachine equivalent**: Parallel state with two regions. One region reaches final, the other does not. Persist at this point. Restore. Complete the second region. @done should fire.
- **Existing coverage**: `ParallelPersistenceTest.php` tests basic parallel persist/restore but always restores from a fully-transitioned state. No test persists when one region is final and the other is not, then completes the second region after restore.
- **Type**: Feature test
- **Scenario**: Create parallel machine with regions A (initial -> final) and B (initial -> mid -> final). Advance region A to final, leave B at mid. Persist. Restore. Send event to advance B to final. Verify @done fires.
- **Dedup**: No overlap with W1 gaps or existing beads.

## Gap 4: Fork/join entry count verification on parallel state entry

- **Priority**: Medium
- **Spring source**: `ForkJoinEntryExitTests.testForkEntrys` -- verifies exactly 3 state entries (S2 + S210 + S220) when entering a parallel state via fork. `testJoinExits` verifies 4 exits on join with specific exit order: `S220, S211, S221, S2`.
- **EventMachine equivalent**: When entering a parallel state, verify the exact count of entry actions fired (parent + all region initial states). When all regions complete (@done), verify the exact count and order of exit actions.
- **Existing coverage**: `ParallelDispatchXStateTest.php` has entry/exit count tests for parallel regions. `ParallelDispatchScxmlOrderingTest.php` tests ordering. BUT: no test verifies the precise entry count on initial parallel state entry (parent state entry + each region initial state entry). No test verifies exit count/order when @done fires.
- **Type**: Feature test
- **Scenario**: Create a parallel state with entry action on parent and entry actions on each region's initial state. Enter the parallel state. Assert entry action fired exactly N times (1 for parent + 1 per region). Then complete all regions. Assert exit actions fired in correct bottom-up order.
- **Dedup**: W1 Gap 4 covers deep hierarchy ordering but not parallel-specific entry counts. No overlap.

## Gap 5: Context variables survive persist/restore cycle (counter increments across reset)

- **Priority**: Medium
- **Spring source**: `StateMachineResetTests.testResetUpdateExtendedStateVariables` -- counter starts null, event A increments to 1, stop, reset with saved context, start, send A again, counter is 2 (not reset to 1). Proves context survives the full stop/reset/start cycle.
- **EventMachine equivalent**: Context mutations made before persist are preserved after restore. Further mutations build on the restored values.
- **Existing coverage**: `IncrementalContextDiffTest.php` and `ContextRoundTripTest.php` test context serialization, but do not test that further mutations build on restored values (i.e., counter continues from where it left off rather than resetting).
- **Type**: Feature test
- **Scenario**: Machine has context `{counter: 0}`. Event A increments counter. Send A twice (counter = 2). Persist (capture rootEventId). Restore. Verify counter = 2. Send A again. Verify counter = 3.
- **Dedup**: W1 Gap 12 (context round-trip fidelity) covers data type fidelity but not counter continuation. W1 Gap 28 (incremental context diff) is closer but focuses on multi-key diffs, not cumulative counter behavior. Slight overlap with Gap 28 but this is a distinct scenario.

## Gap 6: Persist and restore completed (final state) machine

- **Priority**: Medium
- **Spring source**: `StateMachinePersistTests.testPersistWithEnd` -- sends machine to end state, asserts `isComplete() == true`, persists, creates new machine from factory, restores, asserts `isComplete() == true`.
- **EventMachine equivalent**: A machine that has reached its final state is persisted. When restored, the machine should still be in the final state and recognize itself as complete.
- **Existing coverage**: Grepped `persist.*end`, `persist.*final`, `persist.*complete` -- found `EdgeCasesTest.php` (LocalQA) and `ContextRoundTripTest.php` but these do not specifically test persisting and restoring a completed machine.
- **Type**: Feature test
- **Scenario**: Create a machine. Transition to a final state. Persist. Restore from rootEventId. Verify restored state is the final state. Verify no further events are processable.
- **Dedup**: No overlap with existing beads or W1 gaps.

## Gap 7: Restore parallel state from mid-execution (regions at different stages)

- **Priority**: Medium
- **Spring source**: `StateMachinePersistTests.testRegions` -- three parallel regions (S11/S21/S31), persist at S12/S22/S32, restore, verify at S12/S22/S32, continue transitioning to S13/S23/S33, restore AGAIN to original snapshot.
- **EventMachine equivalent**: Parallel machine with multiple regions each at different internal states. Persist at this asymmetric state. Restore. Verify each region is at the correct state. Continue processing.
- **Existing coverage**: `ParallelPersistenceTest.php` tests basic parallel restore but with only 2 regions and simpler state. No test with 3+ regions at different stages, and no test continues processing after restore then restores again.
- **Type**: Feature test
- **Scenario**: Parallel machine with 3 regions, each with 3 states. Advance each region to a different state (region1: state2, region2: state1, region3: state3). Persist. Restore. Verify all 3 region states correct. Advance all regions. Restore again from original snapshot. Verify original states restored.
- **Dedup**: Gap 3 covers partial join (one region final, one not). This gap covers asymmetric mid-progress across 3 regions. Different scenarios.

## Gap 8: Persist/restore nested sub-states within parallel regions

- **Priority**: Medium
- **Spring source**: `StateMachinePersistTests.testSubsInRegions1` -- parallel regions where one region has nested sub-states (S11/S111). Persist at various depths within the sub-states. Restore to a different machine instance. Verify the nested sub-state is correctly restored.
- **EventMachine equivalent**: Parallel state with a region that has a compound sub-state. Persist when the machine is deep within a region's sub-state hierarchy. Restore. Verify the full path (parent.region.compound.leaf) is correctly restored.
- **Existing coverage**: Grepped `sub.?state.*persist`, `nested.*state.*persist`, `deep.*state.*restor` -- NOT FOUND. No test combines parallel regions with nested compound states in a persist/restore cycle.
- **Type**: Feature test
- **Scenario**: Define a parallel state where region_a has: `initial -> compound(sub_initial -> sub_advanced) -> done`. Region_b is simple. Advance region_a into the compound's sub_advanced state. Persist. Restore. Verify the machine is at parent.region_a.compound.sub_advanced AND parent.region_b.initial.
- **Dedup**: No overlap with any existing gap.

---

# Summary

| # | Gap Title | Priority | Covered? |
|---|-----------|----------|----------|
| 1 | Timer re-arm after restore | High | Not covered |
| 2 | Idempotent restore (same snapshot twice) | High | Not covered |
| 3 | Parallel persist/restore with partial join (one region final) | High | Not covered |
| 4 | Fork/join entry count verification on parallel entry | Medium | Partial |
| 5 | Context variables survive persist/restore (counter continues) | Medium | Partial |
| 6 | Persist and restore completed (final state) machine | Medium | Not covered |
| 7 | Restore parallel state from mid-execution (asymmetric regions) | Medium | Not covered |
| 8 | Persist/restore nested sub-states within parallel regions | Medium | Not covered |

## Actionable Gaps (8 beads)

All 8 gaps require new tests:

1. **Gap 1** -- Timer re-arm after restore (High)
2. **Gap 2** -- Idempotent restore (High)
3. **Gap 3** -- Parallel partial join persist/restore (High)
4. **Gap 4** -- Fork/join entry count on parallel entry (Medium)
5. **Gap 5** -- Context counter survives persist/restore (Medium)
6. **Gap 6** -- Persist/restore completed machine (Medium)
7. **Gap 7** -- Asymmetric parallel region restore (Medium)
8. **Gap 8** -- Nested sub-states in parallel region persist/restore (Medium)
