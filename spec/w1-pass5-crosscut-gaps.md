# W1 Pass 5: Cross-Cutting Concern Gaps

> Theme: CROSS-CUTTING CONCERNS
> Lens: Ordering guarantees, persistence fidelity, serialization, context integrity -- concerns that span multiple features.
> Generated: 2026-03-25
> **FINAL W1 pass**

---

## Dedup Check Against Pass 1, Pass 2, Pass 3, Pass 4

Checked all open beads from all four previous passes:

**Pass 1** (14 beads) -- happy-path semantic correctness:
- guard-first-match, guard-no-side-effects, action-ordering-scxml, deep-hierarchy-ordering, lca-sibling, initial-always-chain, child-over-parent-priority, self-transition-exit-entry, targetless-no-exit-entry, parallel-done-all-final, context-roundtrip-fidelity, self-transition-resets-child, cache-clear-commands, incremental-context-diff

**Pass 2** (15 beads) -- edge cases and boundary conditions:
- single-region-parallel, always-all-guards-false, compound-one-child, context-extreme-values, empty-null-payload, single-final-state, noop-self-transition, max-depth-1, negative-depth, guard-null-context, all-immediate-parallel, lca-child-ancestor, available-events-no-transitions, always-empty-guards, send-to-final-state

**Pass 3** (16 beads) -- async/concurrent/race conditions:
- All LocalQA: concurrent-send, send-during-parallel, parallel-context-merge, parallel-scalar-overwrite, both-regions-fail, region-timeout-race, stale-lock-cleanup, timer-sweep-vs-send, dispatchTo-ordering, scheduled-during-send, region-snapshot-isolation, concurrent-schedule-sweeps, child-completion-after-timeout, 3-region-merge, fire-and-forget-failure, every-timer-vs-exit

**Pass 4** (26 beads) -- failure/recovery/timeout:
- entry-action-throws, exit-action-throws, transition-action-throws, guard-throws, all-calculators-fail, fail-all-guards-false, timeout-guarded-branches, child-completion-after-timeout, child-fail-after-parent-transitioned, region-fail-sibling-done, both-regions-fail-sync, region-timeout-after-done, archive-restore-corrupted, archive-active-child, lock-ttl-full-path, machine-already-running-diagnostics, fail-error-details, provides-failure-context, job-actor-fail-guarded, fire-and-forget-observability, always-chain-exception, restore-unknown-state, partial-archive-recovery, timer-stale-skipped, sendTo-nonexistent, dispatchTo-archived-auto-restore

None overlap with the cross-cutting gaps identified below. Each gap below spans multiple features or tests a concern that cuts across subsystems.

---

## Gap 1: Event history ordering preserved through persist/restore cycle

- **Priority**: High
- **Source**: Problem #6.1, #6.3, #4.1 from hardened-testing-research.md
- **Type**: Integration test
- **Scenario**: Create a machine, send 5+ events that produce a rich history (including internal lifecycle events like entry.start, exit, transition events). Persist. Restore from root_event_id. Verify: (1) the restored history has the exact same events in the exact same order as the original, (2) sequence_number values are monotonically increasing, (3) no events are missing or duplicated, (4) event types match exactly.
- **Expected behavior**: Event history ordering is deterministic and preserved through the persist/restore cycle. The `pluck('type')->toArray()` of restored history matches original.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: `PersistenceTest.php` tests basic persist/restore but only verifies state value/context match and history is a Collection. Does NOT verify event ordering fidelity through the full round-trip. `EventStoreTest.php` verifies event types in the history but does not persist/restore. `ContextRoundTripTest.php` and `IncrementalContextDiffTest.php` test context but not history ordering through restore. No test combines both: create events + persist + restore + verify history order matches.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 2: Event payload serialization fidelity through persist/restore

- **Priority**: High
- **Source**: Problem #6.1, #6.2 from hardened-testing-research.md
- **Type**: Integration test
- **Scenario**: Send events with payloads containing various data types: integers, floats, booleans, null, nested arrays, empty arrays, strings with special characters (unicode, newlines, quotes). Persist. Restore. Verify: (1) event payloads survive the round-trip with correct types, (2) integer payloads remain integers (not strings after JSON decode), (3) nested array structure is preserved.
- **Expected behavior**: Event payloads maintain full type fidelity through JSON serialization/deserialization in the machine_events table. No silent type coercion.
- **Stub machine needed**: No (inline TestMachine::define with actions that read payload)
- **Dedup check**: `ContextRoundTripTest.php` tests CONTEXT data types, not EVENT PAYLOAD data types. `ArchiveEdgeCasesTest.php` line 302 tests "unicode characters survive archive/restore" for event payloads but only for unicode strings, not for type fidelity (int/float/bool/null). `EventStoreTest.php` stores events and checks types but does not restore and verify payload types. Pass 1 Gap 12 (`context-roundtrip-fidelity`) covers context types, not payload types.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 3: triggeringEvent preserved through persist/restore of @always chain

