# W3 MassTransit Pass 2: Edge-Case Gaps

> Theme: EDGE CASES & BOUNDARY CONDITIONS
> Lens: Missing instances, uncorrelated messages, ignored events, dynamic events, partitioning, duplicate composite events, catch/fault boundary conditions, activity rollback, conditional ignore, outbox dedup, instant finalize.
> Generated: 2026-03-25
> Source: MassTransit saga state machine tests (`/tmp/masstransit/tests/MassTransit.Tests/SagaStateMachineTests/`)

---

## Dedup Notes

Checked against:
- All existing EventMachine tests in `tests/` directory (grepped extensively for each gap topic)
- W3 MassTransit Pass 1 gaps in `spec/w3-masstransit-pass1-gaps.md` (5 actionable gaps: MT1-MT5, MT7)
- W1 Pass 1 gaps in `spec/w1-pass1-happy-path-gaps.md` (14 gaps)
- W1 Pass 2 gaps in `spec/w1-pass2-edge-case-gaps.md` (15 gaps)
- W1 Pass 3 gaps in `spec/w1-pass3-async-gaps.md` (16 gaps)
- W1 Pass 4 gaps in `spec/w1-pass4-failure-gaps.md` (26 gaps)
- W3 XState Pass 1 gaps in `spec/w3-xstate-pass1-happy-gaps.md` (13 gaps)
- W3 XState Pass 2 gaps in `spec/w3-xstate-pass2-edge-gaps.md` (19 gaps)
- W3 AASM Pass 1 gaps in `spec/w3-aasm-pass1-gaps.md` (8 gaps)
- W3 AASM Pass 2 gaps in `spec/w3-aasm-pass2-gaps.md` (8 gaps)
- W3 Boost Pass 1 gaps in `spec/w3-boost-pass1-gaps.md` (8 gaps)
- W3 Boost Pass 2 gaps in `spec/w3-boost-pass2-gaps.md` (8 gaps)
- W3 Spring Pass 1 gaps in `spec/w3-spring-pass1-gaps.md` (8 gaps)
- W3 Spring Pass 2 gaps in `spec/w3-spring-pass2-gaps.md` (7 gaps)
- W3 Commons Pass 1 gaps in `spec/w3-commons-pass1-gaps.md` (7 gaps)
- W3 Commons Pass 2 gaps in `spec/w3-commons-pass2-gaps.md` (8 gaps)

MassTransit test files read through edge-case lens:
- [x] `MissingInstance_Specs.cs` — OnMissingInstance custom response (ExecuteAsync -> RespondAsync)
- [x] `Fault_Specs.cs` — Self-observing fault, missing instance fault (OnMissingInstance -> Fault())
- [x] `Ignore_Specs.cs` — Ignore(event) in state, no fault produced for ignored events
- [x] `UnobservedEvent_Specs.cs` — UnhandledEventException, Ignore(event, filter), OnUnhandledEvent(Ignore)
- [x] `InMemoryDeadlock_Specs.cs` — Slow action + concurrent Complete/Cancel + new saga creation
- [x] `Partitioning_Specs.cs` — 100 saga creations with 4-way partitioner
- [x] `DynamicEvent_Specs.cs` — Dynamic event registration via Event<T>(nameof(T))
- [x] `CatchFault_Specs.cs` — Catch<NotSupportedException> with Respond+Publish in catch block
- [x] `CatchInitial_Specs.cs` — Activity fault in Initial state, Catch<Exception> -> Finalize
- [x] `FilterFault_Specs.cs` — UseMessageRetry(x => x.Ignore<NotSupportedException>), attempt counter
- [x] `FaultRescue_Specs.cs` — UseRescue pipe middleware, state persisted before fault
- [x] `Faulted_Specs.cs` — Activity rollback: originalValue saved, restored on exception in downstream
- [x] `Outbox_Specs.cs` — UseInMemoryOutbox, guard-like filter (When with condition), fault dedup with retry
- [x] `Respond_Specs.cs` — OnMissingInstance(m => m.Fault()), DuringAny status query
- [x] `Combine_Specs.cs` — CompositeEventOptions.RaiseOnce vs None, duplicate event triggers
- [x] `CompositeEventMultipleStates_Specs.cs` — CompositeEvent across different states than constituent handlers
- [x] `RemoveWhen_Specs.cs` — Instant-finalize (Initially -> Respond -> Finalize in one transition)
- [x] `CorrelationUnknown_Specs.cs` — ConfigurationException when correlation not configured
- [x] `Exception_Specs.cs` — Catch ordering (specific before general), catch -> subsequent action continues, catch in IfElse, catch -> Finalize
- [x] `Retry_Specs.cs` — Retry exhaustion -> exception propagates, retry + Catch combination
- [x] `Anytime_Specs.cs` — DuringAny not handled in Initial, UnhandledEventException
- [x] `Condition_Specs.cs` — If/IfElse/IfAsync/IfElseAsync, WhenEnter with If conditional
- [x] `Finalize_Specs.cs` — WhenEnter with async condition -> Finalize

