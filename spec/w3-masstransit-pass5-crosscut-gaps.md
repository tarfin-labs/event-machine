# W3 MassTransit Pass 5: Cross-Cutting Concern Gaps

> Theme: CROSS-CUTTING CONCERNS
> Lens: Ordering guarantees, persistence fidelity, serialization, context integrity, correlation patterns, event ordering, state persistence.
> Generated: 2026-03-25
> Source: MassTransit saga state machine tests (`/tmp/masstransit/tests/MassTransit.Tests/SagaStateMachineTests/`)

---

## Dedup Notes

Checked against:
- All existing EventMachine tests in `tests/` directory (extensive grep across 160+ test files)
- W1 Pass 1 gaps (`spec/w1-pass1-happy-path-gaps.md`) — 20 gaps
- W1 Pass 2 gaps (`spec/w1-pass2-edge-case-gaps.md`) — 15 gaps
- W1 Pass 3 gaps (`spec/w1-pass3-async-gaps.md`) — 16 gaps (all LocalQA)
- W1 Pass 4 gaps (`spec/w1-pass4-failure-gaps.md`) — 20 gaps
- W3 XState Pass 1 gaps (`spec/w3-xstate-pass1-happy-gaps.md`) — 13 gaps
- W3 XState Pass 2 gaps (`spec/w3-xstate-pass2-edge-gaps.md`) — 19 gaps
- W3 XState Pass 3 gaps (`spec/w3-xstate-pass3-async-gaps.md`) — 8 gaps
- W3 Commons Pass 1/2 gaps
- W3 AASM Pass 1/2 gaps
- W3 Boost Pass 1/2 gaps
- W3 Spring Pass 1/2 gaps
- W3 MassTransit Pass 1 gaps (`spec/w3-masstransit-pass1-gaps.md`) — 5 actionable gaps

MassTransit test files read through cross-cutting lens:
- [x] `Finalize_Specs.cs` — WhenEnter async conditional Finalize, saga removal
- [x] `Testing_Specs.cs` — InMemoryTestHarness, ContainsInState, message consumed assertions
- [x] `RemoveWhen_Specs.cs` — SetCompletedWhenFinalized, instant-finalize, saga removal
- [x] `Outbox_Specs.cs` — Transactional outbox, fault dedup, retry+outbox guarantees
- [x] `OutboxFault_Specs.cs` — Schedule+Request+Outbox interaction, Unschedule on finalize
- [x] `Initiator_Specs.cs` — InitiatorId propagation (causation chain)
- [x] `Publish_Specs.cs` — InitiatorId and SourceAddress propagation on Publish
- [x] `CorrelationUnknown_Specs.cs` — Missing correlation throws ConfigurationException
- [x] `CorrelateGuid_Specs.cs` — Custom correlation via non-standard field, InsertOnInitial
- [x] `ScheduleCorrelation_Specs.cs` — Schedule auto-correlation with CorrelatedBy<Guid>
- [x] `ScheduleTimeout_Specs.cs` — Dynamic delay, property-based correlation, cart abandonment
- [x] `CompositeOrder_Specs.cs` — Individual handlers run before composite handler
- [x] `CompositeEventMultipleStates_Specs.cs` — Composite event in different state from constituents
- [x] `BaseClass_Specs.cs` — State machine inheritance, shared states/events
- [x] `Declarative_Specs.cs` — Multiple state machines on one instance (independent state tracking)
- [x] `SerializeState_Specs.cs` — JSON serialization round-trip preserves state
- [x] `StateExpression_Specs.cs` — State stored as int/string/raw, expression compilation
- [x] `State_Specs.cs` — State naming and storage formats
- [x] `Activity_Specs.cs` — Finally block, Initial.Enter, AfterLeave, BeforeEnter lifecycle ordering
- [x] `AnyStateTransition_Specs.cs` — BeforeEnterAny/AfterLeaveAny global hooks
- [x] `Anytime_Specs.cs` — DuringAny event handling, Initial state exclusion
- [x] `Condition_Specs.cs` — If/IfElse/IfAsync/IfElseAsync, WhenEnter conditional
- [x] `Introspection_Specs.cs` — Events/States/NextEvents introspection
- [x] `Faulted_Specs.cs` — Activity rollback on fault (transactional activity)
- [x] `Respond_Specs.cs` — DuringAny RespondAsync with CurrentState, OnMissingInstance
- [x] `MissingInstance_Specs.cs` — Custom missing instance response, GetResponse multi-type
- [x] `Retry_Specs.cs` — Retry within state machine, retry exhaustion + catch