- **Priority**: High
- **Source**: Problem #4.1, #6.1 from hardened-testing-research.md and CLAUDE.md architecture (triggeringEvent doc)
- **Type**: Integration test
- **Scenario**: Create a machine with an @always chain triggered by an external event with a payload. The @always chain spans multiple states. Persist the machine after the chain completes. Restore from root_event_id. In the restored machine, verify the event history records the correct triggeringEvent for each state along the chain (the original external event, NOT the @always internal event).
- **Expected behavior**: After restore, the event history shows that actions during the @always chain saw the original external event as triggeringEvent. The persist/restore cycle does not lose this relationship.
- **Stub machine needed**: No (uses existing AlwaysChainMachine or inline)
- **Dedup check**: `AlwaysEventPreservationTest.php` tests triggeringEvent preservation during the in-memory macrostep but does NOT persist/restore and verify. `AlwaysEventPreservationParallelTest.php` tests triggeringEvent in parallel context but also only in-memory. `PersistenceTest.php` does not test @always chains. No test combines @always event preservation with persist/restore.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 4: Context mutations from multiple actions in a single transition are atomic

- **Priority**: High
- **Source**: Problem #1.3, #6.1 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Define a transition with 3 actions: action1 sets `key_a = 1`, action2 sets `key_b = 2`, action3 sets `key_c = 3`. Send the event. Verify: (1) all three context mutations are applied, (2) the persisted context diff contains all three changes (not just the last one), (3) restoring from events produces a context with all three keys set.
- **Expected behavior**: All context mutations from all actions in a single transition are captured in the persisted event's context diff. No silent overwrites within a transition's action list.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: `IncrementalContextDiffTest.php` tests context diffs across multiple EVENTS (GO1 then GO2), not multiple ACTIONS within a single event. `ActionOrderingTest.php` tests ordering but does not verify context diff persistence. `ContextRoundTripTest.php` tests type fidelity, not multi-action atomicity. No test verifies that multiple actions in a single transition produce a single coherent context diff in the persisted event.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 5: machine_current_states stays in sync after @always chain

- **Priority**: Medium
- **Source**: Problem #7.3, #2.4 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a machine where the initial state has an @always chain that transitions through 3 intermediate states to a final stable state. After the macrostep completes, verify: (1) `machine_current_states` has exactly one row, (2) that row's `state_id` matches the final stable state (not any intermediate @always state), (3) `state_entered_at` is set.
- **Expected behavior**: machine_current_states reflects the FINAL stable state after a macrostep with @always chains. Timer sweeps and schedules would use this table to find the machine, so it must be correct.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: `MachineCurrentStatesTest.php` tests basic create/transition/final-state-deletion but does NOT test @always chains. No test verifies machine_current_states correctness after an @always chain that passes through multiple intermediate states.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 6: machine_current_states stays in sync for parallel state (multiple rows)

- **Priority**: Medium
- **Source**: Problem #3.2, #7.3 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a machine that enters a parallel state with 2 regions. Verify: (1) machine_current_states has one row per region (2 rows total), (2) each row has the correct state_id for its region, (3) after transitioning one region to a different state, verify the table updates correctly (that region's row updated, other preserved). After @done fires, verify rows are updated to reflect the post-parallel state.
- **Expected behavior**: machine_current_states accurately reflects all active atomic states in a parallel configuration. Timer sweeps rely on this.
- **Stub machine needed**: No (inline TestMachine::define or existing parallel stubs)
- **Dedup check**: `MachineCurrentStatesTest.php` tests parallel state row creation at line 80+ with `ParallelDispatch*` machines. Let me verify... Grepped for "parallel" in `MachineCurrentStatesTest.php` -- found at line 89 "creates multiple current state rows for parallel regions". This covers basic parallel row creation. However, it does NOT test: (a) row updates when one region transitions, (b) row cleanup after @done fires and machine leaves parallel state, (c) correctness of state_ids per region after region transitions. The existing test only checks initial row creation, not the full lifecycle.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 7: Archive/restore round-trip preserves FULL machine equivalence (state + context + history + current event)

