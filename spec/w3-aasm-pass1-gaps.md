# W3 AASM Pass 1: Guard, Memory Leak, Callback Gaps

> Theme: HAPPY PATH / SEMANTIC CORRECTNESS (Pass 1)
> Source: AASM guard specs (6 files), memory_leak_spec, edge_cases_spec, callbacks_spec, multiple_transitions_that_differ_only_by_guard_spec, transition_spec
> Generated: 2026-03-25

---

## Dedup Baseline

Before identifying gaps, I checked:
- **W1 Pass 1 gaps** (`spec/w1-pass1-happy-path-gaps.md`): 14 gaps opened, including Gap 1 (first-match guard), Gap 2 (guard purity)
- **Existing guard test files**: `GuardPriorityTest.php`, `GuardPurityTest.php`, `CalculatorsWithGuardedTransitions.php`, `ParallelValidationGuardTest.php`, `ConditionalOnDoneTest.php`, `ConditionalOnFailTest.php`, `ActionsTest.php`
- **Existing action ordering tests**: `SelfTransitionTest.php`, `InitialAlwaysChainTest.php`, `AlwaysEventPreservationTest.php`
- **Grepped patterns**: `guard.*fallthrough`, `guard.*fail.*callback`, `multiple.*guard`, `memory.*leak`, `callback.*order`, `guard.*failure.*isol`, `guard.*param`, `nil.*guard`

### Already covered by W1P1 or existing tests:
- **First-match guard wins** (W1P1 Gap 1 = `GuardPriorityTest.php`) -- SKIP
- **Guard purity / no context mutation on fail** (W1P1 Gap 2 = `GuardPurityTest.php`) -- SKIP
- **Guard fallthrough to unguarded branch** (`CalculatorsWithGuardedTransitions.php` test 2: guard fails, falls to state_c) -- SKIP
- **Calculator-before-guard ordering** (`CalculatorsWithGuardedTransitions.php` test 5, `CalculatorTest.php`) -- SKIP
- **Action ordering exit/transition/entry** (W1P1 Gap 3) -- already opened as bead -- SKIP
- **ValidationGuardBehavior rejection** (`ParallelValidationGuardTest.php`, `MachineControllerTest.php`) -- SKIP

---

## Gap 1: Guard fallthrough executes second transition's actions (not first's)

- **Priority**: High
- **Source**: AASM `multiple_transitions_that_differ_only_by_guard_spec.rb` + `guardian_without_from_specified.rb`
- **Type**: Feature test
- **Scenario**: Define two transitions on the same event from the same state, each with a different target. First guard fails, second guard passes. Both have after-actions. Verify: (a) machine takes second transition's target, (b) second transition's action executes, (c) first transition's action does NOT execute.
- **Why not covered**: `GuardPurityTest.php` tests context purity but not that the winning transition's action specifically runs while the losing one doesn't. `CalculatorsWithGuardedTransitions.php` test 2 covers fallthrough to an unguarded branch, NOT to a second guarded branch with its own action. The AASM test is specifically about two guarded transitions to the same target where only the second's callbacks fire.
- **Dedup check**: Grepped `guard.*fallthrough.*callback`, `second.*transition.*callback`, `winning.*transition.*action` in tests/ -- only found `GuardPurityTest.php` which tests context (not action execution tracking). `CalculatorsWithGuardedTransitions.php` test 2 has no action on the fallback branch.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 2: Multiple guards on a single transition (AND semantics)

- **Priority**: High
- **Source**: AASM `guardian.rb` lines 25-29 (`:guards => [:succeed, :fail]`), `guard_spec.rb` lines 16-29
- **Type**: Feature test
- **Scenario**: Define a transition with an array of two guards. Both must return true for the transition to fire. Test: (a) both succeed -> transitions, (b) first succeeds + second fails -> stays, (c) first fails + second succeeds -> stays.
- **Why not covered**: `TransitionDefinitionTest.php` tests that multiple guards are *parsed* into the definition. No runtime test verifies AND semantics (all guards must pass). `GuardPriorityTest.php` tests multiple *transitions* with guards, not multiple guards on a *single* transition.
- **Dedup check**: Grepped `multiple.*guards.*on.*same.*transition`, `guards.*array.*same.*transition`, `all.*guards.*pass` in tests/ -- no matches. `TransitionDefinitionTest.php` line 179 only tests definition parsing, not runtime AND evaluation.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 3: Guard failure stops all subsequent lifecycle (exit/entry/actions)