---

## Gap MT-E1: Ignored event in specific state produces no fault (silent ignore)

- **Priority**: Medium
- **MassTransit source**: `Ignore_Specs.cs` -- `Ignore(Started)` during Running. Duplicate Start event is silently ignored: no fault message produced, no exception thrown, state unchanged. Test explicitly verifies NO fault message is published.
- **Type**: Feature test (edge case)
- **Scenario**: Machine in state B handles EVENT_X -> B (self-transition). Machine also has `Ignore(EVENT_Y)` equivalent in state B: no transition defined for EVENT_Y, but the event should not throw. Send EVENT_Y to machine in state B. Verify: (1) no exception is thrown, (2) machine stays in state B, (3) no error event appears in history.
- **Expected behavior**: EventMachine's behavior when an event has no matching transition in the current state is to throw `NoTransitionDefinitionFoundException`. This is fundamentally different from MassTransit's `Ignore()` which silently swallows the event. The question is whether EventMachine SHOULD have an ignore mechanism, or whether the exception is the correct behavior. This test documents the boundary.
- **Dedup check**: Grepped "ignored.*event", "silently.*ignore" in tests/ -- found `TimerVerificationTest.php` mentions timer events being ignored and `AsyncMachineDelegationTest.php` mentions ignored events. W1P2 Gap 15 covers "sending event to machine at final state" which tests the rejection. W3 Spring Gap 2 covers "send returns ACCEPTED/DENIED semantics." Neither covers the specific pattern of testing that unhandled events in a non-final state are properly rejected (or silently handled). However, this is actually COVERED by W3 Spring Gap 2 which tests the full send-invalid-event-exception-state-unchanged cycle.
- **SKIP**: Covered by W3 Spring Pass 2 Gap 2 (send event ACCEPTED/DENIED semantics).

## Gap MT-E2: Conditional ignore -- event ignored only when filter matches (filtered ignore)

- **Priority**: Medium
- **MassTransit source**: `UnobservedEvent_Specs.cs` (`Raising_an_ignored_event_that_is_not_filtered`) -- `Ignore(Charge, x => x.Data.Volts == 9)`. Charge with Volts=9 is ignored. Charge with Volts=12 throws `UnhandledEventException`. The ignore is CONDITIONAL: the filter determines whether the event is swallowed or rejected.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine equivalent: A state has two transition branches for the same event. Branch 1 has a guard checking `payload.amount > 100` -> transition. Branch 2 has no guard (fallback). When guard fails AND no fallback exists, the event is unhandled. Test the boundary: (a) event with amount=200 matches guard -> transitions, (b) event with amount=50 fails guard AND has no fallback -> exception.
- **Expected behavior**: When all guards fail for an event and no unguarded fallback transition exists, `NoTransitionDefinitionFoundException` is thrown. The machine state is unchanged.
- **Dedup check**: W1P1 Gap 1 (`ht-w1p1-guard-first-match`) covers first-match semantics. W1P2 Gap 2 covers "all guards false for @always." Neither specifically tests "all guards false for a regular event (not @always) -> exception." `TransitionDefinitionTest.php` tests unknown events. `CalculatorTest.php` tests calculator fallback. No test for: regular event with only guarded branches where all guards fail.
- **W1 overlap**: Partially related to W1P2 Gap 2 (all guards false) but that's @always-specific. This is about regular events. No direct overlap.

## Gap MT-E3: Duplicate composite event constituent -- RaiseOnce vs fire-every-time semantics

- **Priority**: High
- **MassTransit source**: `Combine_Specs.cs` (`When_multiple_events_trigger_a_composite_event`) -- With `CompositeEventOptions.RaiseOnce`, sending Second twice after First fires the composite exactly once (TriggerCount=1). With `CompositeEventOptions.None`, sending Second twice fires the composite twice (TriggerCount=2). Tests the idempotency boundary of composite event completion.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine equivalent: Parallel state with two regions. Region A and B each have a transition to their final state. After both regions reach final, @done fires. Then send an event that would cause Region B to re-enter its final state (self-transition on final). Verify @done does NOT fire a second time (EventMachine should have RaiseOnce semantics).
- **Expected behavior**: @done fires exactly once per parallel state activation. Even if a region's final state is re-entered, @done should not re-fire. This is the EventMachine equivalent of RaiseOnce.
- **Dedup check**: W3 XState Pass 1 Gap X4 covers "@done fires exactly once on simultaneous completion." W3 XState Pass 2 Gap E7 covers "parallel NOT done when only some regions final." W3 Boost Pass 2 Gap 7 covers "double @done firing." These collectively cover the single-fire semantics. However, NONE tests the specific edge case of a region re-entering final AFTER @done has already fired. The existing tests verify @done fires once during normal completion, not that it doesn't re-fire if a region re-enters final.
- **W1/W3 overlap**: Partially covered by Boost P2 Gap 7 ("@done cannot fire twice") but that gap tests from the "send event after @done" angle, not the "region re-enters final" angle. The scenarios are related but the trigger mechanism differs.
- **SKIP**: Effectively covered by Boost P2 Gap 7 which already tests that @done/events after parallel completion don't cause double-fire.

