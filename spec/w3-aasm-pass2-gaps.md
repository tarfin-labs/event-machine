# W3 AASM Pass 2: Persistence Adapter Gaps

> Theme: PERSISTENCE ADAPTERS — validation failure rollback, pessimistic locking, nested transactions, after_commit, skip-validation
> Source: AASM `spec/unit/persistence/active_record_persistence_spec.rb`, `active_record_persistence_multiple_spec.rb`, `callbacks_spec.rb`, `spec/models/active_record/transactor.rb`, `validator.rb`, `instance_level_skip_validation_example.rb`
> Generated: 2026-03-25

---

## Dedup Baseline

Before identifying gaps, I checked:

### Existing gap files reviewed:
- `spec/w3-aasm-pass1-gaps.md` (8 gaps: guard fallthrough, AND semantics, guard failure stops lifecycle, guard retry isolation, guard injection, nil payload, memory leak, callback ordering)
- `spec/w1-pass1-happy-path-gaps.md` (14 gaps: first-match guard, guard purity, action ordering, etc.)
- `spec/w1-pass2-edge-case-gaps.md` (edge case gaps -- no transaction/persistence overlap)
- `spec/w3-spring-pass1-gaps.md` (8 gaps: persist/restore patterns -- Spring SM focus, not AASM transaction patterns)
- `spec/w3-boost-pass1-gaps.md`, `spec/w3-xstate-pass1-happy-gaps.md`, `spec/w3-commons-pass1-gaps.md`

### Existing EventMachine test files checked for coverage:
- `TransitionsTest.php` -- Tests `isTransactional` flag: transactional event rolls back DB, non-transactional does not. Also tests `MachineAlreadyRunningException` under lock.
- `ParallelDispatchTransactionSafetyTest.php` -- Tests that `ParallelRegionJob` critical section runs inside a DB transaction (transaction level >= 2).
- `ParallelValidationGuardTest.php` -- Tests that `MachineValidationException` is thrown when validation guard fails, state stays unchanged, history records guard fail events.
- `ActionsTest.php` -- Tests `MachineValidationException` thrown and history persisted.
- `MachineControllerTest.php` -- Tests 422 response for validation guard failure.
- `MachineLockingTest.php` (LocalQA) -- Tests lock release after async completion, no deadlocks with 5 parallel delegations.
- `ParallelDispatchLockModesTest.php` -- Tests lock configuration options.
- `ParallelDispatchLockContTest.php` -- Tests lock contention scenarios.
- `ParallelDispatchLockInfraTest.php` -- Tests lock infrastructure.
- `EventResolutionTest.php` -- Tests `isTransactional` preservation through re-instantiation.
- `HistoryValidationTest.php` -- Tests that `MachineValidationException` is thrown for invalid event payloads.

### Grepped patterns:
- `validation.*fail.*rollback`, `transaction.*nested`, `after_commit`, `skip.validation`, `context.*unchanged.*validation`, `context.*revert`, `context.*rollback`, `validation.*guard.*context` -- **no matches** in test files for most of these patterns.

---

## Gap 1: Validation guard failure preserves pre-transition context (no context mutation)

- **Priority**: High
- **Source**: AASM `active_record_persistence_spec.rb` lines 427-449 ("should not store states for invalid models"). AASM validates that after a failed validation, the model state reverts to the original both in memory AND after reload.
- **Type**: Feature test
- **AASM Pattern**: Model is valid and sleeping. Set name to nil (invalid). Call `run!` -> raises `RecordInvalid`. Assert still sleeping. Reload from DB. Assert still sleeping (not running).
- **EventMachine Equivalent**: Machine has a calculator that mutates context, followed by a `ValidationGuardBehavior` that rejects. The question is: does the context mutation from the calculator persist in history or is it rolled back?
- **What's tested**: `ParallelValidationGuardTest.php` tests that state stays unchanged when validation guard fails. But NO test verifies that **context changes made by calculators/actions before the guard** are properly handled. The `handleValidationGuards()` method in `Machine.php` runs AFTER `transition()` and AFTER `persist()` -- meaning history events including context mutations are already persisted when the validation exception is thrown.
- **Scenario**: Define a machine with state A -> B. The transition has: (1) a calculator that sets `context.computed_value = 42`, (2) a `ValidationGuardBehavior` that always fails. Send event. Catch `MachineValidationException`. Verify: (a) state remains A, (b) check what happened to `computed_value` in persisted history -- document whether the calculator's context mutation is visible in history or rolled back.
- **Why this matters**: In AASM, failed validation means state is NOT persisted. In EventMachine, the guard fail event IS persisted (by design for audit), but the question is whether intermediate context mutations from the same transition are also persisted. This is EventMachine's intentional behavior but it needs an explicit test documenting it.
- **Dedup check**: Grepped `context.*validation.*guard`, `calculator.*validation.*fail`, `context.*after.*guard.*fail` -- no matches. `ParallelValidationGuardTest` only checks state value, not context.