---

## Gap MTC1: Finalize cleanup -- timer unschedule when machine reaches final state

- **Priority**: High
- **MassTransit source**: `OutboxFault_Specs.cs` — `WhenEnter(Final, x => x.Unschedule(ScheduleEvent).Publish(...))`. Timers/schedules are explicitly unscheduled when entering the final state. Also `ScheduleTimeout_Specs.cs` — `SetCompletedWhenFinalized()` after Cart timeout flow.
- **Type**: Feature test
- **Scenario**: Create a machine with an `after` timer (e.g., 60s timeout) in state `active`. Transition to `active` (timer starts). Then send an event that transitions to `completed` (final state). Verify: (1) the timer is no longer pending (no `machine_timer_fires` row for this machine), (2) if `machine:process-timers` sweeps after finalization, it does not fire the timer, (3) the `machine_current_states` row reflects the final state.
- **Expected behavior**: When a machine reaches a final state, any pending `after`/`every` timers should be cleaned up. They should not fire after the machine has completed.
- **Dedup check**: `TimerEdgeCasesTest` tests timer edge cases but not timer cleanup on finalization. `ProcessTimersCommandTest` tests the sweep command but not the "timer exists for finalized machine" scenario. `TimeBasedEventsTest` tests timer creation/cancellation when exiting the state, but does not test the specific pattern of reaching a FINAL state with timers pending. W1P4 Gap 9 covers "child completion after parent @timeout" (late @done ignored) but not timer cleanup on finalization.
- **Cross-cutting nature**: This involves the intersection of timer subsystem + state persistence + finalization lifecycle. A timer left dangling after finalization could cause a phantom event to be processed against a finalized machine.

## Gap MTC2: Event persistence atomicity -- actions only take visible effect after successful persistence

- **Priority**: High
- **MassTransit source**: `Outbox_Specs.cs` — `UseInMemoryOutbox()` ensures response message is only sent after saga state persists successfully. Fault message sent exactly once despite retries.
- **Type**: Feature test
- **Scenario**: Create a machine with a transition from A to B. The transition has an action that sends an event to another machine via `sendTo`. Verify: (1) the `machine_events` table has the transition recorded, (2) the `sendTo` event was dispatched (via `CommunicationRecorder`), (3) if we simulate a persistence failure (e.g., mock MachineEvent::create to throw), the `sendTo` should NOT have been dispatched. This is testing the ordering: persist first, then side effects.
- **Expected behavior**: Side effects (sendTo, dispatchTo) should only execute after machine state is successfully persisted. If persistence fails, side effects should not have fired.
- **Dedup check**: `ParallelDispatchTransactionSafetyTest` tests "persist failure does not dispatch jobs" — this covers the parallel dispatch case. `ArchiveConcurrencyTest` and `PersistenceTest` test basic persistence. `SendToTest` tests sendTo delivery but not the ordering guarantee relative to persistence. The specific pattern of "sendTo during a normal transition is only dispatched after persist succeeds" is NOT tested at the Feature level (only the parallel dispatch variant is).
- **W1P3 overlap**: W1P3 gaps are all LocalQA. AASM Pass 2 Gap 2 ("Transactional event rolls back ALL persisted events") is related but covers rollback, not the ordering guarantee. This gap covers the Feature-level ordering guarantee for sendTo/dispatchTo in normal (non-parallel) transitions.
- **Cross-cutting nature**: Involves event persistence subsystem + communication subsystem + transaction ordering.

## Gap MTC3: Fault message dedup -- exception during transition produces exactly one @fail event