- **Priority**: High
- **Source**: Problem #6.5, #4.7 from hardened-testing-research.md
- **Type**: Integration test
- **Scenario**: Create a machine, send 5+ events with varied payloads, including at least one event that triggers @always. Archive via ArchiveService. Restore. Verify: (1) state value matches, (2) context matches (type-level), (3) history event count matches, (4) history event types match in order, (5) history event payloads match, (6) currentEventBehavior matches.
- **Expected behavior**: Archive/restore produces a machine state that is bit-for-bit equivalent to the pre-archive state. This is the comprehensive "round-trip fidelity" test that combines state, context, history, and event metadata.
- **Stub machine needed**: No (inline or existing)
- **Dedup check**: `ArchiveLifecycleTest.php` tests the full lifecycle (archive -> access -> new events -> re-archive) but verifies event counts and basic data, NOT comprehensive field-by-field equivalence including history event payloads and types. `ArchiveEdgeCasesTest.php` tests unicode and compression but not full equivalence. `ArchiveTransparencyTest.php` tests transparent restore but not field-level fidelity. Pass 4 Gap 14 covers corrupted archive error handling, not round-trip fidelity. No test verifies archive/restore produces a fully equivalent machine state (state + context + history types + history payloads + currentEventBehavior).
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 8: raise() events processed in FIFO order (multiple raises from one action)

- **Priority**: Medium
- **Source**: Problem #4.1 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: An entry action raises 3 events in sequence: RAISE_A, RAISE_B, RAISE_C. Each event transitions to a different state (if the current state handles it). Verify: (1) RAISE_A is dequeued and processed first, (2) RAISE_B is processed second (against the new state), (3) RAISE_C is processed third. The internal event queue maintains FIFO ordering.
- **Expected behavior**: raise() events are processed in the order they were raised (FIFO), and each is evaluated against the machine's state at the time of dequeue (not at the time of enqueue).
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: `RaisedEventTiebreakerTest.php` tests 2 raised events (EVENT_1 and EVENT_2) and verifies EVENT_1 processes first, but the test expects an EXCEPTION for EVENT_2 (new state doesn't handle it). It does NOT test a scenario where all 3 raised events are handled successfully in sequence. `EventProcessingOrderTest.php` tests entry-before-raise but not multi-raise FIFO ordering. `AlwaysBeforeRaiseTest.php` tests @always before raise priority but not multi-raise ordering.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 9: Context modifications from entry/exit actions visible to subsequent actions in correct order

- **Priority**: Medium
- **Source**: Problem #1.3, #4.1 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Transition from A to B. A's exit action sets `exit_marker = true`. The transition action reads `exit_marker` and sets `transition_saw_exit = true/false`. B's entry action reads both markers and sets `entry_saw_both`. Verify: (1) transition action sees `exit_marker = true`, (2) entry action sees both markers.
- **Expected behavior**: Context modifications are visible to subsequent actions in the SCXML ordering: exit actions' mutations are visible to transition actions, and both are visible to entry actions.
- **Stub machine needed**: No (inline TestMachine::define with closure actions)
- **Dedup check**: `ActionOrderingTest.php` (pass 1 bead) tests the ORDER of execution (exit before transition before entry) via an append-to-log pattern. But it does NOT verify that context mutations from earlier actions are VISIBLE to later actions. The ordering test confirms sequence via a log array, not via cross-action context reads. `EventProcessingOrderTest.php` also logs execution order but does not test cross-action context visibility. No test verifies that exit action's context mutation is readable by the transition action.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 10: Parallel state context persisted correctly (both regions' context changes in events table)

- **Priority**: Medium
- **Source**: Problem #3.7, #6.1 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a parallel state where Region A's entry action sets `region_a_done = true` and Region B's entry action sets `region_b_done = true`. After both regions complete (sync mode), persist. Restore from root_event_id. Verify: (1) both context keys exist in restored context, (2) the event history contains context diffs for both regions' changes, (3) no context key was silently lost.
- **Expected behavior**: When parallel regions modify context (sync mode), both regions' changes are captured in persisted events and survive a restore cycle.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: `ParallelPersistenceTest.php` tests parallel state persist/restore but only verifies state VALUE (which regions the machine is in), NOT context values. `ParallelDispatchContextConflictTest.php` tests context merge in dispatch mode but does not persist/restore. `ContextRoundTripTest.php` tests context types in a flat machine, not parallel. No test verifies parallel state context modifications survive persist/restore.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 11: Event history records correct state value at each step (including parallel multi-value)