## Gap 2: Transactional event rolls back ALL persisted events (including history)

- **Priority**: High
- **Source**: AASM `active_record_persistence_spec.rb` lines 543-554 ("transactions: should rollback all changes"). Worker's status rollback proves transaction atomicity.
- **Type**: Feature test
- **AASM Pattern**: Transactor enters running state (persisted), triggers worker update (persisted), then fails in after_enter callback. Transaction wraps everything -> worker's update is rolled back.
- **EventMachine Equivalent**: A transactional event (`isTransactional: true`) causes a transition with an entry action that throws. The existing test (`TransitionsTest.php` line 107) verifies external DB writes (ModelA) are rolled back. But it does NOT verify that the **machine_events table** entries are also rolled back.
- **Scenario**: Create a machine. Send a transactional event that triggers a transition with an entry action that (a) writes to `machine_events` via transition/entry, then (b) throws an exception. Verify: the `machine_events` table has NO new events from this failed transition. The machine's state in the DB should be unchanged.
- **Why this matters**: The existing test checks external model rollback but not internal EventMachine event persistence rollback.
- **Dedup check**: `TransitionsTest.php` tests ModelA rollback. No test checks `machine_events` count before/after a failed transactional event.

## Gap 3: Non-transactional event persists partial state despite action failure

- **Priority**: High
- **Source**: AASM `active_record_persistence_spec.rb` lines 529-541 ("without transactions: should not rollback all changes"). Worker's status is NOT rolled back when no transaction.
- **Type**: Feature test
- **AASM Pattern**: NoTransactor (use_transactions: false) enters running, updates worker, then fails. Worker's status remains changed (running).
- **EventMachine Equivalent**: A non-transactional event causes a transition where an entry action throws. Existing test (`TransitionsTest.php` line 94) checks that external ModelA data persists. But no test verifies that the `machine_events` table entries from the partial transition are preserved (not rolled back).
- **Scenario**: Create a machine. Send a non-transactional event. Entry action writes context and then throws. Verify: machine_events table contains the transition events (state change events are persisted even though the action threw).
- **Dedup check**: `TransitionsTest.php` line 94 checks ModelA persistence but not machine_events persistence for the partial transition.

## Gap 4: Nested transaction rollback -- action failure inside user transaction

- **Priority**: High
- **Source**: AASM `active_record_persistence_spec.rb` lines 556-583 ("nested transactions"). Two scenarios: (a) `requires_new_transaction = true` (default) -- nested savepoint created, inner exception rolls back only inner transaction, (b) `requires_new_transaction = false` -- inner failure poisons outer transaction.
- **Type**: Feature test
- **EventMachine Equivalent**: User wraps `$machine->send()` inside their own `DB::transaction()`. If the machine's transition action throws, does the user's outer transaction also roll back? EventMachine uses `DB::transaction()` for transactional events which creates a savepoint inside the outer transaction.
- **Scenario**: (a) Wrap `$machine->send(transactional event)` inside `DB::transaction()`. Action throws. Verify: outer transaction is still usable (inner savepoint rolled back, not outer). (b) Wrap `$machine->send(non-transactional event)` inside `DB::transaction()`. Action throws. Verify: behavior depends on whether exception propagates.
- **Why this matters**: Real applications often wrap machine operations in larger business transactions. The interaction between EventMachine's internal transaction and the user's outer transaction must be well-defined and tested.
- **Dedup check**: No test wraps `$machine->send()` inside a user-level `DB::transaction()`. `ParallelDispatchTransactionSafetyTest.php` tests transaction LEVEL but not nested transaction rollback semantics.