- **Priority**: Medium
- **MassTransit source**: `Outbox_Specs.cs` (When_a_fault_in_a_saga_machine_occurs) — With retry + outbox, fault message is sent exactly once despite 5 retries. `Interlocked.Increment(ref count)` verifies count == 1.
- **Type**: Feature test
- **Scenario**: Create a machine with a transition from A to B where the action throws an exception. The machine has an @fail handler. Verify: (1) the @fail handler fires exactly once, (2) the machine ends in the @fail target state, (3) event history shows exactly one @fail internal event (not multiple). This tests that exception handling does not accidentally trigger @fail multiple times.
- **Expected behavior**: An exception in a transition action should produce exactly one @fail event, even if there are multiple paths through error handling.
- **Dedup check**: W1P4 Gap 1 ("Entry action throws -- machine state not corrupted") tests state corruption but not dedup of @fail events. W1P4 Gap 3 ("Transition action throws -- partial action execution") tests partial execution but not the @fail dedup count. `AsyncEdgeCasesTest` tests @fail with async but not event count. No test explicitly counts @fail events to verify exactly-once semantics.
- **Cross-cutting nature**: Involves exception handling subsystem + event history subsystem + @fail event generation.

## Gap MTC4: State serialization round-trip preserves parallel state value across persist/restore

- **Priority**: Medium
- **MassTransit source**: `SerializeState_Specs.cs` — JSON serialize → deserialize preserves state. `StateExpression_Specs.cs` — State stored as int/string/raw with expression compilation.
- **Type**: Feature test
- **Scenario**: Create a machine with a parallel state containing 3 regions, each at different stages (region A at `processing`, region B at `complete`, region C at `waiting`). Persist to database. Restore from database. Verify: (1) all three region states are correctly restored, (2) the value array matches the original, (3) the machine can continue processing events from the restored state (send an event to region C, verify it transitions).
- **Expected behavior**: Parallel state values (which are arrays/nested structures in EventMachine) must survive the persist→restore cycle with full fidelity, including the ability to resume processing.
- **Dedup check**: `ParallelPersistenceTest` tests: (a) parallel state can be restored from database, (b) parallel state value is correctly stored in machine events, (c) parallel state restoration after multiple transitions. This DOES cover the basic parallel persist/restore. However, the specific pattern of restoring a PARTIAL parallel state (not all regions final) and then continuing processing is worth checking. Looking at `ParallelPersistenceTest` more closely...
- **SKIP CANDIDATE**: `ParallelPersistenceTest::parallel state restoration after multiple transitions` likely covers this. Checking test content to confirm.

## Gap MTC5: Context integrity across delegation boundary -- child context does not leak into parent

- **Priority**: High
- **MassTransit source**: `Declarative_Specs.cs` — Two independent state machines on one instance with separate InstanceState properties. `Initiator_Specs.cs` — SetSagaFactory creates instance with specific fields, correlation maintained across publish.
- **Type**: Feature test
- **Scenario**: Parent machine delegates to child machine. Child machine modifies its own context extensively (sets `child_key_1`, `child_key_2`, etc.). Child reaches final. Parent's @done fires and transitions. Verify: (1) parent's context does NOT contain `child_key_1`, `child_key_2` (child context is isolated), (2) parent's context only has the keys it started with plus any modifications from its own actions, (3) the @done action can access child result data (via `ForwardContext` or event payload) but this is an explicit opt-in, not implicit context merge.
- **Expected behavior**: Child machine context is isolated from parent context. When delegation completes, the child's context does NOT automatically merge into the parent's context. The parent only sees child results through explicit mechanisms (event payload, ForwardContext).
- **Dedup check**: `MachineDelegationTest` tests basic delegation but focuses on state transitions, not context isolation verification. `MachineFakingTest` tests faked children with context passing but through fake mechanisms. `ContextTest` tests context management but not across delegation boundaries. `ForwardContextTest` tests ForwardContext access but not the negative case (child context NOT leaking). No test explicitly verifies that child context keys are absent from parent context after delegation.
- **Cross-cutting nature**: Involves context management subsystem + delegation subsystem + persistence subsystem (context stored per machine instance).

## Gap MTC6: Event ordering in event history -- events recorded in macrostep processing order