- **Priority**: Medium
- **Source**: Problem #6.3, #3.2 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a machine with states idle -> parallel(region_a, region_b) -> done. Send events that transition through these states. Verify: (1) each event in history has a `machine_value` that reflects the state at that point, (2) parallel state events have multi-value arrays (e.g., `['machine.region_a.a1', 'machine.region_b.b1']`), (3) post-parallel events have single-value state arrays.
- **Expected behavior**: The machine_value column in machine_events accurately tracks the machine's state at each event, including multi-value representations for parallel states.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: `ParallelPersistenceTest.php` line 65 tests "parallel state value is correctly stored in machine events" which verifies multi-value storage for parallel states. This is close but tests only the initial parallel entry, NOT the full lifecycle (pre-parallel -> parallel -> post-parallel) state value tracking. `EventStoreTest.php` tracks event types but not machine_value per event. Partial coverage.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 12: Listeners do not corrupt context when modifying it during entry/exit

- **Priority**: Medium
- **Source**: Problem #1.2 (side effects) and architecture docs (Listener System) from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Define a machine with a sync listener on state entry that reads context. Also define an entry action on the same state that modifies context. Verify: (1) the listener sees the context AFTER the entry action has run (ordering), (2) if the listener modifies context, those modifications are captured, (3) if the listener is queued (`queue: true`), it does NOT see the action's modifications synchronously (it runs asynchronously).
- **Expected behavior**: Sync listeners execute after entry actions and can see their context modifications. Queued listeners run asynchronously and may see snapshot context.
- **Stub machine needed**: No (inline or existing ListenerMachine stubs)
- **Dedup check**: `ListenTest.php` tests listener firing on entry/exit/transition but does NOT verify context visibility ordering between actions and listeners. The tests assert listeners fire but not WHAT context they see. `ListenChildDelegationTest.php` tests listeners with child delegation but not context ordering. No test verifies that a sync entry listener sees context modifications made by the entry action on the same state.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 13: Context computed methods work correctly after persist/restore