- **Priority**: High
- **Source**: AASM `callbacks_spec.rb` lines 163-190 (event guard fails -> no state callbacks), lines 203-234 (transition guard fails -> no state callbacks)
- **Type**: Feature test
- **Scenario**: Define a machine with exit, entry, and transition actions. Add a guard that returns false. Send the event. Verify: (a) machine stays in original state, (b) NO exit action fires, (c) NO entry action fires, (d) NO transition action fires. Test both: guard as only transition, and guard on first of two transitions (where second has no guard -- verify second transition fires instead).
- **Why not covered**: Existing tests verify state doesn't change or verify context purity, but no test explicitly asserts that exit/entry/transition actions are NOT called when a guard fails. The AASM callback_spec is very explicit: "does not run any state callback if the event guard fails" and "does not run any state callback if the transition guard fails".
- **Dedup check**: Grepped `guard.*stops`, `guard.*prevents`, `guard.*blocks.*action`, `no.*action.*guard.*fail` in tests/ -- no matches. `GuardPurityTest` checks context, not action non-execution.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 4: Guard failure isolation -- retry succeeds without stale state

- **Priority**: Medium
- **Source**: AASM `callbacks_spec.rb` lines 236-259 ("does not propagate failures to next attempt")
- **Type**: Feature test
- **Scenario**: Define a machine with a guard that uses context to decide pass/fail. First attempt: guard reads context value and fails. Update context. Second attempt: guard reads updated context and passes. Verify the second attempt succeeds cleanly with no stale failure state from the first attempt.
- **Why not covered**: No test in EventMachine sends the same event twice to the same machine where the first fails and the second succeeds based on context changes. The AASM test explicitly verifies "does not propagate failures to next attempt of same transition."
- **Dedup check**: Grepped `guard.*failure.*isol`, `failure.*not.*propagat`, `guard.*retry`, `stale.*guard` in tests/ -- no matches. `ParallelValidationGuardQATest` has a retry test but for ValidationGuard in parallel context, not basic GuardBehavior isolation.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 5: Guard receives injected parameters (ContextManager, EventBehavior, State)

- **Priority**: Medium
- **Source**: AASM `guard_spec.rb` lines 31-41 (guard with params via Proc/lambda), `guard_with_params_spec.rb`
- **Type**: Feature test
- **Scenario**: Define a guard that accepts `ContextManager`, `EventBehavior`, and `State` via parameter injection. Verify the guard receives all three correctly and can use them to make decisions. The event payload should influence the guard decision.
- **Why not covered**: `InvokableBehaviorArgumentsTest.php` tests parameter injection for actions. `IsolatedTestingTest.php` line 80 tests guard with state injection, but it's a unit-level isolated invocation, not a full machine send() with event payload flowing through. No full integration test verifies a guard receiving event payload, context, and state via injection during a real transition.
- **Dedup check**: Grepped `guard.*param`, `parameter.*injection.*guard`, `guard.*inject` in tests/ -- found `ExportXStateCommandTest` (parsing, not runtime), `TestMachineV2Test` (guards parameter for faking, not injection), `AlwaysGuardMachine` (faking timing). No test for runtime parameter injection into guards during a real `send()`.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 6: Guard with nil/empty event payload does not crash

