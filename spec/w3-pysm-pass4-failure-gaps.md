# W3 python-statemachine Pass 4: Failure / Recovery / Timeout Gaps

> Theme: FAILURE / RECOVERY / TIMEOUT
> Lens: Exception paths, error.execution semantics, error-in-error-handler prevention, validator vs execution error distinction, conditional error routing, timeout composition, delayed event cancellation, recovery after error.
> Generated: 2026-03-25
> Source: python-statemachine test suite — test_error_execution.py, test_statechart_error.py, test_fellowship_quest.py, test_validators.py, test_contrib_timeout.py, test_statechart_delayed.py, test_async.py, test_async_futures.py, test_statemachine_compat.py, test_invoke.py

---

## Dedup Check

Checked against:
- `spec/w1-pass4-failure-gaps.md` (30 gaps — general failure/recovery from problem research)
- `spec/w3-xstate-pass3-async-gaps.md` (XState async gaps — actor lifecycle focus)
- `spec/w1-pass3-async-gaps.md` (async/race gaps — LocalQA focus)
- All existing EventMachine test files in `tests/` (grep'd for each pattern below)
- All previous pass gap files (w1-pass1/2/3, w3-xstate-pass1/2/3, w3-boost-pass1/2, w3-spring-pass1/2, w3-aasm-pass1/2, w3-commons-pass1/2, w3-masstransit-pass1)

---

## Gap PF1: Error-in-error-handler does not cause infinite loop

- **Priority**: High
- **pysm source**: `test_error_execution.py` line 91 (`test_error_in_error_handler_no_infinite_loop`), `test_error_execution.py` line 594 (`test_error_in_on_callback_of_error_handler_is_ignored`), line 336 (`test_error_in_error_handler_no_loop_with_convention`)
- **Type**: Feature test
- **Scenario**: Define a machine where the @fail action itself throws an exception (or an action triggered during error handling throws). Verify: (1) no infinite loop, (2) the second error is either ignored or propagated but never triggers recursive @fail processing, (3) the machine ends in a consistent state.
- **Expected behavior**: EventMachine must prevent recursive error handling. When an action in @fail handling throws, the error is logged/suppressed, not re-entered. This is critical for SCXML compliance (errors during error.execution processing must not recurse).
- **Stub machine needed**: No (inline TestMachine::define with throwing @fail action)
- **Dedup check**: W1P4 Gap 1-4 cover individual action/guard/exit exceptions, but none cover the recursive case of error-during-error-handling. Grepped "error.*in.*error", "recursive.*error", "infinite.*loop.*error" in tests/ — found `MaxTransitionDepthTest.php` (depth limit) and `InfiniteLoopProtectionE2ETest.php` (max depth), but these are for @always loops, NOT for error-in-error-handler recursion. No test exercises this pattern.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap PF2: Recovery after @fail — machine continues processing events

- **Priority**: High
- **pysm source**: `test_error_execution.py` line 447 (`test_recovery_from_error_allows_further_transitions`), `test_fellowship_quest.py` line 445 (`test_recovery_after_wound`)
- **Type**: Feature test
- **Scenario**: Child machine fails, parent handles @fail and transitions to an error-recovery state (NOT a final state). From that state, send another event. Verify: (1) the machine processes the event normally, (2) state transitions work correctly after recovery, (3) context is intact.
- **Expected behavior**: After handling @fail, the machine is in a valid state and can continue processing events. This tests that failure handling doesn't leave the machine in a broken internal state.
- **Stub machine needed**: No (inline TestMachine::define with @fail -> recovery_state -> normal_event -> final)
- **Dedup check**: `MachineDelegationTest.php` tests @fail routing to a final state. `ConditionalOnFailTest.php` tests conditional routing. But NONE test sending further events after @fail recovery (all @fail targets are final states in existing tests). Grepped "after.*fail.*continue", "recovery.*after.*fail", "send.*after.*fail" — no results.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap PF3: Sequential errors — each handled independently by @fail

- **Priority**: Medium
- **pysm source**: `test_error_execution.py` line 492 (`test_multiple_errors_sequential`), `test_fellowship_quest.py` line 731 (`test_error_recovery_then_second_error_handled`)
- **Type**: Feature test
- **Scenario**: Machine delegates to child 1, which fails. @fail routes to intermediate state. Machine then delegates to child 2, which also fails. Second @fail routes to final state. Verify: (1) first @fail fires, (2) second @fail fires from the new state, (3) both error events are recorded in history, (4) each @fail action receives the correct error context.
- **Expected behavior**: Each child failure is handled by the @fail transitions available in the CURRENT state at the time of failure. Sequential failures are independent.
- **Stub machine needed**: Yes (two-step delegation machine with @fail at each step)
- **Dedup check**: No existing test exercises sequential @fail handling (two child delegations where both fail). All existing delegation tests have a single @fail point. Grepped "sequential.*fail", "multiple.*fail.*delegation", "second.*fail" — no results.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap PF4: Conditional @fail routing based on error context (type/message)

- **Priority**: High
- **pysm source**: `test_error_execution.py` line 623 (`test_condition_on_error_transition_routes_to_different_states`), line 656 (`test_condition_inspects_error_type_to_route`), line 679 (`test_condition_inspects_error_message_to_route`), `test_fellowship_quest.py` entire file (character x peril matrix)
- **Type**: Feature test
- **Scenario**: Define a parent machine with multiple guarded @fail branches. Child throws different exception types (e.g., RuntimeException vs ValidationException). Each @fail guard inspects the error event payload to route to different target states. Verify: (1) RuntimeException routes to retry_state, (2) ValidationException routes to rejected_state, (3) the guard receives the error details in the event payload.
- **Expected behavior**: @fail guards can inspect the error payload (error message, error class) to determine routing. First-match-wins semantics apply.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: `ConditionalOnFailTest.php` tests conditional @fail for PARALLEL states but the condition checks context values, NOT the error payload itself. No test verifies that @fail guards can inspect the error message/type from the child's exception. W1P4 Gap 6 covers "all @fail guards false" but not conditional routing by error type. Grepped "fail.*guard.*error.*type", "fail.*condition.*exception", "fail.*route.*error" — no results.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap PF5: ValidationGuardBehavior exception always propagates (NOT routed through @fail)

- **Priority**: High
- **pysm source**: `test_validators.py` line 129 (`test_validator_does_not_trigger_error_transition`), line 26 (`test_validator_rejects_with_catch_errors_as_events_true`)
- **Type**: Feature test
- **Scenario**: Define a machine with a ValidationGuardBehavior on a transition AND a @fail or error-handling transition. The validation guard throws MachineValidationException. Verify: (1) the exception propagates to the caller (not caught by @fail), (2) machine stays in current state, (3) no actions execute, (4) this is different from a regular GuardBehavior throwing (which would be handled differently).
- **Expected behavior**: ValidationGuardBehavior exceptions are transition-selection rejections, not execution errors. They ALWAYS propagate to the caller regardless of @fail configuration. This matches pysm's validator semantics exactly.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: `ParallelValidationGuardTest.php` tests validation guard in parallel context. `MachineControllerTest.php` tests through HTTP (422 response). `ActionsTest.php` has basic validation guard tests. W1P4 Gap 30 covers "ValidationGuard in non-parallel context" which is close but focuses on simple non-parallel context. THIS gap specifically tests that validation exceptions bypass @fail routing. Grepped "validation.*not.*fail", "validation.*bypass.*error", "validation.*propagat.*not.*handled" — no results. Different focus from W1P4 Gap 30: W1P4 Gap 30 tests MachineValidationException is thrown; this tests it's NOT caught by @fail.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap PF6: Validator rejection does not fall through to next transition branch

- **Priority**: Medium
- **pysm source**: `test_validators.py` line 151 (`test_validator_rejection_does_not_fallthrough`), line 68 (`test_first_validator_fails`)
- **Type**: Feature test
- **Scenario**: Define a transition with two branches: first has a ValidationGuardBehavior, second is unguarded. The validation guard throws. Verify: (1) the exception propagates immediately, (2) the second branch is NOT tried (unlike a regular guard returning false which would try the next branch), (3) the machine stays in its current state.
- **Expected behavior**: A ValidationGuardBehavior exception is immediate rejection — the engine does NOT fall through to the next transition branch. This is fundamentally different from a regular guard returning false.
- **Stub machine needed**: No (inline TestMachine::define with multi-branch transition)
- **Dedup check**: `TransitionsTest.php` tests transition fallback. But no test verifies that a VALIDATION guard exception stops the branch chain. This is a semantic difference between GuardBehavior (returns false → try next branch) and ValidationGuardBehavior (throws → propagate immediately). Grepped "validation.*fallthrough", "validation.*stop.*chain", "validation.*next.*branch" — no results.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap PF7: Error in guard during @fail-triggered compound exit fires correctly

- **Priority**: Medium
- **pysm source**: `test_statechart_error.py` line 64 (`test_error_recovery_exits_compound`), `test_error_execution.py` line 281 (`test_error_in_guard_with_convention`)
- **Type**: Feature test
- **Scenario**: Parallel state with region A in a compound delegation state. Region A's child fails. The @fail transition exits the parallel state entirely. Verify: (1) exit actions of the parallel state fire, (2) the machine reaches the @fail target state cleanly, (3) exit of the parallel state removes all region state data, (4) compound exit is complete (no dangling sub-state references).
- **Expected behavior**: @fail handling that exits a compound/parallel state must properly clean up all descendant state data, same as a normal exit.
- **Stub machine needed**: No (inline or existing ParallelDispatchWithFailMachine)
- **Dedup check**: `ParallelDispatchFailBasicTest.php` tests basic parallel fail. But no test verifies that exit actions fire during @fail-triggered parallel exit, or that all region state data is cleaned up. Grepped "fail.*exit.*action", "fail.*cleanup.*region", "fail.*compound.*exit" — no results. Different from W1P4 Gap 11 (which tests ordering of region-A-fail while region-B-done); this tests the exit cleanup correctness.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap PF8: @fail action can modify context (error handler sets attributes)

- **Priority**: Medium
- **pysm source**: `test_error_execution.py` line 701 (`test_error_handler_can_set_machine_attributes`), line 258 (`test_error_data_passed_to_handler`)
- **Type**: Feature test
- **Scenario**: Parent delegates to child. Child fails. Parent's @fail action stores error metadata in context (error_message, error_code, retry_count). Verify: (1) the @fail action executes and modifies context, (2) the updated context is persisted, (3) subsequent transitions can read the error context values, (4) the context changes appear in the machine event history.
- **Expected behavior**: @fail actions are normal actions that can read/write context. Error details from the event payload can be persisted into context for later use.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: W1P4 Gap 18 covers "@fail action accesses error details" which is similar but focuses on reading the payload. This gap focuses on WRITING to context during @fail and verifying persistence. `MachineDelegationTest.php` line 130 tests "@fail routes and captures error message" — this partially covers reading. But no test verifies that @fail action context writes are persisted and available in subsequent state. Grepped "fail.*action.*context.*write", "fail.*modify.*context", "fail.*persist.*error" — no results.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap PF9: Timeout cancelled on early state exit (timer/delayed event semantics)

- **Priority**: Medium
- **pysm source**: `test_contrib_timeout.py` line 49 (`test_timeout_cancelled_on_early_exit`), `test_statechart_delayed.py` line 48 (`test_cancel_delayed_event`)
- **Type**: Feature test
- **Scenario**: Machine enters state with `after` timer (say 60s). Before timer fires, send an event that transitions machine to another state. Verify: (1) the timer does NOT fire, (2) no stale `machine_timer_fires` row remains active, (3) advancing time past the original timer interval has no effect.
- **Expected behavior**: When a state is exited, all timers associated with that state are effectively cancelled.
- **Stub machine needed**: No (inline with timer helpers)
- **Dedup check**: W1P4 Gap 26 covers "timer fire after state exit — stale skipped" which is the exact same scenario. **DUPLICATE of W1P4 Gap 26.** SKIP.

## Gap PF10: Timeout composition — first-to-complete wins (invoke vs timeout race)

- **Priority**: Medium
- **pysm source**: `test_contrib_timeout.py` line 96 (`test_invoke_completes_before_timeout`), line 115 (`test_timeout_fires_before_slow_invoke`)
- **Type**: Feature test
- **Scenario**: Parent delegates to a child machine AND has a @timeout configured. Test two sub-scenarios: (a) child completes before timeout — verify @done fires and @timeout is cancelled; (b) timeout fires before child completes — verify @timeout fires and child completion is ignored. Use `simulateChildDone` and `simulateChildTimeout` for deterministic unit tests.
- **Expected behavior**: The first event to arrive (child completion or timeout) determines the transition. The other is silently ignored because the machine has already left the delegating state.
- **Stub machine needed**: No (uses existing AsyncTimeoutParentMachine)
- **Dedup check**: W1P4 Gap 9 covers "child completion after parent @timeout — late @done ignored" which is sub-scenario (b). Sub-scenario (a) is tested in `ReviewFixesTest.php` line 324 ("timeout job is no-op when child completes before timeout"). Both sub-scenarios are individually covered. **DUPLICATE.** SKIP.

## Gap PF11: No @fail handler defined — exception propagates to caller

- **Priority**: Medium
- **pysm source**: `test_error_execution.py` line 430 (`test_no_error_handler_defined`), `test_statemachine_compat.py` line 64 (`test_statemachine_exception_propagates`)
- **Type**: Feature test
- **Scenario**: Child machine throws but parent has NO @fail handler defined (neither explicit nor fire-and-forget). Verify: (1) the exception propagates to the caller of Machine::send(), (2) the parent's state is not corrupted, (3) the child's failure is recorded in events.
- **Expected behavior**: Without an @fail handler, child exceptions propagate through to the caller.
- **Stub machine needed**: No (inline TestMachine::define with child delegation but no @fail)
- **Dedup check**: `MachineDelegationTest.php` always defines @fail. `AsyncMachineDelegationTest.php` always defines @fail. No test verifies behavior when @fail is OMITTED from a delegation config. Grepped "no.*fail.*handler", "missing.*fail", "without.*fail.*delegation" — no results. Different from W1P4 Gap 22 (fire-and-forget child failure) because fire-and-forget is intentional (no @done defined), while this is a delegation WITH @done but WITHOUT @fail.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap PF12: Guard exception during @fail evaluation — double fault handling

- **Priority**: Medium
- **pysm source**: `test_error_execution.py` line 764 (`test_all_conditions_false_error_unhandled`), `test_fellowship_quest.py` condition-based error routing patterns
- **Type**: Feature test
- **Scenario**: Child fails. Parent's @fail has a guarded branch where the guard itself throws an exception. Verify: (1) the guard exception does not mask the original child failure, (2) the machine ends in a consistent state, (3) either the exception propagates or falls through to the next @fail branch.
- **Expected behavior**: If a guard on @fail throws, the behavior should be consistent with normal guard exception handling — the guard is treated as false and the next branch is tried, OR the exception propagates.
- **Stub machine needed**: No (inline TestMachine::define)
- **Dedup check**: W1P4 Gap 4 covers "guard throws RuntimeException" for regular transitions but not for @fail transitions specifically. The @fail context adds complexity because there's already an error being handled. Grepped "guard.*throw.*fail", "fail.*guard.*exception" — no results.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap PF13: Error in parallel region — @fail exits parallel cleanly

- **Priority**: High
- **pysm source**: `test_statechart_error.py` line 37 (`test_error_in_parallel_region_isolation`), line 17 (`test_error_in_compound_child_onentry`)
- **Type**: Feature test
- **Scenario**: Parallel state with two regions. Region A's entry action throws a RuntimeException. Verify: (1) @fail fires, (2) the parallel state is exited completely (both regions cleaned up), (3) the machine transitions to the @fail target state, (4) Region B's state data is discarded (not left dangling).
- **Expected behavior**: An exception in one parallel region triggers @fail and exits the entire parallel state, cleaning up all regions.
- **Stub machine needed**: No (uses existing ParallelDispatchWithFailMachine or inline)
- **Dedup check**: `ParallelDispatchFailBasicTest.php` tests basic parallel fail but uses a ThrowRuntimeExceptionAction in an explicit action, not in an entry action. The specific pattern of entry action exception in a parallel region is not tested. Also, no test verifies that Region B's state data is cleaned up after Region A fails in sync mode. Grepped "entry.*action.*parallel.*fail", "region.*entry.*throw", "parallel.*cleanup.*on.*fail" — no results. W1P4 Gap 11 tests "Region A fails while Region B already done" (ordering), not entry-action-throws.
- **Workflow**: Use /agentic-commits. Run composer quality.

## Gap PF14: Async futures — exception routed to correct caller (concurrent sends)

- **Priority**: Medium
- **pysm source**: `test_async_futures.py` line 88 (`test_exception_reaches_caller`), line 195 (`test_concurrent_sends_exception_with_catch_errors_as_events_off`), line 284 (`test_separate_tasks_validator_exception_routing`)
- **Type**: LocalQA
- **Scenario**: Two concurrent SendToMachineJobs for the same machine. The first event's action throws. Verify: (1) the first caller's job fails with the correct exception, (2) the second caller either processes successfully or gets a MachineAlreadyRunningException — NOT the first caller's exception, (3) no exception leaks across callers.
- **Expected behavior**: Exceptions from one event processing must not leak to another caller. Each caller gets only its own exception.
- **Stub machine needed**: No
- **Dedup check**: W1P3 Gap 1 covers "concurrent Machine::send() to same instance" but focuses on lock serialization, NOT on exception isolation between callers. `AsyncEdgeCasesTest.php` has a concurrent-sends test but doesn't test exception isolation. **Different focus.** This is specifically about exception routing correctness under concurrency.
- **Workflow**: Use /agentic-commits. Run composer quality.

---

# Summary

| # | Gap Title | Priority | Dedup Status |
|---|-----------|----------|--------------|
| PF1 | Error-in-error-handler — no infinite loop | High | Not covered |
| PF2 | Recovery after @fail — machine continues | High | Not covered |
| PF3 | Sequential errors — each handled independently | Medium | Not covered |
| PF4 | Conditional @fail routing by error type/message | High | Not covered |
| PF5 | ValidationGuard exception bypasses @fail | High | Not covered |
| PF6 | Validator rejection stops branch chain (no fallthrough) | Medium | Not covered |
| PF7 | @fail exits compound/parallel — cleanup correctness | Medium | Not covered |
| PF8 | @fail action modifies context — persisted | Medium | Partially (W1P4 Gap 18 reads, this writes) |
| PF9 | Timeout cancelled on early exit | Medium | DUPLICATE of W1P4 Gap 26 — SKIP |
| PF10 | Timeout composition — first-to-complete wins | Medium | DUPLICATE — SKIP |
| PF11 | No @fail handler — exception propagates | Medium | Not covered |
| PF12 | Guard exception during @fail — double fault | Medium | Not covered |
| PF13 | Error in parallel region entry — clean exit | High | Not covered |
| PF14 | Concurrent sends — exception isolation | Medium | Not covered (LocalQA) |

## Actionable Gaps (12 beads)

Excluding PF9 (duplicate) and PF10 (duplicate):

1. **PF1** — Error-in-error-handler no infinite loop (High)
2. **PF2** — Recovery after @fail continues (High)
3. **PF3** — Sequential errors handled independently (Medium)
4. **PF4** — Conditional @fail routing by error context (High)
5. **PF5** — ValidationGuard bypasses @fail (High)
6. **PF6** — Validator no fallthrough (Medium)
7. **PF7** — @fail exits compound/parallel cleanup (Medium)
8. **PF8** — @fail action writes context persisted (Medium)
9. **PF11** — No @fail handler propagates exception (Medium)
10. **PF12** — Guard exception during @fail double fault (Medium)
11. **PF13** — Parallel region entry error clean exit (High)
12. **PF14** — Concurrent sends exception isolation (Medium, LocalQA)