## Gap MT-E4: Composite event across multiple states (constituent handled in different state than composite handler)

- **Priority**: Medium
- **MassTransit source**: `CompositeEventMultipleStates_Specs.cs` -- Constituent event `First` is handled in `Waiting` (transitions to `WaitingForSecond`). Composite event `Third` is handled in `WaitingForSecond` (different state). The composite can be defined before or during machine construction and still work across state boundaries.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine equivalent: This maps to a scenario where parallel regions complete at different times and the machine transitions through intermediate states. Region A completes and an intermediate transition fires. Then Region B completes and @done fires from the new intermediate state. This is already inherent in how EventMachine processes parallel states -- regions complete independently and @done fires from whatever state the parallel machinery tracks.
- **Expected behavior**: In EventMachine's parallel model, @done is tied to the parallel state, not to the state the machine was in when each constituent completed. This is fundamentally different from MassTransit's composite events which can be handled in any state.
- **Dedup check**: EventMachine's @done is always handled at the parallel state level (defined in the parallel state config), not in arbitrary states. The MassTransit pattern of "composite event in a different state" doesn't directly translate because EventMachine's @done is structural, not a user-defined composite.
- **SKIP**: Not applicable -- EventMachine's @done is structural to parallel states, not a cross-state composite pattern.

## Gap MT-E5: Activity rollback on downstream exception (faulted handler restores original value)

- **Priority**: High
- **MassTransit source**: `Faulted_Specs.cs` (`Having_an_activity_with_faulted_handler`) -- `CalculateValueActivity` saves `originalValue`, calls `next.Execute(context)`, catches exception, restores `originalValue`. After the exception, `_claim.Value` is `(string)default` (restored), not "79" (calculated). This proves the activity's try/catch pattern allows cleanup/rollback.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine equivalent: A calculator behavior sets `context['computed'] = 42`. A subsequent action throws an exception. In EventMachine, does the calculator's context mutation persist in the event history or is it rolled back? This tests the atomicity boundary of context mutations within a single transition.
- **Expected behavior**: In EventMachine, context mutations happen via `ContextManager` and are persisted as part of the macrostep. If an action throws after a calculator has modified context, the behavior depends on whether the transition is transactional. With transactional events, context changes should be rolled back. Without, they may persist.
- **Dedup check**: W3 AASM Pass 2 Gap 1 covers "validation guard failure preserves pre-transition context (no context mutation)." W1P4 Gap 3 covers "transition action throws -- partial execution." The AASM gap focuses on ValidationGuardBehavior failure, and W1P4 Gap 3 focuses on partial action execution consistency. Neither covers the specific pattern of a calculator's context mutation being visible or rolled back after a subsequent action exception. However, W1P4 Gap 3 IS close enough -- it tests "action 1 executes, action 2 throws, is state consistent?" The calculator-before-action variant is a sub-case.
- **SKIP**: Effectively covered by W1P4 Gap 3 (transition action partial execution) and AASM P2 Gap 1 (validation guard context preservation). Together they cover the "context mutation before exception" boundary.

## Gap MT-E6: Catch block with side effects -- respond and publish in exception handler

- **Priority**: High
- **MassTransit source**: `CatchFault_Specs.cs` -- `Catch<NotSupportedException>(ex => ex.Respond(...).Publish(...).TransitionTo(FailedToStart))`. Exception caught, response sent, event published, and machine transitions to error state. All within the catch block. Also: `Catching_a_fault_and_finalizing` -- catch -> respond -> publish -> Finalize (saga removed).
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine does not have a Catch<T> mechanism on transitions. Exceptions in actions propagate to the caller. The closest equivalent is a @fail handler on child delegation that catches child failures and performs side effects (context updates, transitions). However, the MassTransit pattern is about catching exceptions WITHIN a transition's action pipeline, not child delegation failures.
- **Expected behavior**: EventMachine's action exceptions propagate. There is no in-transition catch mechanism.
- **Gap assessment**: This is NOT directly applicable to EventMachine. EventMachine uses @fail for child delegation failures and exception propagation for action failures. The "catch within transition" pattern is a MassTransit-specific feature. However, the EFFECT is tested: W1P4 Gaps 1-3 test entry/exit/transition action exceptions and verify state consistency. The @fail handler pattern for child delegation is tested in W1P4 Gap 6.
- **SKIP**: Not applicable -- EventMachine does not have in-transition catch. Action exception behavior covered by W1P4 Gaps 1-3.

## Gap MT-E7: Exception catch ordering -- specific exception type caught before generic