- **Priority**: Low
- **Source**: AASM `guard_arguments_check_spec.rb` (nil as first argument doesn't raise)
- **Type**: Feature test
- **Scenario**: Define a guard that accepts an `EventBehavior` parameter. Send an event with no custom payload (bare `['type' => 'GO']`). Verify the guard is called without crashing -- the injected EventBehavior should have an empty/default payload.
- **Why not covered**: EventMachine's parameter injection always provides properly constructed objects, but there's no explicit test that a guard with event injection works when the event has no payload data beyond the type. Edge case for developer safety.
- **Dedup check**: Grepped `nil.*guard`, `empty.*payload.*guard`, `bare.*event.*guard` in tests/ -- no matches.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 7: MachineDefinition caching does not leak memory on redefinition

- **Priority**: Low
- **Source**: AASM `memory_leak_spec.rb` (commented out but instructive pattern)
- **Type**: Feature test
- **Scenario**: Create multiple `MachineDefinition::define()` calls with the same machine ID. Verify that: (a) the definition is correctly replaced/cached, (b) no duplicate StateDefinition/TransitionDefinition objects accumulate. Use PHP's reflection or object counting to verify.
- **Why not covered**: AASM's memory leak spec was entirely commented out (never ran). EventMachine has no equivalent test. The `MachineDiscovery` cache and `MachineDefinition::define()` are called repeatedly in tests but no test explicitly checks for leaks.
- **Dedup check**: Grepped `memory.*leak`, `ObjectSpace`, `definition.*cache.*leak` in tests/ -- no matches.
- **Note**: This is low priority because PHP's request-based lifecycle naturally prevents long-lived leaks, unlike Ruby's persistent process. However, for queue workers and Octane, this could matter. Mark as low priority.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

## Gap 8: Callback ordering witness -- guard runs before actions/exit/entry (comprehensive)

- **Priority**: High
- **Source**: AASM `callbacks_spec.rb` lines 65-93 (full ordered callback chain)
- **Type**: Feature test
- **Scenario**: Define a machine where every lifecycle point appends to a shared log array: guard evaluation, exit action, transition action, entry action. Run a transition. Verify the log shows the exact SCXML-compliant order: guard -> exit -> transition actions -> entry. This is different from W1P1 Gap 3 which tests only exit/transition/entry -- this gap adds the guard's position in the ordering.
- **Why not covered**: W1P1 Gap 3 covers exit-transition-entry ordering but does NOT include guard evaluation timing in the sequence. No test verifies that guard evaluates BEFORE any exit/entry/transition actions.
- **Dedup check**: This is complementary to W1P1 Gap 3, not a duplicate. W1P1 Gap 3 focuses on action ordering; this gap adds guard timing into the witness log.
- **Workflow**: Use /agentic-commits for commits. Run composer quality before completing.

---

# Summary

| # | Gap Title | Priority | AASM Source | Covered? |
|---|-----------|----------|-------------|----------|
| 1 | Guard fallthrough executes second transition's actions | High | `multiple_transitions_that_differ_only_by_guard_spec.rb` | Not covered |
| 2 | Multiple guards on single transition (AND semantics) | High | `guard_spec.rb` lines 16-29 | Not covered (only definition parsing) |
| 3 | Guard failure stops all lifecycle (exit/entry/actions) | High | `callbacks_spec.rb` lines 163-234 | Not covered |
| 4 | Guard failure isolation -- retry succeeds | Medium | `callbacks_spec.rb` lines 236-259 | Not covered |
| 5 | Guard receives injected parameters during send() | Medium | `guard_spec.rb` lines 31-41 | Not covered (only isolated unit) |
| 6 | Guard with nil/empty event payload | Low | `guard_arguments_check_spec.rb` | Not covered |
| 7 | MachineDefinition caching memory leak | Low | `memory_leak_spec.rb` | Not covered (low priority, PHP lifecycle) |
| 8 | Callback ordering witness including guard timing | High | `callbacks_spec.rb` lines 65-93 | Not covered (W1P1 Gap 3 is partial) |

## Skipped (already covered or opened by W1P1)

| Pattern | Why Skipped |
|---------|-------------|
| First-match guard wins | W1P1 Gap 1 = `GuardPriorityTest.php` |
| Guard purity / no context mutation | W1P1 Gap 2 = `GuardPurityTest.php` |
| Guard fallthrough to unguarded branch | `CalculatorsWithGuardedTransitions.php` test 2 |
| Calculator-before-guard ordering | `CalculatorTest.php`, `CalculatorsWithGuardedTransitions.php` |
| Exit/entry/transition action ordering | W1P1 Gap 3 (already opened) |
| ValidationGuardBehavior rejection | `ParallelValidationGuardTest.php` |
| AASM "multiple machines on one class" | Not applicable (EventMachine has separate machine classes) |
| AASM "guard_multiple_spec" patterns | Duplicate of single-machine guard patterns (AASM-specific multi-machine) |
| AASM "guard_with_params_multiple_spec" | Duplicate (AASM multi-machine pattern, N/A) |
| AASM edge_cases_spec | Not applicable (tests AASM-specific multi-machine API) |

## Actionable Gaps (8 beads)

1. **Gap 1** -- Guard fallthrough with action execution verification (High)
2. **Gap 2** -- Multiple guards AND semantics on single transition (High)
3. **Gap 3** -- Guard failure blocks all lifecycle callbacks (High)
4. **Gap 4** -- Guard failure isolation on retry (Medium)
5. **Gap 5** -- Guard parameter injection integration test (Medium)
6. **Gap 6** -- Guard with bare event payload (Low)
7. **Gap 7** -- MachineDefinition caching memory check (Low)
8. **Gap 8** -- Comprehensive callback ordering witness with guard (High)