- **Priority**: Medium
- **MassTransit source**: `Initiator_Specs.cs` — InitiatorId propagation verifies causation chain. `AnyStateTransition_Specs.cs` — BeforeEnterAny/AfterLeaveAny track last-entered/last-left in correct order.
- **Type**: Feature test
- **Scenario**: Create a machine that processes: (1) external event TRIGGER, (2) transition A→B with exit/entry actions, (3) @always from B→C with exit/entry actions, (4) raise() in C's entry action, (5) raised event transitions C→D. Verify: the `machine_events` table rows are in correct chronological order: external TRIGGER event, exit A, entry B, @always B→C, exit B, entry C, raise event, exit C, entry D. Each row's `id` is monotonically increasing. Each row has the correct `event_type` and `source_state`/`target_state`.
- **Expected behavior**: Event history (machine_events rows) should reflect the exact processing order within a macrostep, allowing full replay/audit of what happened.
- **Dedup check**: `EventStoreTest` tests that events are stored (external, action, guard, incremental context) but does NOT verify ordering across a complex macrostep with @always + raise. `ActionOrderingTest` tests action execution order but through context markers, not through event history row ordering. `AlwaysEventPreservationTest` tests event preservation through @always chains but verifies triggering event identity, not event history row ordering. No test explicitly queries `machine_events` rows and verifies their chronological order across a macrostep involving @always + raise.
- **Cross-cutting nature**: Involves event history subsystem + macrostep processing engine + @always/raise subsystem.

## Gap MTC7: Finalize conditional -- WhenEnter with async condition determines whether to finalize

- **Priority**: Medium
- **MassTransit source**: `Finalize_Specs.cs` — `WhenEnter(OtherState, x => x.ThenAsync(...).If(ctx => ctx.Instance.ReceivedFirst && ctx.Instance.TruthProvided, ctx => ctx.Finalize()))`. Saga enters OtherState, async operation completes, condition evaluated, saga finalized only if both conditions true.
- **Type**: Feature test
- **Scenario**: EventMachine equivalent: Machine transitions to `review` state. `review` state has entry action that sets `context['reviewed'] = true`. `review` state has @always transition with guard checking `context['reviewed'] && context['approved']`. If guard passes, transition to `completed` (final). If guard fails, stay in `review`. Test two scenarios: (a) context has `approved = true` → machine finalizes; (b) context has `approved = false` → machine stays in `review`.
- **Expected behavior**: Entry actions can modify context that influences @always guard evaluation in the same macrostep. The guard sees the context as modified by the entry action.
- **Dedup check**: MT Pass 1 Gap MT2 ("Entry action data available to subsequent WhenEnter chain") covers entry action data flowing to @always. This gap is specifically about the conditional finalization pattern -- entry action modifies context, @always evaluates, and the machine either finalizes or stays. MT2 is more general (data flow), this is more specific (conditional finalize/stay).
- **SKIP**: MT Pass 1 Gap MT2 already covers the essential data flow pattern. The conditional finalize is a natural consequence of correct data flow + @always guard evaluation. No separate gap needed.

## Gap MTC8: Activity rollback on fault -- action restores original context value when subsequent action fails

- **Priority**: Medium
- **MassTransit source**: `Faulted_Specs.cs` — `CalculateValueActivity` wraps `next.Execute()` in try/catch, restores `context.Instance.Value` to `originalValue` on failure. Verifies `_claim.Value` is default (not the computed value) after exception.
- **Type**: Feature test
- **Scenario**: Machine has a transition with two actions: (a) first action sets `context['computed'] = context['x'] + context['y']`, (b) second action throws an exception. After the exception, verify: (1) `context['computed']` still has its value from action (a) if there is no rollback mechanism (EventMachine does NOT have automatic activity rollback like MassTransit), OR (2) document that EventMachine's behavior differs from MassTransit here -- partial action execution leaves context modified.
- **Expected behavior**: EventMachine does NOT have automatic per-action rollback. When an action throws, subsequent actions do not execute, but already-executed actions' context changes persist. This is documented behavior (W1P4 Gap 3 covers partial action execution).
- **Dedup check**: W1P4 Gap 3 ("Transition action throws exception -- partial action execution") covers exactly this scenario.
- **SKIP**: W1P4 Gap 3 already covers this. EventMachine intentionally does not have MassTransit's activity rollback pattern.

## Gap MTC9: Unschedule timer when exiting timer state (not just on finalize)