## Gap 5: ValidationGuardBehavior failure does NOT fire after_commit equivalent

- **Priority**: Medium
- **Source**: AASM `active_record_persistence_spec.rb` lines 605-611 ("should not fire :after_commit if validation failed when saving object"). Also lines 599-602 ("should not fire :after_commit if transaction failed").
- **Type**: Feature test
- **AASM Pattern**: Validator has `after_commit` callback that changes name. When validation fails (invalid record), the callback does NOT fire.
- **EventMachine Equivalent**: EventMachine's `listen` system supports `queue: true` listeners dispatched via `ListenerJob`. When a `ValidationGuardBehavior` fails and `MachineValidationException` is thrown, any listeners registered on the transition or entry should NOT have been dispatched. Since `handleValidationGuards()` runs after `persist()`, the question is whether queued listeners were already dispatched.
- **Scenario**: Define a machine with a `listen.entry` listener on the target state (queued). Add a `ValidationGuardBehavior` on the transition that always fails. Send event. Catch `MachineValidationException`. Verify: the queued listener job was NOT dispatched to the queue. (If it was, document this as a known behavior difference from AASM's after_commit pattern.)
- **Dedup check**: No test checks listener dispatch behavior when validation guard fails. `ParallelValidationGuardTest.php` checks state and history only.

## Gap 6: Pessimistic lock prevents concurrent state mutation (basic)

- **Priority**: Medium
- **Source**: AASM `active_record_persistence_spec.rb` lines 496-527 ("pessimistic locking" -- no lock, default lock, FOR UPDATE NOWAIT).
- **Type**: Feature test
- **AASM Pattern**: Three lock modes tested: no lock (no `lock!` called), default lock (`lock!(true)`), custom lock (`lock!('FOR UPDATE NOWAIT')`).
- **EventMachine Equivalent**: EventMachine's `MachineLockManager` acquires a lock before `send()` when `parallel_dispatch.enabled = true`. The existing `MachineLockingTest.php` (LocalQA) tests lock release and no-deadlock but requires real MySQL+Redis. No **unit-level** test verifies that `MachineLockManager::acquire()` is called during `send()` and `release()` happens in `finally`.
- **Scenario**: (a) With parallel_dispatch enabled, mock `MachineLockManager` to verify `acquire()` is called with the correct rootEventId and `release()` is called even when transition throws. (b) Verify `MachineAlreadyRunningException` is thrown when lock is already held (existing test covers this). (c) Verify lock is released on success.
- **What's already tested**: `TransitionsTest.php` line 121 tests `MachineAlreadyRunningException`. `MachineLockingTest.php` (LocalQA) tests real lock lifecycle. Gap is: no unit test verifying acquire/release pattern in the normal success path.
- **Dedup check**: Grepped `acquire.*release`, `lock.*finally`, `lock.*success.*path` -- no matches in unit/feature tests. Only LocalQA tests real locking.

## Gap 7: after_commit callback only fires when DB transaction commits (not on rollback)

- **Priority**: Medium
- **Source**: AASM `active_record_persistence_spec.rb` lines 585-651 ("after_commit callback"). Five scenarios: (a) fires on success, (b) does not fire on exception, (c) does not fire on validation failure, (d) does not fire when not persisting, (e) nested transaction: fires only when ROOT transaction commits.
- **Type**: Feature test
- **EventMachine Equivalent**: EventMachine's `MachineDefinition.php` uses `DB::afterCommit()` for specific post-persist operations. The `listen` system with `queue: true` dispatches jobs inside the transaction. The question is whether EventMachine has any after_commit-like behavior that should be tested.
- **Scenario**: Create a machine with `shouldPersist = true`. Wrap `$machine->send()` in `DB::transaction()` that raises `ActiveRecord::Rollback` equivalent (`DB::rollBack()`). Verify that no `machine_events` are persisted and no queued listener jobs were dispatched.
- **Dedup check**: No test wraps machine send in a transaction that is manually rolled back.

## Gap 8: Skip-validation equivalent -- force transition despite guard failure

- **Priority**: Low
- **Source**: AASM `active_record_persistence_spec.rb` lines 835-855 ("instance_level skip validation with _without_validation method"). InvalidPersistor (`skip_validation_on_save: true`) lines 475-494.
- **Type**: Feature test
- **AASM Pattern**: Two approaches: (a) class-level `skip_validation_on_save: true` config -- persists state even when model is invalid, (b) instance-level `complete_without_validation!` -- bypasses validation for a single call.
- **EventMachine Equivalent**: EventMachine does NOT have a skip-validation mechanism for `ValidationGuardBehavior`. If a validation guard fails, the exception is always thrown. The only "skip" pattern is `fakingAllGuards()` in tests.
- **Why this is a gap**: This is NOT a missing feature test -- it's a documentation gap. EventMachine intentionally has no skip-validation because it follows SCXML semantics where guards are absolute. However, a test that explicitly verifies "there is no way to bypass ValidationGuardBehavior at runtime" would be valuable as a regression test.
- **Scenario**: Define a machine with a `ValidationGuardBehavior` that always fails. Attempt every possible way to send an event (send(), transition(), etc.). Verify `MachineValidationException` is always thrown. This documents the intentional design decision.
- **Dedup check**: `ParallelValidationGuardTest.php` tests that the exception IS thrown, but doesn't test that there's no bypass mechanism.

---

# Summary

| # | Gap Title | Priority | AASM Source | EventMachine Coverage |
|---|-----------|----------|-------------|----------------------|
| 1 | Validation guard failure preserves pre-transition context | High | persistence_spec L427-449 | State checked; context NOT checked |
| 2 | Transactional event rolls back ALL persisted events | High | persistence_spec L543-554 | External DB checked; machine_events NOT checked |
| 3 | Non-transactional event persists partial state | High | persistence_spec L529-541 | External DB checked; machine_events NOT checked |
| 4 | Nested transaction rollback (user wraps machine send) | High | persistence_spec L556-583 | Not tested at all |
| 5 | Validation guard failure does not dispatch queued listeners | Medium | persistence_spec L605-611 | Not tested at all |
| 6 | Pessimistic lock acquire/release in normal success path | Medium | persistence_spec L496-527 | Only LocalQA (real infra); no unit test |
| 7 | Manual transaction rollback prevents event persistence | Medium | persistence_spec L585-651 | Not tested at all |
| 8 | No runtime bypass for ValidationGuardBehavior (design doc test) | Low | persistence_spec L835-855 | Partial (exception IS thrown; no "no bypass" test) |

## Skipped (already covered or not applicable)

| AASM Pattern | Why Skipped |
|--------------|-------------|
| Enum column support | AASM-specific ORM pattern, N/A for EventMachine |
| Named scopes for states | AASM-specific ORM pattern, N/A for EventMachine |
| Direct state assignment prevention | EventMachine uses event-driven mutations only (no direct assignment by design) |
| Subclass state/event inheritance | EventMachine uses composition (machine definitions), not inheritance |
| Conditional initial states (Proc-based) | EventMachine uses static config initial states |
| Silent persistence (`whiny_persistence: false`) | EventMachine always throws exceptions on failure |
| Invalid state column values | EventMachine validates states at definition time |
| Timestamp on state entry | Not an EventMachine feature (uses machine_events timestamps instead) |
| Multiple machines on one class | Not applicable (EventMachine = separate machine classes) |
| before/after_transaction callbacks | AASM-specific lifecycle, EventMachine uses listen system instead |
| before/after_all_transactions callbacks | AASM-specific lifecycle, N/A |

## Actionable Gaps (8 beads)

1. **Gap 1** -- Validation guard failure context preservation (High)
2. **Gap 2** -- Transactional event machine_events rollback (High)
3. **Gap 3** -- Non-transactional event partial persistence (High)
4. **Gap 4** -- Nested transaction rollback with user transaction (High)
5. **Gap 5** -- Validation guard failure listener dispatch prevention (Medium)
6. **Gap 6** -- Lock acquire/release unit test for success path (Medium)
7. **Gap 7** -- Manual DB rollback prevents machine event persistence (Medium)
8. **Gap 8** -- No runtime bypass for ValidationGuardBehavior (Low)