- **Priority**: Medium
- **MassTransit source**: `Exception_Specs.cs` (`When_an_action_throws_an_exception`) -- `.Catch<ApplicationException>()` catches, `.Catch<Exception>()` does NOT fire (specific match wins). Tests that `ShouldNotBeCalled` is false. Also: `When_the_exception_does_not_match_the_type` -- `Catch<Exception>()` catches `ApplicationException` (subtype) with correct type/message.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine does not have typed exception catching. This is MassTransit-specific.
- **SKIP**: Not applicable -- EventMachine does not have Catch<T> exception handling.

## Gap MT-E8: Retry exhaustion propagates exception (not silently swallowed)

- **Priority**: Medium
- **MassTransit source**: `Retry_Specs.cs` -- `.Retry(r => r.Intervals(10, 10, 10), x => x.Then(throw))` retries 3 times then propagates `IntentionalTestException`. `Should_retry_the_activities` verifies exception propagates after exhaustion. `Should_retry_the_activities_and_still_allow_catch` verifies retry + `.Catch<>()` combination works.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine does not have built-in retry within the state machine. Retry is handled at the queue/job level (Laravel's job retry mechanism). The closest equivalent is testing that failed jobs are retried by Horizon.
- **SKIP**: Not applicable -- EventMachine uses Laravel's queue retry mechanism, not in-machine retry.

## Gap MT-E9: Self-observing fault -- machine handles its own action's fault event

- **Priority**: High
- **MassTransit source**: `Fault_Specs.cs` (`Should_be_able_to_observe_its_own_event_fault`) -- Machine in WaitingToStart. Send Start (which throws). Fault<Start> is published. Machine handles Fault<Start> via `StartFaulted` event. Machine transitions to FailedToStart. The machine observes its OWN fault.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine equivalent: Machine in state A. A transition action throws. The machine needs to handle the exception and transition to an error state, rather than leaving the exception unhandled. In EventMachine, this maps to: (a) action throws -> exception propagates to caller, (b) caller catches and sends a FAILURE event, (c) machine handles FAILURE event and transitions to error state. OR using @fail in child delegation.
- **Expected behavior**: EventMachine does not have self-fault observation. Actions that throw propagate the exception. The pattern of "machine handles its own fault event" requires external coordination (the caller catches and re-sends).
- **Dedup check**: W1P4 Gaps 1-3 cover action exception propagation. The self-fault pattern is a MassTransit message-bus feature (Fault<T> auto-published) that doesn't map to EventMachine's architecture.
- **SKIP**: Not applicable -- EventMachine's error handling model uses exception propagation and @fail for delegation, not self-fault observation.

## Gap MT-E10: Missing instance fault -- event sent to non-existent instance auto-faults

- **Priority**: High
- **MassTransit source**: `Fault_Specs.cs` (`Should_receive_a_fault_when_an_instance_does_not_exist`) -- `OnMissingInstance(m => m.Fault())`. Send Stop to non-existent saga instance. Fault<Stop> is published. `Respond_Specs.cs` (`Should_fault_on_a_missing_instance`) -- same pattern, RequestFaultException thrown.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine equivalent: `sendTo` or `dispatchTo` sends an event to a machine rootEventId that does not exist in `machine_events`. What happens? Does it throw? Does it auto-restore from archive? Does it silently fail?
- **Expected behavior**: If no events exist for the rootEventId and no archive exists, the send should throw a descriptive exception.
- **Dedup check**: W1P4 Gap 28 covers "sendTo non-existent machine." The MassTransit pattern maps directly to this gap.
- **SKIP**: Covered by W1P4 Gap 28 (sendTo non-existent machine).

## Gap MT-E11: Missing instance with custom response (OnMissingInstance -> ExecuteAsync)

- **Priority**: Medium
- **MassTransit source**: `MissingInstance_Specs.cs` -- `OnMissingInstance(m => m.ExecuteAsync(context => context.RespondAsync(new InstanceNotFound(context.Message.ServiceName))))`. Custom response returned instead of fault. Uses `GetResponse<Status, InstanceNotFound>` dual-response pattern.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine equivalent: Sending an event to a non-existent machine via an HTTP endpoint. The endpoint should return a structured error response (404 with "instance not found" payload) rather than a 500 error.
- **Expected behavior**: EventMachine's HTTP endpoints handle missing machine instances. `MachineController` attempts to restore the machine. If it doesn't exist, it should return an appropriate HTTP error.
- **Dedup check**: `EndpointEdgeCasesTest.php` tests "endpoint for non-existent machine-id-bound machine returns 404." This covers the HTTP endpoint layer. The gap is whether the non-HTTP path (sendTo) also handles this gracefully.
- **SKIP**: HTTP path covered by `EndpointEdgeCasesTest`. Non-HTTP path covered by W1P4 Gap 28.

## Gap MT-E12: Catch -> subsequent action continues (exception swallowed, pipeline resumes)

- **Priority**: Medium
- **MassTransit source**: `Exception_Specs.cs` (`When_the_exception_is_caught`) -- `.Then(throw).Catch<Exception>(ex => ex).Then(context => context.Instance.Called = true)`. After catch (with empty handler), the NEXT action in the pipeline runs. `Called` is true.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine does not have catch-and-resume. Exceptions propagate.
- **SKIP**: Not applicable -- EventMachine does not have in-transition catch/resume.

## Gap MT-E13: Outbox guarantees fault published exactly once despite retries

- **Priority**: High
- **MassTransit source**: `Outbox_Specs.cs` (`Should_receive_the_fault_message_once`) -- `UseMessageRetry(r => r.Immediate(5))` + `UseInMemoryOutbox()`. Action throws. Despite 5 retry attempts, the fault message is published exactly ONCE (`count == 1`). The outbox deduplicates.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine equivalent: A machine action throws. Laravel's job retry mechanism retries the job 5 times. Each attempt calls `Machine::send()`. EventMachine persists events to `machine_events`. The question is: do we get duplicate events in `machine_events` from the retry attempts? EventMachine's lock mechanism (`MachineLockManager`) should prevent concurrent processing, and each retry starts fresh (restoring from events). But does the machine_events table end up with duplicate transition/action events?
- **Expected behavior**: Each retry attempt should start from the last persisted state. If the action throws before persistence, no events are written. If it throws after persistence, the retry should see the new state and potentially handle the event differently.
- **Dedup check**: W3 AASM Pass 2 Gaps 2-3 cover "transactional event rolls back ALL events" and "non-transactional event persists partial state." These address the persistence boundary but not the retry-specific dedup question. W1P3 Gap 1 covers "concurrent send() both succeed in sequence" which tests locking. No test specifically verifies event persistence idempotency across job retries.
- **W1 overlap**: Related to W1P3 Gap 1 (concurrent sends) and AASM P2 Gaps 2-3 (transaction rollback). This is a distinct edge case: retry of the same event on the same machine after a failure mid-transition.

## Gap MT-E14: Instant finalize -- machine created and finalized in single transition

- **Priority**: Medium
- **MassTransit source**: `RemoveWhen_Specs.cs` (`When_a_saga_goes_straight_to_finalized`) -- Machine receives Ask, responds with Answer, and immediately calls `.Finalize()` in the Initial state. The saga is created and removed in one step. Tests that the saga does NOT exist after the single transition.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine equivalent: A machine's initial state is type: final. The machine is created, MACHINE_START fires, initial state is entered, and since it's final, MACHINE_FINISH fires immediately. Test that this works without errors and the machine correctly reaches its final state in a single macrostep.
- **Expected behavior**: Machine enters initial state which is final. MACHINE_FINISH fires. Machine is in completed state.
- **Dedup check**: W1P2 Gap 6 covers "machine with single final state (send throws)" which tests a machine where initial IS final. `StateDefinitionTest.php` tests "initial state of type final triggers machine finish." `RootEntryExitTest.php` tests "runs root exit before MACHINE_FINISH when initial state is final." The basic scenario IS covered. The edge case of "send event in initial that immediately transitions to final" is slightly different -- it's about a two-state machine where the first event immediately finishes.
- **SKIP**: Core scenario covered by W1P2 Gap 6 and existing tests. The "event in initial -> immediate final" variant is a minor extension.

## Gap MT-E15: DuringAny event NOT handled in Initial state -- UnhandledEventException

- **Priority**: Medium
- **MassTransit source**: `Anytime_Specs.cs` (`Should_not_be_handled_on_initial`) -- `DuringAny` defines events handled in ALL states except Initial. Sending Hello in Initial throws `UnhandledEventException`. `HelloCalled` remains false.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine does not have a `DuringAny` mechanism. The closest equivalent is defining the same event on multiple states. However, the edge case here is about the Initial state being special -- in MassTransit, DuringAny excludes Initial. In EventMachine, there's no equivalent distinction since events are defined per-state.
- **SKIP**: Not applicable -- EventMachine does not have DuringAny. Events are defined per-state explicitly.

## Gap MT-E16: Rescue middleware -- state persisted before fault, fault event self-correlated

- **Priority**: Medium
- **MassTransit source**: `FaultRescue_Specs.cs` -- `UseRescue(rescuePipe)` wraps saga. Machine transitions to WaitingToStart first (state persisted!), then throws. The fault event `Fault<Start>` arrives, which transitions WaitingToStart -> FailedToStart. Key insight: the state is PERSISTED before the exception, so the fault handler can process from the new state.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine equivalent: Action throws AFTER state transition and persistence. The exception propagates to the caller. The caller (or a retry job) can then send a FAILURE event. But the machine is already in the new state (WaitingToStart equivalent), so the failure event is handled from the new state. This tests that state persistence happens BEFORE action execution completes (mid-macrostep persistence).
- **Expected behavior**: In EventMachine, state changes are persisted as events during the macrostep. If an action throws after the state change event is persisted, the machine's persisted state reflects the transition. A subsequent event operates from the new state.
- **Dedup check**: W1P4 Gap 1 covers "entry action throws -- state not corrupted" which tests whether the machine state is consistent after an exception. The specific question of "is the state change persisted before the action completes?" is about EventMachine's persistence model. The `isTransactional` flag controls this -- with transactions, the persistence is atomic (all or nothing). Without, partial persistence is possible.
- **SKIP**: Covered by W1P4 Gap 1 and W3 AASM P2 Gaps 2-3 (transaction rollback semantics).

## Gap MT-E17: Guard-like event filter -- same event with two conditional handlers

- **Priority**: High
- **MassTransit source**: `Outbox_Specs.cs` -- `When(Started, x => x.Data.FailToStart)` and `When(Started, x => x.Data.FailToStart == false)`. Same event type, two handlers with opposite conditions. The condition acts as a guard: one handler throws, the other succeeds. Filter determines which path fires.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine equivalent: A state has two transition branches for the same event with opposite guards. Branch 1: guard checks `payload.fail == true` -> action throws. Branch 2: guard checks `payload.fail == false` -> transitions to Running. Send event with `fail=true` -> first branch fires, exception. Send event with `fail=false` -> second branch fires, transitions. This tests the guard-as-event-filter boundary.
- **Expected behavior**: Guards act as event filters. Only the first matching guard's transition branch executes. If the matching branch's action throws, the exception propagates (no fallback to the next branch).
- **Dedup check**: W1P1 Gap 1 (`ht-w1p1-guard-first-match`) covers first-match semantics with guards. `FilterExpression_Specs.cs` in MassTransit mirrors this. The specific pattern of "same event, opposite guards, one throws" combines guard-first-match with action exception handling. W1P4 Gap 4 covers "guard throws RuntimeException." These are close but distinct: this gap is about the guard PASSING and then the guarded action throwing, not the guard itself throwing.
- **Dedup verdict**: The combination of W1P1 Gap 1 (guard first-match) + W1P4 Gap 1 (entry action throws) covers both aspects independently. A combined test would be nice but is not a critical gap.
- **SKIP**: Covered by combination of W1P1 Gap 1 and W1P4 Gap 1.

## Gap MT-E18: Catch -> Finalize (exception triggers machine removal/final state)

- **Priority**: Medium
- **MassTransit source**: `Exception_Specs.cs` (`When_an_action_throws_an_exception_and_catches_it`) -- `.Then(throw).Catch<Exception>(ex => ex.Finalize())`. Exception caught, machine goes to Final state.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine equivalent: An action throws, and the desired behavior is to transition the machine to a final state. In EventMachine, this requires the caller to catch the exception and send a dedicated failure event that has a transition to a final state.
- **SKIP**: Not applicable -- EventMachine does not have in-transition catch/finalize.

## Gap MT-E19: NextEvents includes ignored events (ignored event still appears in available events)

- **Priority**: Medium
- **MassTransit source**: `UnobservedEvent_Specs.cs` (`Raising_an_ignored_event` -> `Should_have_the_next_event_even_though_ignored`) -- Machine in Running state where Charge is ignored. `NextEvents()` still includes Charge. Ignored events appear in the available events list.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine equivalent: `availableEvents()` returns events that have transitions defined, regardless of whether guards would pass. The question is: should events that would always fail (all guards false) appear in `availableEvents()`?
- **Expected behavior**: EventMachine's `availableEvents()` returns event types that have at least one transition branch defined for the current state, regardless of guard evaluation.
- **Dedup check**: W3 XState Pass 2 Gap E12 covers "availableEvents returns false for unknown events and true for guarded transitions" which tests guard-filtered available events. This is the OPPOSITE of the MassTransit pattern (MassTransit includes ignored events; XState excludes failed-guard events). The EventMachine behavior needs to be documented either way.
- **SKIP**: Covered by XState P2 Gap E12 which tests guard-filtered availableEvents edge cases.

## Gap MT-E20: Slow action + concurrent events -- deadlock prevention

- **Priority**: High
- **MassTransit source**: `InMemoryDeadlock_Specs.cs` -- Complete event triggers 1s delay action. During delay, Cancel event sent to same instance. Then a NEW saga is created. Tests that repository doesn't deadlock. Key edge case: concurrent operations on same instance + operations on different instances during the lock hold.
- **Type**: LocalQA test (edge case)
- **Scenario**: EventMachine equivalent: Machine has a slow action (sleeps 5s). While action is running (lock held), another process sends a different event to the same machine AND creates a new machine. Verify: (1) second event gets MachineAlreadyRunningException or waits, (2) new machine creation is unaffected by the lock on the first machine, (3) no deadlock between the two operations.
- **Expected behavior**: Locks are per-machine (per rootEventId). A slow action on Machine A should not block creation of Machine B. The concurrent event on Machine A should either wait or be rejected.
- **Dedup check**: W1P3 Gap 1 covers "concurrent send() to same instance -- lock serializes correctly." W1P3 Gap 2 covers "send() during parallel region -- MachineAlreadyRunningException." The specific MassTransit pattern adds the "create new machine WHILE another is locked" dimension. In EventMachine, machine creation (`Machine::create()`) does NOT acquire a lock (it just inserts events), so new machines should be unaffected. The critical question is whether the lock on machine A could block unrelated operations.
- **SKIP**: Covered by W1P3 Gaps 1-2. Machine creation is lock-free by design.

## Gap MT-E21: Configuration validation -- event with no correlation throws at connection time

- **Priority**: Low
- **MassTransit source**: `CorrelationUnknown_Specs.cs` -- Event `Second` has no `CorrelatedBy<Guid>` and no explicit correlation configured. Connecting the saga to the bus throws `ConfigurationException`. This is a definition-time validation.
- **Type**: Not applicable
- **Scenario**: EventMachine does not use message correlation (events are sent to specific rootEventIds). This is MassTransit-specific.
- **SKIP**: Not applicable -- EventMachine uses explicit rootEventId targeting, not message correlation.

## Gap MT-E22: Catch in IfElse block -- exception handling within conditional branch

- **Priority**: Medium
- **MassTransit source**: `Exception_Specs.cs` (`When_the_exception_is_caught_within_an_else`) -- `IfElse(false, then -> TransitionTo(Completed), else -> Then(throw).TransitionTo(NotCompleted).Catch<Exception>(ex -> TransitionTo(Failed)))`. Exception in the else branch caught by the else branch's catch, transitions to Failed.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine does not have in-transition IfElse or Catch. Guards handle branching, exceptions propagate.
- **SKIP**: Not applicable -- EventMachine does not have in-transition conditional blocks or catch.

## Gap MT-E23: Outbox guard-like filter -- conditional handler on event payload field

- **Priority**: Medium
- **MassTransit source**: `Outbox_Specs.cs` -- `When(Started, x => x.Data.FailToStart)` acts as a guard on the event payload. If FailToStart is true, the handler runs (and throws). If false, the next handler runs.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine's guards can access the event payload via parameter injection. A guard that checks `$event->payload['fail'] === true` is the direct equivalent. This is standard EventMachine guard behavior.
- **SKIP**: Covered by existing guard tests that use event payload in guard evaluation.

---

## NEW GAPS (not covered by any existing gap or bead)

### Gap MT-E24: All regular event guards false -- machine stays in state, no exception

- **Priority**: High
- **MassTransit source**: `UnobservedEvent_Specs.cs` (`Raising_an_ignored_event_that_is_not_filtered`) -- `Ignore(Charge, x => x.Data.Volts == 9)`. Charge with Volts=12 does NOT match the ignore filter, so `UnhandledEventException` is thrown. This tests the boundary between "handled but ignored" and "truly unhandled."
- **Type**: Feature test (edge case)
- **Scenario**: Machine in state B has a transition on EVENT_X with two guarded branches. Guard 1: `payload.type == 'A'` -> state C. Guard 2: `payload.type == 'B'` -> state D. No unguarded fallback. Send EVENT_X with `payload.type == 'C'` (neither guard matches). Verify: (1) exception is thrown (NoTransitionDefinitionFoundException or similar), (2) machine stays in state B, (3) no actions executed, (4) event history records the rejected event.
- **Expected behavior**: When all guards fail for a regular event (not @always), the event is unhandled. Machine stays in current state. Exception thrown. This is different from @always where all-guards-false just means "stay in state" (no exception).
- **Dedup check**: W1P2 Gap 2 covers "all guards false for @always" (stays in state, no exception). W1P1 Gap 1 covers "guard first-match" (happy path). No gap covers the specific scenario of "all guards fail for a REGULAR event -> exception thrown." `TransitionDefinitionTest.php` tests "no transition found" but for unknown events, not for known events with all-failing guards. `CalculatorTest.php` line 197 tests calculator failure fallback. No test sends a known event where all guard branches fail.
- **W1 overlap**: Distinct from W1P2 Gap 2 (@always specific). New gap.

### Gap MT-E25: Retry + Catch combination -- retry exhausted, then catch handles

- **Priority**: Medium
- **MassTransit source**: `Retry_Specs.cs` (`Should_retry_the_activities_and_still_allow_catch`) -- `.Retry(3).Catch<IntentionalTestException>(x => x.Then(caught = true))`. Retries exhaust, then the catch block runs. `Caught` is true. This tests the boundary between retry and exception handling.
- **Type**: Feature test (edge case)
- **Scenario**: EventMachine equivalent: A job-based child delegation has `tries: 3` in its queue config. The job fails 3 times. After final failure, the job's `failed()` method fires, which triggers @fail on the parent. The parent's @fail handler sets context and transitions.
- **Expected behavior**: After all retries exhaust, the failure handler (@fail) fires and the parent transitions to an error state.
- **Dedup check**: W1P4 Gap 20 covers "job actor @fail with guarded branches." `JobActorTest.php` tests job failure triggers @fail. The retry-then-fail chain is tested by Laravel's job retry mechanism + EventMachine's `ChildJobJob::failed()`. The specific pattern of "retry N times, then @fail fires" is implicitly covered but not explicitly tested with retry count verification.
- **SKIP**: Implicitly covered by W1P4 Gap 20 and existing job actor tests.

### Gap MT-E26: Event persistence atomicity -- retry of same event does not create duplicate machine_events

- **Priority**: High
- **MassTransit source**: `Outbox_Specs.cs` (`Should_receive_the_fault_message_once`) -- With retry + outbox, fault published exactly once. Despite 5 retries, only 1 fault message. The outbox ensures atomicity.
- **Type**: Feature test (edge case)
- **Scenario**: Machine transition action writes to context, then throws. The job retries. On retry, `Machine::send()` restores state from `machine_events` and attempts the transition again. Verify: (1) after retry, `machine_events` does not contain duplicate events from the failed first attempt, (2) the final successful attempt creates exactly the expected events, (3) the context reflects only the successful attempt's mutations.
- **Expected behavior**: When a transition fails (action throws) with transactional events, the `machine_events` writes from that attempt are rolled back. The retry starts from the same state. No duplicate events. When non-transactional, the failed attempt's events MAY persist, but the retry sees them and operates correctly.
- **Dedup check**: AASM P2 Gap 2 covers "transactional event rolls back ALL persisted events." AASM P2 Gap 3 covers "non-transactional event persists partial state." These cover the persistence boundary. The specific scenario of "retry after failed attempt, verify no duplicates" is a composition of these two gaps. No existing gap tests the full retry-restore-send-verify-no-duplicates cycle.
- **W1 overlap**: Close to AASM P2 Gaps 2-3 but adds the retry dimension (restore + re-send after failure).

---

# Summary

| # | Gap Title | Priority | Covered? |
|---|-----------|----------|----------|
| MT-E1 | Ignored event produces no fault | Medium | SKIP (Spring P2 Gap 2) |
| MT-E2 | All regular event guards false -- exception thrown | High | NOT COVERED (new) |
| MT-E3 | Duplicate composite constituent -- RaiseOnce semantics | High | SKIP (Boost P2 Gap 7) |
| MT-E4 | Composite across multiple states | Medium | SKIP (N/A -- architectural) |
| MT-E5 | Activity rollback on downstream exception | High | SKIP (W1P4 Gap 3 + AASM P2 Gap 1) |
| MT-E6 | Catch block with side effects | High | SKIP (N/A -- no catch in EM) |
| MT-E7 | Exception catch ordering | Medium | SKIP (N/A -- no catch in EM) |
| MT-E8 | Retry exhaustion propagates | Medium | SKIP (N/A -- queue-level retry) |
| MT-E9 | Self-observing fault | High | SKIP (N/A -- architectural) |
| MT-E10 | Missing instance auto-fault | High | SKIP (W1P4 Gap 28) |
| MT-E11 | Missing instance custom response | Medium | SKIP (EndpointEdgeCasesTest + W1P4 Gap 28) |
| MT-E12 | Catch -> subsequent action continues | Medium | SKIP (N/A -- no catch in EM) |
| MT-E13 | Outbox fault exactly once | High | See MT-E26 below |
| MT-E14 | Instant finalize | Medium | SKIP (W1P2 Gap 6) |
| MT-E15 | DuringAny not in Initial | Medium | SKIP (N/A -- no DuringAny in EM) |
| MT-E16 | Rescue middleware persistence | Medium | SKIP (W1P4 Gap 1 + AASM P2 Gaps 2-3) |
| MT-E17 | Guard-like event filter | High | SKIP (W1P1 Gap 1 + W1P4 Gap 1) |
| MT-E18 | Catch -> Finalize | Medium | SKIP (N/A -- no catch in EM) |
| MT-E19 | Ignored events in NextEvents | Medium | SKIP (XState P2 Gap E12) |
| MT-E20 | Deadlock with slow action + concurrent ops | High | SKIP (W1P3 Gaps 1-2) |
| MT-E21 | Correlation validation | Low | SKIP (N/A -- no correlation in EM) |
| MT-E22 | Catch in IfElse branch | Medium | SKIP (N/A -- no catch in EM) |
| MT-E23 | Outbox guard-like filter | Medium | SKIP (existing guard tests) |
| **MT-E24** | **All regular event guards false -> exception** | **High** | **NOT COVERED** |
| MT-E25 | Retry + Catch combination | Medium | SKIP (W1P4 Gap 20) |
| **MT-E26** | **Event persistence idempotency across retries** | **High** | **NOT COVERED** |

## Actionable Gaps (2 beads)

Gaps below are NOT covered by any existing test or existing bead:

1. **Gap MT-E24** -- All guards false for regular event: exception thrown, machine unchanged (High)
2. **Gap MT-E26** -- Event persistence idempotency: retry after failed transition produces no duplicate events (High)