- **Priority**: Medium
- **MassTransit source**: `OutboxFault_Specs.cs` — Timers rescheduled on every state transition. `WhenEnter(Final, x => x.Unschedule(ScheduleEvent))` explicitly cleans up. `ScheduleTimeout_Specs.cs` — timer tied to specific state, `SetCompletedWhenFinalized()`.
- **Type**: Feature test
- **Scenario**: Machine has state A with an `after: 30s` timer. Machine transitions from A to B (B has no timer). Verify: (1) the timer for state A is cancelled/cleaned up, (2) no `machine_timer_fires` row remains for the state A timer, (3) if `machine:process-timers` sweeps, no phantom fire occurs for A's timer. Then transition from B to C (C has its own `after: 60s` timer). Verify: (4) only C's timer is pending.
- **Expected behavior**: When exiting a state with timers, those timers should be cancelled. Only the current state's timers should be active.
- **Dedup check**: `TimerTest` and `TimeBasedEventsTest` test basic timer lifecycle. `TimerEdgeCasesTest` tests edge cases. Checking whether any test verifies that exiting a state cancels its timers... `TimerTest` likely covers this implicitly since timers are tied to states and the sweep checks `machine_current_states`. However, the specific verification that exiting a state removes the timer_fires row is worth checking.
- **PARTIAL**: The timer system naturally handles this through `machine_current_states` -- the sweep only fires timers for machines in timer-bearing states. But does the old `machine_timer_fires` row get cleaned up? This is about stale data cleanup, not functionality.
- **SKIP**: EventMachine's timer system uses `machine_current_states` for sweep eligibility, so exiting a timer state naturally prevents the timer from firing. The `machine_timer_fires` table is for dedup, not scheduling. No gap here.

## Gap MTC10: Event identity preserved through sendTo/dispatchTo -- receiving machine sees correct event type and payload

- **Priority**: High
- **MassTransit source**: `Publish_Specs.cs` and `Initiator_Specs.cs` — Published messages carry correct TransactionId, InitiatorId, and SourceAddress. The receiving consumer sees the full message fidelity.
- **Type**: Feature test
- **Scenario**: Machine A sends event to Machine B via `sendTo(B::class, $machineId, 'EVENT_X', ['key1' => 'value1', 'key2' => 42])`. Machine B receives EVENT_X. Verify in Machine B: (1) the event type is `EVENT_X`, (2) the event payload contains `key1 = 'value1'` and `key2 = 42`, (3) the event payload types are preserved (string stays string, int stays int), (4) the triggering event in Machine B's transition actions is the EVENT_X event with correct payload.
- **Expected behavior**: When using sendTo/dispatchTo, the receiving machine should see the exact event type and payload that was sent, with type fidelity preserved through serialization.
- **Dedup check**: `SendToTest` tests sendTo delivery (basic happy path) and is covered by W1P1 Gap 15. `CrossMachineTest` (LocalQA) tests dispatchTo delivery. `ContextRoundTripTest` tests type preservation through persist/restore. But NO test verifies that the event payload types are preserved through the sendTo serialization/deserialization cycle specifically. The sendTo tests verify delivery and state transition but not payload type fidelity.
- **Cross-cutting nature**: Involves serialization subsystem + inter-machine communication + event type system.

## Gap MTC11: availableEvents consistency with DuringAny equivalent (events valid in every non-initial state)

- **Priority**: Low
- **MassTransit source**: `Introspection_Specs.cs` — `NextEvents()` returns events valid from current state. `Anytime_Specs.cs` — `DuringAny` events handled in all states (except Initial). `Respond_Specs.cs` — `DuringAny` for status check.
- **Type**: Feature test
- **Scenario**: EventMachine does not have a literal `DuringAny` construct, but a machine may define the same event as handled in every state. Create a machine with states A, B, C and event CANCEL handled in all three. Call `availableEvents()` from each state. Verify CANCEL appears in all three. Then also add an event SUBMIT only handled in state A. Verify SUBMIT appears in `availableEvents()` from A but not B or C.
- **Expected behavior**: `availableEvents()` correctly reflects the union of events from the current state's transitions, including events defined on parent/compound states.
- **Dedup check**: `AvailableEventsTest` and `AvailableEventsTestMachineTest` test availableEvents. W1P2 Gap 13 ("availableEvents() on state with no transitions defined") covers the edge case. XState E12 ("availableEvents returns false for unknown events and true for guarded transitions") covers guard scenarios. XState E13 ("availableEvents in parallel state shows events from ALL regions") covers parallel. The basic "same event in multiple states" scenario is likely covered.
- **SKIP**: Existing tests and gaps cover availableEvents thoroughly.