- **Priority**: Medium
- **Source**: Problem #6.1, #6.2 from hardened-testing-research.md and architecture docs (ContextManager)
- **Type**: Integration test
- **Scenario**: Create a ContextManager subclass with a computed method (e.g., `totalAmount()` that returns `price * quantity`). Set context keys `price = 100, quantity = 3`. Persist. Restore. Verify: (1) the computed method returns the correct value (300) after restore, (2) `toResponseArray()` includes the computed value, (3) the computed method is not stored in the database (it's derived).
- **Expected behavior**: Computed context methods work correctly after restore since they're derived from stored context values. The raw context in the DB should not contain computed values.
- **Stub machine needed**: Yes (ContextManager subclass with computed method) or use existing computed context stubs
- **Dedup check**: `ContextComputedTest.php` tests computed methods in-memory. `EndpointComputedContextTest.php` tests computed context in HTTP responses. Neither persists/restores and verifies computed methods still work. No test for computed context through the persist/restore cycle.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 14: Event sourcing consistency -- no orphaned events after failed transition

- **Priority**: Medium
- **Source**: Problem #6.3 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Create a machine. Begin a transition that will fail (e.g., an entry action throws). After the exception, verify: (1) no partial/orphaned events were written to machine_events (the failed transition's events should be rolled back or not persisted), (2) the machine's event history is consistent (only events from successful transitions), (3) a subsequent successful transition produces a clean event chain.
- **Expected behavior**: Failed transitions do not leave orphaned events in the database. Event chain integrity is maintained.
- **Stub machine needed**: No (inline TestMachine::define with throwing action)
- **Dedup check**: Pass 4 Gap 1 (`entry-action-throws`) tests that the machine state is not corrupted when an entry action throws, but does NOT verify event persistence integrity (that no orphaned events were written). `EventStoreTest.php` tests successful event storage. `HistoryValidationTest.php` tests that validation exceptions don't corrupt history but only for MachineValidationException, not arbitrary RuntimeExceptions. No test verifies that a RuntimeException during transition does not leave orphaned events.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 15: Incremental context diff correctness when context key is set back to its initial value

- **Priority**: Medium
- **Source**: Problem #6.1, #6.2 from hardened-testing-research.md
- **Type**: Integration test
- **Scenario**: Create a machine with context `counter = 0`. Event 1 sets `counter = 5`. Event 2 sets `counter = 0` (back to initial). Persist. Restore. Verify: (1) restored `counter` is 0, (2) the incremental diff for event 2 explicitly records `counter = 0` (not omitted because it matches the initial value), (3) if we replay only up to event 1, counter would be 5.
- **Expected behavior**: Setting a context value back to its initial value produces an explicit diff entry. The diff is against the previous state, not against the initial context.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: `IncrementalContextDiffTest.php` tests incremental diffs with overwriting a key to a NEW value, but does NOT test setting a key back to its INITIAL value. The diff computation might optimize away "no change" diffs by comparing against initial context rather than previous context. No test for this scenario.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 16: Context key deletion (set to null) persists correctly through incremental diff

- **Priority**: Medium
- **Source**: Problem #6.1, #6.2 from hardened-testing-research.md
- **Type**: Integration test
- **Scenario**: Create a machine with context `data = ['key' => 'value']`. Event 1 sets `data = null`. Persist. Restore. Verify: (1) restored `data` is null (not the original value), (2) the diff captures the null assignment, (3) subsequent events can set `data` to a new value.
- **Expected behavior**: Setting a context key to null is treated as a meaningful change and persisted in the incremental diff. The restored context has null, not the original value.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: `ContextRoundTripTest.php` tests that null values persist correctly in initial context, but does NOT test setting a previously non-null value TO null via an action. `IncrementalContextDiffTest.php` overwrites values but always with non-null values. No test for the null-assignment diff scenario.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 17: MachineCast serialization/deserialization on Eloquent model attribute

- **Priority**: Low
- **Source**: Problem #6.2 from hardened-testing-research.md and architecture docs (MachineCast)
- **Type**: Feature test
- **Scenario**: Create an Eloquent model with a MachineCast attribute. Set the attribute to a machine instance. Save the model. Reload from DB. Verify: (1) the attribute returns a Machine instance, (2) the machine state matches the original, (3) the machine context matches the original, (4) sending events to the reloaded machine works correctly.
- **Expected behavior**: MachineCast correctly serializes (to root_event_id string) and deserializes (to Machine instance) through Eloquent attribute casting.
- **Stub machine needed**: No (uses existing ModelA and XyzMachine)
- **Dedup check**: `SerializationTest.php` line 23 tests "a machine as a model attribute can serialize as root_event_id" which covers save/load of the attribute. However, it does NOT verify that the reloaded machine has correct state/context or that events can be sent to it. It only verifies the attribute IS a Machine instance and that toArray/toJson work.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap 18: Event ordering guarantee -- @always chain completes before raise() events

- **Priority**: Medium
- **Source**: Problem #4.1 from hardened-testing-research.md
- **Type**: Feature test
- **Scenario**: Already partially tested by `AlwaysBeforeRaiseTest.php`. However, that test only has a single @always step. Test with a MULTI-STEP @always chain (A -> @always -> B -> @always -> C) where entry action in A raises an event. Verify: (1) the full @always chain A->B->C completes before the raised event is processed, (2) the raised event is processed against state C (the final @always target), not A or B.
- **Expected behavior**: Multi-step @always chains fully complete before any raised events from the chain's entry actions are dequeued. This is an extension of the single-step test in AlwaysBeforeRaiseTest.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: `AlwaysBeforeRaiseTest.php` tests single-step @always before raise. `AlwaysEventPreservationTest.php` tests event preservation through @always chains. `InitialAlwaysChainTest.php` tests @always chain completion. No test combines multi-step @always chain with raised events to verify ordering.
- **Workflow**: Use /agentic-commits. Run composer quality.

---

# Summary

| # | Gap Title | Priority | Dedup Status |
|---|-----------|----------|--------------|
| 1 | Event history ordering through persist/restore | High | Not covered |
| 2 | Event payload serialization fidelity through persist/restore | High | Not covered |
| 3 | triggeringEvent preserved through persist/restore of @always chain | High | Not covered |
| 4 | Multi-action context mutations atomic in persisted diff | High | Not covered |
| 5 | machine_current_states correct after @always chain | Medium | Not covered |
| 6 | machine_current_states lifecycle for parallel state | Medium | Partially covered (creation only) |
| 7 | Archive/restore full machine equivalence | High | Not covered |
| 8 | raise() FIFO ordering with 3+ events | Medium | Not covered |
| 9 | Context visibility across exit/transition/entry actions | Medium | Not covered |
| 10 | Parallel state context survives persist/restore | Medium | Not covered |
| 11 | Event history records correct state value per step (parallel) | Medium | Partially covered |
| 12 | Listener context ordering vs entry actions | Medium | Not covered |
| 13 | Computed context methods after persist/restore | Medium | Not covered |
| 14 | No orphaned events after failed transition | Medium | Not covered |
| 15 | Incremental diff when value returns to initial | Medium | Not covered |
| 16 | Context null-assignment diff correctness | Medium | Not covered |
| 17 | MachineCast full round-trip fidelity | Low | Partially covered |
| 18 | Multi-step @always chain completes before raised events | Medium | Not covered |

## Actionable Gaps (18 beads)

All 18 gaps above require new tests. No duplicates with Pass 1, Pass 2, Pass 3, or Pass 4.