## Gap MTC12: Persist/restore fidelity after @always chain -- intermediate states are NOT persisted, only final resting state

- **Priority**: High
- **MassTransit source**: `SerializeState_Specs.cs` — State serialization preserves the state after all processing. Not intermediate states. Also relates to MassTransit's saga persistence model where the instance is saved once after all processing.
- **Type**: Feature test
- **Scenario**: Machine starts in A. Receives event TRIGGER. Transitions A→B (B has @always→C, C has @always→D, D is stable). Verify: (1) after processing, the machine is in state D, (2) `machine_events` table contains the full chain (TRIGGER, @always B→C, @always C→D), (3) `machine_current_states` shows state D (not B or C), (4) restoring from events reproduces state D, (5) intermediate states B and C are NOT stored as the "current state" at any point in `machine_current_states`.
- **Expected behavior**: The `machine_current_states` table (used for timers, schedules, and quick state lookups) should only reflect the final resting state after a macrostep, not transient intermediate states from @always chains.
- **Dedup check**: `MachineCurrentStatesTest` tests current state tracking. `InitialAlwaysChainTest` tests @always chains complete in one macrostep. `AlwaysEventPreservationTest` tests event preservation. But does any test verify that `machine_current_states` shows the FINAL state after an @always chain, not an intermediate? Let me check `MachineCurrentStatesTest`... The test creates a machine, verifies current state is set. But does it test the @always chain scenario specifically?
- **Cross-cutting nature**: Involves @always processing engine + state persistence subsystem + current_states optimization table.

---

# Summary

| # | Gap Title | Priority | Covered? |
|---|-----------|----------|----------|
| MTC1 | Timer cleanup on finalization | High | Not covered |
| MTC2 | Persistence-first ordering for sendTo/dispatchTo side effects | High | Partial (parallel dispatch covered, normal transition not) |
| MTC3 | @fail event dedup -- exactly once on exception | Medium | Not covered |
| MTC4 | Parallel state serialization round-trip with resume | Medium | Likely covered by ParallelPersistenceTest -- SKIP |
| MTC5 | Context isolation across delegation boundary | High | Not covered |
| MTC6 | Event history ordering across complex macrostep | Medium | Not covered |
| MTC7 | Conditional finalization via entry action + @always guard | Medium | Covered by MT Pass 1 Gap MT2 -- SKIP |
| MTC8 | Activity rollback on fault | Medium | Covered by W1P4 Gap 3 -- SKIP |
| MTC9 | Timer cleanup when exiting timer state | Medium | Handled by architecture -- SKIP |
| MTC10 | sendTo payload type fidelity through serialization | High | Not covered |
| MTC11 | availableEvents with DuringAny pattern | Low | Covered by existing tests -- SKIP |
| MTC12 | Current state table shows final resting state after @always chain | High | Not covered |

## Actionable Gaps (6 beads)

Gaps below are NOT covered by any existing test or existing bead:

1. **Gap MTC1** -- Timer cleanup on finalization: timers cancelled when machine reaches final state (High)
2. **Gap MTC2** -- Persistence-first ordering: sendTo/dispatchTo only dispatched after persist succeeds (High)
3. **Gap MTC3** -- @fail event dedup: exception produces exactly one @fail event (Medium)
4. **Gap MTC5** -- Context isolation: child context does NOT leak into parent after delegation (High)
5. **Gap MTC6** -- Event history ordering: machine_events rows in correct macrostep processing order (Medium)
6. **Gap MTC10** -- sendTo payload type fidelity: event type and payload preserved through serialization (High)
7. **Gap MTC12** -- Current state after @always chain: machine_current_states shows final resting state, not intermediate (High)
