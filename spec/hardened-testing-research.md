# State Machine Problems, Edge Cases, and Testing Challenges

## Comprehensive Research for EventMachine Testing Hardening

Created: 2026-03-25

---

## 1. Basic State Machine Problems

### 1.1 Transition Conflicts and Priority Resolution

**Problem:** When multiple transitions match the same event from the same state (e.g., multiple guarded transitions on the same event type), the system must deterministically choose which one fires. The UML specification intentionally does NOT stipulate any particular order; it puts the burden on the designer to ensure guards are mutually exclusive.

**Concrete Example:**
```
state: awaiting_payment
  on PAYMENT_RECEIVED:
    - target: approved, guard: isAmountSufficient
    - target: partial,  guard: isPartialPayment
```
If a payment is both "sufficient" AND "partial" (e.g., due to overlapping guard logic), which transition fires? The answer depends on evaluation order, which is implementation-defined.

**Why Hard to Test:** Tests typically only exercise one guard at a time. You need combinatorial tests where multiple guards are simultaneously satisfiable.

**How XState/SCXML Handle It:** SCXML uses "document order" -- the first matching transition in source order wins. XState follows the same convention. This is deterministic but fragile: reordering config lines changes behavior silently.

**Testing Approaches:**
- Test every combination of guard truth values for transitions sharing an event type
- Property test: for any event, at most one transition should be selectable (mutually exclusive guards)
- Static analysis: detect guard overlap at definition time
- **EventMachine relevance:** Test that the first guard returning true in array order wins; test that reordering the array changes which transition fires

### 1.2 Guard Evaluation Order and Side Effects

**Problem:** Guards MUST be pure functions with no side effects. If a guard mutates context, logs, makes API calls, or changes external state, the behavior becomes non-deterministic because: (a) multiple guards may be evaluated before one is selected, (b) failed guards still executed their side effects, (c) evaluation order matters if guards depend on shared mutable state.

**Concrete Example:**
```php
// BAD: Guard with side effect
class CheckBalanceGuard extends GuardBehavior {
    public function __invoke(ContextManager $context): bool {
        $balance = $this->apiClient->getBalance(); // Side effect: API call
        $context->set('last_checked_balance', $balance); // Side effect: context mutation
        return $balance >= $context->get('required_amount');
    }
}
```
If this guard is evaluated but a different transition fires, the context has been mutated by a guard that didn't "win."

**Why Hard to Test:** The side effect may be invisible in happy-path tests. You only catch it when a different guard wins the evaluation race.

**Testing Approaches:**
- Verify context is unchanged after guard evaluation when the guard returns false
- Verify no external calls (DB, API) are made during guard evaluation
- Use a spy/mock to verify guards are called but produce no observable side effects
- **EventMachine relevance:** `GuardBehavior` and `ValidationGuardBehavior` get `ContextManager` injected -- test that guards that return false leave context unchanged

### 1.3 Action Execution Order (Entry/Exit/Transition Actions)

**Problem:** SCXML/UML defines a strict ordering: (1) exit actions of source state (bottom-up from deepest child to LCA), (2) transition actions, (3) entry actions of target state (top-down from LCA to deepest child). Violations of this ordering cause bugs where actions see inconsistent state.

**Concrete Example:**
```
State A (exit: releaseResource)
  -> transition to B (action: logTransition)
State B (entry: acquireResource)
```
If entry runs before exit, the resource is double-acquired. If transition action runs before exit, the log may record incorrect "from" state.

**Why Hard to Test:** Most tests verify the final state, not the intermediate ordering. You need action-ordering witnesses (actions that record their execution order).

**Testing Approaches:**
- Create actions that append to a shared log array: `['exit:A', 'transition:A->B', 'entry:B']`
- Verify the log matches the expected SCXML ordering
- Test with nested states (3+ levels deep) where ordering is more complex
- **EventMachine relevance:** Test `processPostEntryTransitions` centralized in `enterState()`, especially the ordering of exit/entry actions in hierarchical transitions

### 1.4 Self-Transitions vs Internal Transitions

**Problem:** A self-transition (external) exits the state and re-enters it, triggering exit and entry actions. An internal transition stays in the same state without triggering exit/entry. Confusing these two semantics leads to: timers not being reset (internal when external was needed), entry actions re-running unexpectedly (external when internal was needed), child states being reset (external self-transition resets to initial child).

**Concrete Example:**
```
State: editing (initial child: draft)
  on SAVE:
    - internal transition: action: saveToDraft    (stays in current child)
    - self-transition to editing: action: saveToDraft (resets child to draft!)
```
A user in `editing.reviewing` who triggers SAVE with an external self-transition gets reset to `editing.draft`, losing their progress.

**Why Hard to Test:** The difference is invisible in flat state machines. It only matters when the state has children or entry/exit actions with side effects.

**XState Bug History:** Issue #1885 -- "External self transitions require target to re-enter the state node." Issue #131 -- "Transition to self wrongly stops activity." Issue #1118 -- "Delayed self transition on parallel state is always treated as internal."

**Testing Approaches:**
- Test self-transition with nested children: verify child state IS reset to initial
- Test internal transition with nested children: verify child state is NOT changed
- Test that entry/exit actions fire (external) or don't fire (internal)
- Test timer reset behavior: external self-transition should cancel and restart timers
- **EventMachine relevance:** Targetless transitions in config. Test with `@always` transitions and compound states.

### 1.5 Unreachable States / Dead States

**Problem:** A state is unreachable if no sequence of events from the initial state can reach it. A dead state (livelock trap) is reachable but has no outgoing transitions for any possible event, trapping the machine forever. Both indicate design errors.

**Concrete Example:**
```
states:
  idle: { on: START -> processing }
  processing: { on: DONE -> completed }
  completed: { type: final }
  orphaned_state: { on: RETRY -> processing }  # Never reachable!
```
`orphaned_state` has transitions defined but cannot be reached from `idle`.

**Why Hard to Test:** Standard tests exercise known paths. Dead/unreachable states are discovered by exhaustive reachability analysis.

**Testing Approaches:**
- Static analysis: walk the state graph from initial, verify all states are reachable
- Property test: generate random event sequences, track which states are visited, assert full coverage
- Detect non-final states with no outgoing transitions (dead states)
- Detect states reachable only from themselves (self-referential islands)
- **EventMachine relevance:** `machine:validate-config` command could detect this. Test the validator catches orphaned states.

### 1.6 Non-Deterministic Transitions

**Problem:** When the same event triggers multiple transitions with overlapping (non-mutually-exclusive) guards, the outcome depends on evaluation order. The UML spec explicitly says this is a design error, but implementations handle it differently (some pick first-match, some throw).

**Concrete Example:**
```
on SUBMIT:
  - target: fast_track, guard: isPremiumCustomer
  - target: fast_track, guard: isHighValue
  - target: standard,   guard: otherwise
```
A premium customer with a high-value order matches both first and second guards. First-match wins in most implementations, but this is fragile.

**Why Hard to Test:** Individual guard tests pass. The non-determinism only appears with specific input combinations.

**Testing Approaches:**
- Enumerate all guard-combination truth tables for shared events
- Property test: for any input, exactly one guard should be true (mutual exclusion property)
- Log which guard was evaluated and selected; assert determinism across runs
- **EventMachine relevance:** Test with multiple guarded transitions on the same event from the same state

### 1.7 Infinite Loops in Eventless (@always) Transitions

**Problem:** An `@always` (eventless) transition fires automatically when its source state is entered. If the target state also has an `@always` transition back, or if a guard depends on context that actions haven't yet modified, the machine enters an infinite loop.

**Concrete Example:**
```
states:
  checking:
    always:
      - target: approved, guard: isApproved
      - target: rejected, guard: isRejected
      - target: checking  # Fallback loops forever if neither guard is true!
```
If `isApproved` and `isRejected` are both false, the machine loops on `checking -> checking` forever.

**XState Documentation:** "Eventless transitions with no target nor guard will cause an infinite loop. Transitions using guard and actions may run into an infinite loop if its guard keeps returning true." The key insight: actions from the transition don't execute until AFTER the guard evaluation loop completes, so guards that depend on context modified by actions will never see the updated value.

**Why Hard to Test:** Happy-path tests where a guard is true terminate normally. The infinite loop only appears when ALL guards are false.

**Testing Approaches:**
- Test @always transitions where ALL guards return false -- verify max-depth protection fires
- Test @always chain: A -> B -> C -> A with guards -- verify detection
- Verify `max_transition_depth` config (default 100) catches infinite loops
- Test that the error message clearly identifies the loop
- **EventMachine relevance:** `MaxTransitionDepthTest.php` exists. Harden with more scenarios: @always with conditional guards, @always chains through multiple states, @always with context-dependent guards.

---

## 2. Hierarchical (Nested) State Problems

### 2.1 Exit/Entry Action Ordering in Nested States

**Problem:** For a transition from `A.B.C` to `D.E.F`, the correct ordering per SCXML is: exit C, exit B, exit A (up to LCA), transition action, enter D, enter E, enter F (down from LCA). Getting this wrong means actions see inconsistent state.

**Concrete Example:**
```
root
├── payment (entry: lockFunds, exit: unlockFunds)
│   └── processing (entry: startJob, exit: cancelJob)
│       └── validating (entry: startValidation)
└── completed (entry: notifyUser)
```
Transition from `payment.processing.validating` to `completed`:
- Correct: exit validating, exit processing (cancelJob), exit payment (unlockFunds), enter completed (notifyUser)
- Wrong (if entry runs before all exits): notifyUser runs while funds are still locked

**Why Hard to Test:** Requires deeply nested states (3+ levels) with actions at every level that record their execution order.

**Testing Approaches:**
- Create a 4-level-deep state hierarchy with logging actions at every entry/exit point
- Verify the execution log matches SCXML ordering exactly
- Test transitions at different depths: sibling, cousin, uncle, root-crossing
- **EventMachine relevance:** `RootEntryExitTest.php` and `RootEntryExitInternalEventsTest.php` exist. Extend to deeper hierarchies and cross-branch transitions.

### 2.2 Transition Scope (LCA - Least Common Ancestor) Calculation

**Problem:** The LCA determines which states are exited and entered during a transition. An incorrect LCA means too many or too few states are exited/entered. Special cases: (a) self-transitions where LCA is the state itself (external) or its parent (internal), (b) transitions between deeply nested cousins, (c) transitions from a child to an ancestor.

**Concrete Example:**
```
root
├── A
│   ├── A1
│   └── A2
└── B
    ├── B1
    └── B2
```
Transition A1 -> A2: LCA = A (only exit A1, enter A2; A's exit/entry NOT called)
Transition A1 -> B1: LCA = root (exit A1, exit A, enter B, enter B1)
Transition A1 -> A:  LCA = root or A? (depends on internal vs external)

**Why Hard to Test:** LCA bugs only manifest with specific topology combinations. Flat machines never exercise LCA.

**Testing Approaches:**
- Test sibling transitions (same parent): verify parent NOT exited/entered
- Test cousin transitions (different parents under same grandparent): verify grandparent NOT exited/entered
- Test child-to-ancestor transitions: verify correct exit sequence
- Test ancestor-to-child transitions (event handled by parent, targets nested child)
- **EventMachine relevance:** `HierarchyTest.php` exists but should be extended with deeper nesting and LCA edge cases

### 2.3 Deep vs Shallow History State Restoration

**Problem:** History pseudo-states remember the previously active substate. Shallow history remembers only the immediate child; deep history remembers the entire nested configuration. Edge cases: (a) history with no prior visit (should go to initial), (b) history with parallel regions (must remember all region states), (c) history after multiple visits (should remember most recent, not first).

**Concrete Example:**
```
editor
├── formatting
│   ├── bold
│   └── italic  <-- was here when we left
└── viewing
```
Shallow history of `editor` remembers `formatting`. Deep history remembers `formatting.italic`.

If the user never visited `editor` before, history should go to the initial state (`formatting.bold`).

**Why Hard to Test:** Requires multi-step scenarios: enter state, leave, return via history, verify restoration.

**Note:** EventMachine does not currently implement history pseudo-states, but this is documented for completeness and future-proofing.

**Testing Approaches (if/when implemented):**
- Test first visit to history (no prior state): should enter initial
- Test shallow history: verify only immediate child is restored
- Test deep history: verify full nested path is restored
- Test history after multiple visits: verify most recent is remembered
- Test history in parallel states: all regions restored correctly

### 2.4 Initial State Selection in Compound States

**Problem:** Every compound (non-atomic, non-parallel) state must have an initial child state. Edge cases: (a) missing initial declaration, (b) initial pointing to a non-existent child, (c) initial pointing to a deeply nested grandchild (SCXML allows this), (d) initial state that itself has an @always transition.

**Concrete Example:**
```
checkout:
  initial: payment_method
  states:
    payment_method:
      always:
        - target: credit_card, guard: hasCreditCard
        - target: bank_transfer  # default
    credit_card: ...
    bank_transfer: ...
```
Entering `checkout` goes to `payment_method`, which immediately transitions via @always. The entry action of `payment_method` fires but it is never a "stable" state.

**Why Hard to Test:** The @always transition fires synchronously during initial state entry, making it hard to observe the intermediate state.

**Testing Approaches:**
- Test that missing `initial` throws a clear config validation error
- Test that entering a compound state correctly selects the initial child
- Test initial state with @always: verify the chain completes in one macrostep
- Test initial state entry actions fire even when @always immediately transitions away
- **EventMachine relevance:** `InitialStateTest.php` exists. Add tests for initial-with-always chains.

### 2.5 Transitions from Parent States vs Child States (Priority)

**Problem:** When an event occurs and both a child state and its ancestor have transitions for that event, which wins? SCXML says: child (most specific/deepest) state has priority. The ancestor's transition is a "fallback" that only fires if no descendant handles the event.

**Concrete Example:**
```
form (on SUBMIT -> review):
  states:
    editing (on SUBMIT -> validating):
      ...
    validating:
      ...
```
If the machine is in `form.editing` and receives SUBMIT, the `editing` state's transition (to `validating`) fires, NOT the `form` state's transition (to `review`). The parent's SUBMIT only fires if the machine is in a child state that doesn't handle SUBMIT (e.g., `form.validating`).

**Why Hard to Test:** Tests often define events at only one level. The priority conflict only appears when the same event is handled at multiple hierarchy levels.

**Testing Approaches:**
- Define same event at parent and child; verify child wins
- From a sibling that doesn't handle the event, verify parent's transition fires as fallback
- Test with guards: child has guarded transition that fails, verify parent's unguarded transition fires
- **EventMachine relevance:** Test `transition()` method with hierarchical event resolution. `EventResolutionTest.php` may cover some of this.

---

## 3. Parallel/Orthogonal State Problems

### 3.1 Race Conditions Between Parallel Regions

**Problem:** When parallel regions run as separate queue jobs (dispatch mode), they execute truly concurrently on different workers. Both regions read the machine state from DB, run their entry actions, compute context diffs, then try to persist. If they interleave incorrectly, one region's changes overwrite the other's.

**Concrete Example:** Region A takes 2 seconds, Region B takes 5 seconds. Both read context at T=0. Region A finishes at T=2, acquires lock, writes context diff. Region B finishes at T=5, acquires lock, but its diff was computed against the T=0 snapshot -- it doesn't see Region A's changes. If both modify the same key, Region A's value is silently lost.

**Why Hard to Test:** Race conditions are timing-dependent. In-process tests (sync mode) never exhibit them because regions run sequentially. Only real multi-worker tests surface the issue.

**Known in XState:** Issue #4895 -- race condition in credit check example with parallel states.

**Testing Approaches:**
- LocalQA tests with real workers and intentional sleep delays
- Verify context after both regions complete: check that both regions' changes survive
- Test shared key writes: verify last-writer-wins semantics are consistent
- Test with array merge: verify both additions survive for different keys
- **EventMachine relevance:** `ParallelDispatchContextConflictTest.php` and `spec/upcoming-parallel-dispatch-deep-edge-cases.md` Scenario 5 cover this. The key gap is testing same-key scalar overwrites.

### 3.2 Synchronization Issues (Join Semantics)

**Problem:** A parallel state completes (@done fires) when ALL regions reach a final state. Edge cases: (a) one region reaches final, the other never does (machine hangs), (b) regions reach final at very different times, (c) a region reaches final then a late event tries to transition it further.

**Concrete Example:** A credit check machine with two parallel regions: `credit_bureau` and `employment_verification`. Credit bureau returns in 2 seconds but employment verification fails silently. The machine hangs in the parallel state forever with no timeout.

**Why Hard to Test:** Requires asymmetric timing and failure injection in one region while the other succeeds.

**Testing Approaches:**
- Test one region final + one region stuck: verify machine does NOT transition to done
- Test `areAllRegionsFinal()` at each step of region completion
- Test with region timeout: verify @timeout fires when a region doesn't complete in time
- Test event sent to already-final region: verify it's ignored/rejected
- **EventMachine relevance:** Scenario 8 in deep edge cases spec (no-raise region). Also `ParallelRegionTimeoutJob` for timeout handling.

### 3.3 Conflicting Transitions Across Regions

**Problem:** If two parallel regions both try to transition the machine out of the parallel state (e.g., both have an @always that targets a state outside the parallel block), only one should win. The other should be suppressed.

**Concrete Example:**
```
parallel_check:
  type: parallel
  regions:
    region_a: ... @fail -> error
    region_b: ... @fail -> error
```
Both regions fail simultaneously. Both try to execute the @fail -> error transition. The transition should only fire once.

**SCXML Handling:** "Two transitions conflict if they both exit some state. In a parallel state, transitions that exit different regions don't conflict. But transitions that exit the parallel state itself DO conflict." The SCXML algorithm resolves this by selecting the first conflicting transition in document order.

**Why Hard to Test:** In sync mode, regions process sequentially so the second region sees the machine has already transitioned. In async mode, both compete for the lock.

**Testing Approaches:**
- Both regions fail: verify machine transitions to error exactly once
- Both regions complete with @done targeting different states: verify only one @done transition fires
- Test with guards on @done: one region's guard passes, other's fails -- verify correct route
- **EventMachine relevance:** Scenario 9 (both-fail) in deep edge cases spec. The double-guard pattern in `ParallelRegionJob::failed()` handles this. Test that `PARALLEL_FAIL` event appears exactly once in history.

### 3.4 Event Broadcasting to Multiple Regions

**Problem:** When an event is sent to a machine in a parallel state, it should be delivered to ALL active regions. Each region independently decides whether to handle it. But: does the event get consumed by the first matching region, or broadcast to all?

**Concrete Example:**
```
parallel_form:
  type: parallel
  regions:
    name_section:
      on VALIDATE -> validating_name
    address_section:
      on VALIDATE -> validating_address
```
Sending VALIDATE should trigger transitions in BOTH regions, not just the first.

**SCXML Semantics:** The event is broadcast to all regions. Each region independently selects a transition. All selected transitions form the "enabled transition set" and execute together (as one microstep).

**Why Hard to Test:** Many test setups only check one region's response to an event.

**Testing Approaches:**
- Send single event, verify ALL regions transition
- Send event that only one region handles: verify only that region transitions, others unchanged
- Send event with guards: different regions have different guard outcomes
- **EventMachine relevance:** `transitionParallelState()` in Machine.php handles this. Test with `Machine::send()` in parallel state.

### 3.5 Done Detection (All Regions Final)

**Problem:** `@done` fires when every region's active atomic state is a final state. Edge cases: (a) regions with nested compound states where the final state is deeply nested, (b) regions where the "final" state has @always transitions (is it really final?), (c) done detection during the same microstep as the last region's transition to final.

**Concrete Example:**
```
parallel:
  region_a:
    states:
      processing:
        states:
          running: -> done
          done: { type: final }  # final is nested inside compound
  region_b:
    states:
      checking: -> complete
      complete: { type: final }
```
`areAllRegionsFinal()` must check that the active atomic state in each region is a final state, not just that a final state exists in the region.

**XState Bug History:** Issue #1341 -- "Issues completing nested Parallel states." Issue #2349 -- "Nested parallel states don't bubble up the done event." The done.state.id event is only generated for the immediate parent of the final state, not grandparents.

**Why Hard to Test:** Requires nested compound states inside regions, which is a complex configuration.

**Testing Approaches:**
- Test @done with nested final states inside regions
- Test @done.{finalState} routing: verify the correct final state ID is reported
- Test @done timing: verify it fires in the same macrostep as the last region completing
- Test @done with @always chain from final: can @always override done detection?
- **EventMachine relevance:** `areAllRegionsFinal()` in MachineDefinition.php. `ConditionalOnDoneTest.php` and `ConditionalOnDoneDispatchTest.php` exist.

### 3.6 Failure Propagation (One Region Fails)

**Problem:** When one region throws an exception, the other region(s) may still be running (in dispatch mode). Questions: (a) should the other regions be cancelled? (b) should their results be discarded? (c) what if the other region completes successfully between the failure and the @fail handling?

**Concrete Example:** Region A fails at T=3s. Region B is still running its 10-second entry action. The `failed()` handler acquires the lock and transitions the machine to `error`. At T=10s, Region B finishes and tries to persist its results -- but the machine is already in `error`.

**Why Hard to Test:** Requires precise failure injection with timing control in concurrent execution.

**Testing Approaches:**
- Region A fails, Region B succeeds later: verify machine is in error, Region B's results discarded
- Region A fails, Region B was already in final: verify machine goes to error (not done)
- Both regions fail: verify only one @fail transition (Scenario 9 in deep edge cases)
- Region fails on retry: verify backoff + retry behavior
- **EventMachine relevance:** `ParallelDispatchWithFailMachine` tests exist. The double-guard in `ParallelRegionJob` handles late-arriving results. Test the guard thoroughly.

### 3.7 Shared Context Mutation Across Regions

**Problem:** Parallel regions share a single context. When regions run as separate jobs, each reads a snapshot, mutates locally, then computes a diff. The diff application strategy determines what survives: scalar values use last-writer-wins (silent data loss), array values use recursive merge (both survive if different keys, last-writer-wins for same nested key).

**Concrete Example:**
- Region A sets `{score: 85, source: 'bureau_a'}`
- Region B sets `{score: 92, source: 'bureau_b'}`
- After merge: `{score: 92, source: 'bureau_b'}` (Region A's score silently lost)

But with proper key separation:
- Region A sets `{results: {bureau_a: {score: 85}}}`
- Region B sets `{results: {bureau_b: {score: 92}}}`
- After merge: `{results: {bureau_a: {score: 85}, bureau_b: {score: 92}}}` (both survive)

**Why Hard to Test:** Requires concurrent execution with timing control to ensure both regions read the same snapshot.

**Testing Approaches:**
- Test scalar conflict: both write same key, verify last-writer-wins
- Test array merge: both write different keys in same array, verify both survive
- Test deep array conflict: both write same nested key, verify last-writer-wins
- Test context diff computation: verify diff is computed against snapshot, not current state
- **EventMachine relevance:** `ParallelDispatchContextConflictTest.php` and `computeContextDiff` in ParallelRegionJob. Scenario 5 in deep edge cases spec.

### 3.8 Ordering of Parallel Region Processing

**Problem:** In sync mode, parallel regions are processed in a defined order (array order). This creates subtle dependencies: if Region A's actions modify context, Region B sees those modifications. In dispatch mode, the order is non-deterministic (depends on worker scheduling). Code that works in sync mode may break in dispatch mode.

**Concrete Example:**
```
Region A entry action: context->set('shared_flag', true)
Region B entry action: if (context->get('shared_flag')) { ... }
```
Sync mode: A runs first, B sees `shared_flag = true`. Works.
Dispatch mode: B may run first, sees `shared_flag = null`. Fails.

**Why Hard to Test:** Sync mode tests always pass. The bug only appears in dispatch mode with specific timing.

**Testing Approaches:**
- Run same parallel config in both sync and dispatch mode; compare results
- In dispatch mode, deliberately make the "first" region slow so the "second" runs first
- Test that regions don't depend on each other's context modifications
- **EventMachine relevance:** This is a design documentation issue. Testing should verify that results are identical regardless of execution order.

### 3.9 Deadlocks in Parallel States

**Problem:** In dispatch mode with locking, deadlocks can occur if: (a) two regions try to acquire each other's locks, (b) a region holds a lock and tries to send an event to the same machine (reentrant lock attempt), (c) nested parallel states create nested lock requirements.

**Concrete Example:** Region A's entry action calls `$this->raise()` which internally tries to process the event, which tries to acquire the machine lock. But Region A is already inside the lock scope (after entry action completes). If the lock is reentrant, this works. If not, deadlock.

**Note:** In EventMachine's design, entry actions run WITHOUT a lock (step 6 in ParallelRegionJob::handle()). The lock is only acquired AFTER the entry action completes. This avoids the most common deadlock scenario. But the raised event processing happens inside the lock scope.

**Why Hard to Test:** Deadlocks are timing-dependent and may manifest as test hangs rather than failures.

**Testing Approaches:**
- Test with lock timeout: verify `MachineLockTimeoutException` is thrown, not a hang
- Test nested parallel states: inner parallel tries to lock while outer holds lock
- Verify lock acquisition timeout is configurable and reasonable
- **EventMachine relevance:** `MachineLockManager` with TTL and cleanup. Lock timeout in config. Test that lock timeout fires correctly.

### 3.10 Memory Consistency in Async Parallel Execution

**Problem:** When parallel regions run as separate PHP processes (queue workers), there is no shared memory. All coordination happens through the database. Edge cases: (a) stale reads from DB replication lag, (b) transaction isolation level affecting visibility, (c) serialized context exceeding DB column limits.

**Concrete Example:** Region A completes and writes to DB. Region B reads from a read replica that hasn't replicated yet. Region B doesn't see Region A's changes and computes an incorrect diff. This is most likely with MySQL master-slave setups.

**Why Hard to Test:** Requires specific infrastructure (read replicas, replication lag injection).

**Testing Approaches:**
- Verify all parallel dispatch DB operations use the write connection (not read replica)
- Test with large context payloads near DB column limits
- Test context serialization round-trip: write then immediately read, verify equality
- **EventMachine relevance:** Check that `MachineEvent` queries in ParallelRegionJob force the write connection.

### 3.11 Region Timeout Handling

**Problem:** A region timeout fires when a region doesn't complete within a configured duration. Edge cases: (a) timeout fires just as the region completes (race), (b) timeout fires but the machine has already transitioned out of the parallel state, (c) multiple regions timeout simultaneously.

**Concrete Example:** Region A has a 30-second timeout. At T=29.9s, Region A's job completes and starts persisting. At T=30s, `ParallelRegionTimeoutJob` fires and tries to transition the machine to the timeout state. Both compete for the lock.

**Why Hard to Test:** Requires precise timing control at sub-second granularity.

**Testing Approaches:**
- Test timeout fires when region doesn't complete: verify @timeout transition
- Test timeout race with completion: verify only one wins (lock ensures this)
- Test timeout when machine already left parallel state: verify timeout is no-op
- Test timeout with region that completed but didn't reach final (stuck at intermediate)
- **EventMachine relevance:** `ParallelRegionTimeoutJob` exists. Test the double-guard pattern in timeout job.

---

## 4. Async/Concurrent Execution Problems

### 4.1 Event Ordering and Queue Management

**Problem:** SCXML defines two queues: internal (for raised events) and external (for sent events). Internal events have priority -- all internal events are processed before the next external event. The macrostep processes one external event completely (including all triggered internal events) before moving to the next.

**Concrete Example:**
```
External queue: [EVENT_A, EVENT_B]
Processing EVENT_A:
  - Entry action raises INTERNAL_1
  - Processing INTERNAL_1: entry action raises INTERNAL_2
  - Processing INTERNAL_2: no more internal events
Now process EVENT_B
```
If EVENT_B is processed before INTERNAL_1, the machine may be in a wrong intermediate state.

**Why Hard to Test:** In synchronous execution, the ordering is implicit. In async execution with queues, events may arrive out of order.

**Testing Approaches:**
- Verify internal events (raise) are processed before external events
- Test rapid-fire external events: verify they're processed in FIFO order
- Test event raised during @always processing: verify it's on the internal queue
- **EventMachine relevance:** `EventProcessingOrderTest.php` exists. `MachineDefinition::$eventQueue` handles internal queue. Test with raise() inside entry actions of @always chain targets.

### 4.2 Lost Events / Event Buffering

**Problem:** If an event arrives while the machine is processing another event (run-to-completion semantics), it must be buffered, not dropped. In async systems with queues, events can be lost due to: queue failures, deduplication, worker crashes, timeout evictions.

**Concrete Example:** User clicks "Submit" twice rapidly. First SUBMIT starts processing (transitions to `validating`). Second SUBMIT arrives while the first is still processing. If the second event is dropped, the user's intent is lost. If it's buffered and replayed after the first completes, the machine may handle SUBMIT in `validating` state (which may not have a SUBMIT transition -> error).

**Why Hard to Test:** Requires concurrent event submission during active processing.

**Testing Approaches:**
- Send event while machine is processing another: verify it's queued, not dropped
- Send event to a state that doesn't handle it: verify appropriate error (not silent drop)
- Test `MachineAlreadyRunningException` when lock is held: verify the caller gets a clear rejection
- Test event deduplication: same event sent twice, verify it's not processed twice
- **EventMachine relevance:** `Machine::send()` with `timeout: 0` lock acquisition. `MachineAlreadyRunningException` for rejection. Scenario 10 in deep edge cases.

### 4.3 Stale State Reads

**Problem:** When multiple processes read machine state from DB, they may get stale data if: (a) another process has modified the state but the transaction hasn't committed, (b) read replicas are used, (c) caching layers serve stale state.

**Concrete Example:** Process 1 transitions machine to `approved`. Process 2 reads machine state (still `pending` due to replication lag). Process 2 sends APPROVE event, which is only valid in `pending` state. The approve runs again, causing double-approval.

**Why Hard to Test:** Requires multi-process coordination with controlled timing.

**Testing Approaches:**
- Test state restoration always reads from primary/write DB
- Test that lock acquisition forces a fresh state read (not cached)
- Test optimistic concurrency: if state changed between read and write, retry or fail
- **EventMachine relevance:** `Machine::create(state: $rootEventId)` restores from DB. ParallelRegionJob reloads fresh state inside the lock. Verify the reload is always fresh.

### 4.4 Concurrent Event Processing

**Problem:** Two external events arrive simultaneously for the same machine instance. Without locking, both read the same state, compute transitions independently, and write back -- the second write overwrites the first (lost update).

**Concrete Example:** Two webhook callbacks arrive simultaneously: CREDIT_CHECK_PASSED and EMPLOYMENT_VERIFIED. Both read state `awaiting_checks`. Both transition to the next state independently. The second write overwrites the first, and one check result is lost.

**Why Hard to Test:** Requires truly concurrent requests, not sequential test steps.

**Testing Approaches:**
- Use parallel test processes to send events simultaneously
- Verify locking prevents concurrent processing
- Test lock contention: second event waits or is rejected
- Test that the second event succeeds after the first releases the lock
- **EventMachine relevance:** `MachineLockManager` with database locks. `machine_locks` table. Test with `Machine::send()` from multiple processes (LocalQA).

### 4.5 Race Conditions in State Persistence

**Problem:** Between reading state and writing the new state, another process may have changed the state. This is the classic read-modify-write race condition. Locking mitigates it but introduces contention.

**XState Bug:** Issue #4895 -- Calls to observers' next() method race each other when multiple snapshots are emitted close together, resulting in stale state being saved roughly 50% of the time. Recommended solution: task queue to process snapshots one at a time.

**Testing Approaches:**
- Verify all state mutations happen inside a lock + transaction
- Test that state read inside lock returns the latest committed value
- Test lock contention under load: many concurrent events to the same machine
- **EventMachine relevance:** DB transaction in ParallelRegionJob wraps state mutation. MachineLockManager prevents concurrent access. Test under concurrent load.

### 4.6 Optimistic vs Pessimistic Locking

**Problem:** EventMachine uses pessimistic locking (acquire lock before reading/modifying). Trade-offs: (a) pessimistic locks reduce throughput under contention, (b) lock timeout causes rejection (MachineAlreadyRunningException), (c) dead locks left by crashed workers, (d) lock TTL must be long enough for the longest operation but short enough for cleanup.

**Concrete Example:** Worker holds lock, OOM-killed. Lock row remains in `machine_locks` until TTL expires. All other operations on this machine instance block until TTL cleanup. If TTL is 60 seconds, the machine is effectively "dead" for 60 seconds.

**Testing Approaches:**
- Test lock acquisition and release in normal flow
- Test lock TTL expiry: simulate a crashed worker, verify lock is cleaned up after TTL
- Test lock cleanup sweep: verify stale locks are removed
- Test `MachineLockTimeoutException`: verify clear error message with context
- **EventMachine relevance:** `MachineLockManager::acquire()`, `release()`, cleanup sweep with `$lastCleanupAt` static property. Scenario 7 in deep edge cases.

### 4.7 Event Replay and Idempotency

**Problem:** When restoring machine state from persisted events, replaying events must be idempotent. If events are replayed due to failure recovery, the same actions should not execute twice (e.g., sending duplicate emails, charging a credit card twice).

**Concrete Example:** Machine processes CHARGE_CUSTOMER event, executes ChargeAction (calls payment API), then crashes before persisting. On recovery, the event is replayed. ChargeAction runs again -- customer is double-charged.

**Why Hard to Test:** Requires simulating crash-and-recovery scenarios.

**Testing Approaches:**
- Test state restoration from event history: verify correct final state
- Test idempotency: replay an event that was already processed, verify no duplicate side effects
- Test that actions are not re-executed during state restoration (they should be, but their side effects should be idempotent)
- Test archive-and-restore: verify state is identical before archiving and after restoring
- **EventMachine relevance:** `ArchiveService::archiveMachine()` / `restoreMachine()`. `MachineEventArchive` model. Archive tests exist. Test round-trip fidelity.

### 4.8 Async Action Completion Handling

**Problem:** When an action is async (dispatched to a queue), the machine must wait for it to complete before transitioning. Edge cases: (a) the job succeeds but the completion notification is lost, (b) the job times out, (c) the job fails and is retried, (d) the job completes but the machine has already transitioned due to a timeout.

**Concrete Example:** Child machine delegation: parent spawns async child. Child completes after 30 minutes. Parent receives @done. But the parent had already timed out at 15 minutes and transitioned to `timed_out`. The child's @done arrives at a state that no longer handles it.

**Testing Approaches:**
- Test child completion after parent timeout: verify @done is ignored
- Test child failure with retry: verify parent sees retry failure, not intermediate failures
- Test child completion notification lost: verify timeout fires as fallback
- **EventMachine relevance:** `ChildMachineJob`, `@done`, `@fail`, `@timeout` on child delegation. `MachineChild` tracking table. Test with `simulateChildDone/Fail/Timeout`.

### 4.9 Timeout Handling and Cleanup

**Problem:** Timeouts must be reliably cancelled when a state is exited. If a timeout fires after the machine has left the state, it may cause an invalid transition. In async systems, timeout jobs may be queued but not yet processed.

**Concrete Example:** Machine enters `waiting_for_response` with a 30-second timeout. At T=25s, the response arrives and the machine transitions to `processing`. At T=30s, the timeout job fires. The machine is now in `processing`, which doesn't handle the timeout event.

**Testing Approaches:**
- Test timeout cancelled on state exit: verify timeout does not fire after exit
- Test timeout race with event: event arrives just before timeout, verify timeout is no-op
- Test timer fire deduplication: `machine_timer_fires` table prevents duplicate processing
- Verify timer cleanup in `MachineCurrentState` when state changes
- **EventMachine relevance:** Timer system with `machine_timer_fires` dedup table. `machine:process-timers` sweep command. E2E timer tests exist.

---

## 5. Communication Problems

### 5.1 Parent-Child Machine Communication

**Problem:** When a parent delegates to a child machine, communication is asynchronous. Edge cases: (a) parent sends event to child that has already completed, (b) child sends event to parent that has moved to a different state, (c) child machine crashes without notifying parent, (d) parent and child send events to each other simultaneously (circular).

**Concrete Example:** Parent delegates to child with `sendToParent('CHECK_COMPLETE')` on child completion. Child completes, sends event. But the parent has already moved on due to a timeout. The `sendToParent` event arrives at a state that doesn't handle CHECK_COMPLETE -- it's silently dropped or throws an error.

**Testing Approaches:**
- Test sendToParent after parent timeout: verify graceful handling
- Test sendTo after child completion: verify rejection or queuing
- Test parent-child circular communication: parent sends to child, child sends back, verify no infinite loop
- Test child crash without notification: verify parent eventually times out
- **EventMachine relevance:** `sendTo`, `dispatchTo`, `sendToParent`, `dispatchToParent`. `CommunicationRecorder` for testing. Test with `InteractsWithMachines` trait.

### 5.2 Cross-Machine Event Delivery

**Problem:** `sendTo` delivers events to a different machine instance. In async mode (`dispatchTo`), this uses queue jobs. Edge cases: (a) target machine doesn't exist, (b) target machine is archived, (c) target machine is in a state that doesn't handle the event, (d) event delivery order not guaranteed (queue FIFO is not strict under failures).

**Concrete Example:** Machine A sends `STATUS_UPDATE` to Machine B via `dispatchTo`. Machine B was archived 2 days ago. The `SendToMachineJob` fires, tries to restore Machine B from events -- but Machine B's events have been archived to `machine_event_archives`. Auto-restore should transparently restore, but what if the archive is corrupted?

**Testing Approaches:**
- Test sendTo to non-existent machine: verify error handling
- Test dispatchTo to archived machine: verify auto-restore works
- Test delivery ordering: send multiple events, verify FIFO processing
- Test sendTo when target is processing another event: verify queuing/locking
- **EventMachine relevance:** `SendToMachineJob`. Auto-restore in `ArchiveService`. Test with real dispatch and verification.

### 5.3 Fire-and-Forget Reliability

**Problem:** Fire-and-forget delegation (no @done handler) means the parent doesn't track the child's outcome. Edge cases: (a) child fails silently with no recovery, (b) child generates side effects that the parent assumes happened but didn't, (c) orphaned child machines that never complete, consuming resources.

**Testing Approaches:**
- Test fire-and-forget child failure: verify no parent impact
- Test fire-and-forget child stuck: verify no resource leak alerts
- Test that `MachineChild` record is still created for tracking
- **EventMachine relevance:** `FireAndForgetMachineDelegationTest.php` exists. Verify tracking and cleanup.

### 5.4 Circular Communication / Infinite Loops

**Problem:** Machine A sends event to Machine B, which triggers an action that sends an event back to Machine A, which triggers an action that sends to Machine B again -- infinite loop across machines.

**Concrete Example:**
```
Machine A: on NOTIFY -> (action: sendTo Machine B, 'PROCESS')
Machine B: on PROCESS -> (action: sendTo Machine A, 'NOTIFY')
```

In sync mode, this is an immediate stack overflow. In async mode, it generates an unbounded number of queue jobs.

**Testing Approaches:**
- Test bidirectional sendTo: verify no infinite loop (depth limiting)
- Test with `CommunicationRecorder`: verify the loop is detected or limited
- Test async circular: verify queue doesn't grow unbounded
- **EventMachine relevance:** No built-in cross-machine loop detection. This is a design gap worth testing for.

### 5.5 Event Delivery Ordering Guarantees

**Problem:** When multiple events are sent from Machine A to Machine B, are they delivered in order? Queue systems generally guarantee FIFO per queue, but: (a) retries break ordering, (b) multiple queues break ordering, (c) fan-out patterns break ordering.

**Testing Approaches:**
- Send 10 events in sequence, verify they're processed in order
- Test retry scenario: event 3 fails and retries while events 4-5 process -- ordering broken
- Document ordering guarantees (or lack thereof) in the testing expectations
- **EventMachine relevance:** `dispatchTo` uses queue. Document expected ordering behavior.

---

## 6. Persistence and Recovery Problems

### 6.1 State Restoration Correctness

**Problem:** Restoring a machine from persisted events must produce an identical runtime state. Edge cases: (a) events reference behaviors that have been renamed/removed, (b) context contains objects that don't serialize/deserialize cleanly, (c) incremental context diffs are applied in wrong order.

**Concrete Example:** Machine was persisted with context key `user_id`. Between persistence and restoration, the code was refactored and `user_id` was renamed to `customer_id`. Restoration succeeds but the machine's context has an unexpected `user_id` key and a missing `customer_id` key.

**Testing Approaches:**
- Test basic round-trip: create machine, persist, restore, verify identical state
- Test with complex context values: arrays, nested objects, null values, empty strings
- Test incremental diff application: verify diffs are applied in chronological order
- Test with large event histories (1000+ events): verify restoration completes in reasonable time
- **EventMachine relevance:** `Machine::create(state: $rootEventId)`. `MachineEvent` with incremental context. Test round-trip with various context types.

### 6.2 Context Serialization/Deserialization

**Problem:** Context is JSON-serialized for storage. Edge cases: (a) PHP objects that don't serialize to JSON cleanly, (b) large context exceeding DB column limits, (c) floating-point precision loss, (d) null vs empty string vs missing key, (e) date/time objects, (f) circular references.

**Concrete Example:** Context contains a Carbon date object. JSON serialization converts it to a string. Deserialization returns it as a string, not a Carbon object. Actions expecting a Carbon object throw a type error.

**Testing Approaches:**
- Test serialization of each PHP type: int, float, string, bool, null, array, nested array
- Test edge values: PHP_INT_MAX, PHP_FLOAT_EPSILON, very long strings, deeply nested arrays
- Test null/missing distinction: `{'key': null}` vs `{}` (missing key)
- Test that non-serializable values (closures, resources) are rejected at persist time
- **EventMachine relevance:** `ContextManager` with `toArray()`. `MachineEvent` JSON columns. Test round-trip fidelity.

### 6.3 Event Sourcing Consistency

**Problem:** The machine_events table is an event-sourced store. Each event captures a state transition and context diff. Edge cases: (a) events inserted out of order due to concurrent writes, (b) orphaned events (events whose parent event was deleted), (c) duplicate events from retry logic, (d) events with corrupted JSON payloads.

**Testing Approaches:**
- Verify event chain integrity: each event's `root_event_id` is consistent
- Verify event ordering: events are applied in chronological order during restoration
- Test duplicate event insertion: verify idempotency or detection
- Test corrupted event: verify graceful error on restoration (not silent corruption)
- **EventMachine relevance:** `MachineEvent` model. `EventCollection` for history. Test with corrupted or out-of-order events.

### 6.4 Migration of Persisted State Machines

**Problem:** When machine definitions change between versions (new states, removed states, renamed events), persisted machines created with the old version may fail to restore. This is the "event versioning" problem.

**Concrete Example:** Version 1 had states: `pending -> processing -> done`. Version 2 renames `processing` to `in_progress`. Persisted machines with events referencing `processing` fail to restore because the state definition no longer exists.

**Why Hard to Test:** Requires maintaining multiple versions of machine definitions.

**Testing Approaches:**
- Test restoration of machine persisted with older definition version
- Test adding a new state: old machines should still restore
- Test removing a state: old machines in that state should fail gracefully (not crash)
- Test renaming a state: old machines should fail with a clear error
- **EventMachine relevance:** No built-in versioning. Document as a known limitation. Test that restoration of unknown states produces clear errors.

### 6.5 Archive and Restore Integrity

**Problem:** The archival system compresses and moves events to `machine_event_archives`. Restoration decompresses and re-inserts events. Edge cases: (a) compression corruption, (b) partial archive (some events archived, some not), (c) archive of machine with active children, (d) restore into a DB that has schema changes.

**Testing Approaches:**
- Test archive round-trip: archive then restore, verify identical state
- Test archive of machine with 10,000+ events: verify compression works
- Test partial archive recovery: what happens if archive process crashes midway
- Test restore of corrupted archive: verify clear error
- **EventMachine relevance:** `ArchiveService`, `CompressionManager`, `machine:archive-events` command. `ArchiveEventsCommandTest.php` and `CompressionManagerTest.php` exist.

---

## 7. Timer and Scheduling Problems

### 7.1 Timer Accuracy and Drift

**Problem:** Timers in queue-based systems are inherently imprecise. A 30-second timer might fire at 31, 35, or even 60 seconds depending on queue load, worker availability, and sweep interval. Edge cases: (a) timer resolution is coarser than the timer value, (b) system clock changes (NTP adjustment, DST), (c) timer fires after the machine has already transitioned.

**Concrete Example:** Timer configured for `after: 5` (5 seconds). The `machine:process-timers` sweep runs every 10 seconds. The timer won't fire until the next sweep, so actual delay is 5-15 seconds. For time-sensitive workflows (auction bidding, SLA timers), this imprecision matters.

**Testing Approaches:**
- Test timer fires within acceptable window (configured resolution + sweep interval)
- Test timer with 0 delay: verify it fires on the next sweep
- Test timer accuracy under queue load (many pending jobs)
- Test timer after state change: verify cancelled timer doesn't fire
- **EventMachine relevance:** `machine:process-timers` sweep. Timer resolution in config. `machine_timer_fires` dedup. E2E timer tests exist.

### 7.2 Overlapping Timer Fires

**Problem:** If the sweep interval is shorter than the timer processing time, the next sweep may find the same timers eligible again. Without deduplication, the timer action executes multiple times.

**Concrete Example:** Timer `REMINDER_EVERY_5M` fires every 5 minutes. Processing takes 6 minutes (slow API call). The next sweep at +5 minutes finds the timer eligible again, fires it, creating a duplicate.

**Testing Approaches:**
- Test dedup: fire timer, immediately re-run sweep, verify timer doesn't fire again
- Test `machine_timer_fires` table: verify fire record prevents duplicate
- Test overlapping `every` timer with slow processing
- **EventMachine relevance:** `MachineTimerFire` model for dedup. Test that dedup works under concurrent sweeps.

### 7.3 Timer Cleanup on State Exit

**Problem:** When a state with a timer is exited, the timer should be cancelled. In SCXML, this is automatic. In a queue-based system, a timer job may already be queued but not processed. The timer fire must be detected as stale and skipped.

**Concrete Example:** Machine is in `waiting` with a 30-second timeout timer. At T=25s, an event transitions the machine to `processing`. At T=30s, the timer sweep finds the timer eligible. But the machine is no longer in `waiting`. The timer should be skipped.

**Testing Approaches:**
- Test timer fire after state exit: verify fire is skipped
- Test that `MachineCurrentState` is updated on state change (timers check this)
- Test rapid state changes: enter state, exit immediately, verify timer never fires
- **EventMachine relevance:** Timer fires check `machine_current_states` for active state. Test that state change updates this table before timer can fire.

### 7.4 Recurring Timer Edge Cases

**Problem:** `every` timers fire repeatedly while in a state. Edge cases: (a) `every` with `max` count -- fires exactly N times then stops, (b) `every` timer still in queue when state is exited, (c) `every` timer with state that @always transitions away (enters, immediately exits -- does the timer fire at all?).

**Concrete Example:**
```
retrying:
  every: { delay: 10, action: retryAction, max: 3 }
  on: SUCCESS -> completed
```
Timer fires at T=10, T=20, T=30 (max 3). If SUCCESS arrives at T=25, the third fire at T=30 should be cancelled. But is the T=20 fire still processing when SUCCESS arrives?

**Testing Approaches:**
- Test `every` with max: verify exactly N fires
- Test `every` cancelled by event: verify no more fires after exit
- Test `every` with action that transitions the state: verify timer stops after transition
- **EventMachine relevance:** `EveryTimerMachine`, `EveryWithMaxMachine` stubs. E2E timer every tests exist.

### 7.5 Schedule Timing Edge Cases

**Problem:** Scheduled events fire at specific times (cron-like). Edge cases: (a) schedule fires while the machine is processing another event, (b) schedule fires while the machine is in a state that doesn't handle the event, (c) multiple schedules fire at the same time, (d) schedule fires during deployment (code version change).

**Testing Approaches:**
- Test schedule fire during event processing: verify queuing/rejection
- Test schedule fire in wrong state: verify graceful handling
- Test concurrent schedule fires: verify serialized processing
- **EventMachine relevance:** `MachineScheduler`, `machine:process-scheduled`. `ScheduledEventsE2ETest.php` exists.

---

## 8. Testing-Specific Challenges

### 8.1 Testing Non-Deterministic Behavior

**Problem:** Parallel states, async delegation, and queue processing introduce non-determinism. The same test may pass or fail depending on timing, worker scheduling, and system load.

**Testing Approaches:**
- Use controlled delays (sleep) to force specific ordering
- Use locks/semaphores to synchronize test steps
- Run non-deterministic tests multiple times (flaky detection)
- Use test modes that force deterministic ordering (sync dispatch)
- Separate sync semantics tests (unit) from async behavior tests (LocalQA/E2E)

### 8.2 Testing Race Conditions

**Problem:** Race conditions only manifest with specific timing. Standard sequential tests never trigger them.

**Testing Approaches:**
- Use multiple processes (artisan commands, phpunit --processes) to create true concurrency
- Inject controlled delays in actions to widen the race window
- Use `DB::listen()` to verify SQL ordering
- Run suspect tests 100x in a loop to detect intermittent failures
- Use the LocalQA infrastructure with real MySQL + Redis + Horizon

### 8.3 Testing Timeout Scenarios

**Problem:** Testing timeouts requires waiting for the timeout duration, making tests slow. But reducing timeouts for testing may mask real-world timing issues.

**Testing Approaches:**
- Use `TestMachine::advanceTimersBy()` for in-memory timer testing
- Use configurable timeout values (env vars) for QA tests
- Test timeout logic separately from timeout timing (mock the clock)
- **EventMachine relevance:** `TestMachine` has timer helpers. In-memory timer testing via `8.1.0-in-memory-timer-testing.md` spec.

### 8.4 Testing Event Ordering

**Problem:** Verifying that events are processed in the correct order requires observing intermediate states, not just the final state.

**Testing Approaches:**
- Use logging actions at every transition point
- Verify `EventCollection` history contains events in expected order
- Test internal queue priority over external queue
- Test that `raise()` events process before the next `send()` event

### 8.5 Mocking Async Operations

**Problem:** Async operations (queue jobs, child machines, external API calls) make tests slow and non-deterministic.

**Testing Approaches:**
- `Machine::fake()` for preventing actual machine execution
- `CommunicationRecorder` for testing sendTo/raise without side effects
- `InlineBehaviorFake` for spying on inline closure behaviors
- `fakingAllActions(except:)` for isolating specific behaviors
- `simulateChildDone/Fail/Timeout` for testing child delegation without real child execution
- `Bus::fake()` / `Queue::fake()` for Laravel job assertions

### 8.6 Property-Based Testing for State Machines

**Problem:** State machines have combinatorial state spaces. Manual test cases can only cover a fraction of possible paths. Property-based testing generates random event sequences and checks invariants hold.

**Key Properties to Test:**
1. **Reachability:** Every non-final state can reach at least one final state via some event sequence
2. **Determinism:** For any (state, event) pair, at most one transition fires
3. **Safety:** The machine never enters an invalid state combination
4. **Liveness:** The machine always makes progress (no infinite loops in @always)
5. **Context invariants:** Context values always satisfy defined constraints
6. **Idempotency:** Replaying the same event sequence produces the same final state

**Testing Approaches (per QuickCheck State Machine library):**
- Define a model of the machine's expected behavior
- Generate random event sequences
- Execute against both model and implementation
- Check that post-conditions hold after each step
- Shrink failing sequences to minimal counterexamples

### 8.7 Model Checking Approaches

**Problem:** The state explosion problem makes exhaustive checking of all possible states infeasible for complex machines. A machine with N context variables each with M possible values has M^N possible states.

**Practical Approaches:**
- Bounded model checking: check all states reachable within K transitions
- Symbolic execution: represent sets of states symbolically
- Partial order reduction: avoid exploring equivalent orderings
- For EventMachine: focus on structural properties (reachability, deadlock-freedom) rather than full state-space exploration

### 8.8 Conformance Testing (SCXML W3C Tests)

**Problem:** The W3C SCXML test suite contains ~500 tests covering the specification's normative assertions. Not all are applicable to every implementation.

**Key Test Categories Relevant to EventMachine:**
- Basic transitions and event processing
- Hierarchical state entry/exit ordering
- Parallel state semantics and done detection
- Internal vs external transitions
- Guard evaluation and condition semantics
- Raise (internal event) ordering
- Data model and context management
- Error handling and fallback behavior

**Testing Approaches:**
- Map each SCXML test to an EventMachine equivalent
- Identify which SCXML tests are already covered by existing tests
- Create stub machines that replicate specific SCXML test scenarios
- Document intentional deviations from the SCXML spec

---

## 9. EventMachine-Specific Testing Gaps (Based on Research)

### 9.1 Identified High-Priority Gaps

Based on this research cross-referenced with the existing test suite, here are the testing areas with the most significant gaps:

| # | Gap | Risk | Existing Coverage | Recommended Tests |
|---|-----|------|-------------------|-------------------|
| 1 | Guard mutual exclusion verification | High | None | Property test: same event, multiple guards, verify at most one true |
| 2 | @always infinite loop with conditional guards | High | MaxTransitionDepthTest only | Test @always chains where all guards false, context-dependent guards |
| 3 | Parallel region event broadcasting | High | Limited | Test single event triggers transitions in ALL regions |
| 4 | Entry/exit action ordering in deep hierarchy (4+ levels) | Medium | 2-level tests only | Create 4-level hierarchy with action-ordering witnesses |
| 5 | Self-transition vs internal transition with children | High | None apparent | Test child state reset (external) vs preservation (internal) |
| 6 | Context serialization edge cases | Medium | Basic tests | Test PHP_INT_MAX, deep nesting, null vs missing, float precision |
| 7 | Cross-machine circular communication detection | Medium | None | Test bidirectional sendTo loop |
| 8 | Timer fire after state exit | Medium | E2E tests | Unit test: timer fires, machine not in expected state, verify skip |
| 9 | Event ordering: raise() priority over send() | Medium | EventProcessingOrderTest | Extend with nested raise-during-@always scenarios |
| 10 | State restoration with unknown state (schema migration) | Low | None | Test restore when definition has changed |
| 11 | Parallel region done detection with nested compounds | High | Basic done tests | Test @done with deeply nested final states |
| 12 | send() during parallel execution (Scenario 10) | Critical | None (spec only) | Implement Scenario 10 from deep edge cases |
| 13 | Both regions fail simultaneously (Scenario 9) | High | None (spec only) | Implement Scenario 9 from deep edge cases |
| 14 | Region that never raises (stuck machine, Scenario 8) | High | None (spec only) | Implement Scenario 8 from deep edge cases |
| 15 | Concurrent Machine::send() to same instance | High | None | Test with parallel processes |

### 9.2 Structural/Static Analysis Tests

These don't test runtime behavior but verify machine definitions are well-formed:

- All states reachable from initial
- All non-final states have at least one outgoing transition
- No transition targets a non-existent state
- Every compound state has an `initial` child
- Parallel state has 2+ regions
- Guards are referentially transparent (no side effects) -- hard to verify automatically
- Context keys used in guards/actions are defined in initial context
- Event types used in transitions are defined in the behavior section

### 9.3 Regression Test Priorities (from XState Bug History)

Based on bugs found in XState's issue tracker, these deserve explicit regression tests in EventMachine:

1. **External self-transition resets child states** (XState #1885, #131)
2. **Parallel states: external transition resets sibling regions** (XState #400, #1829)
3. **Nested parallel done events don't bubble** (XState #2349)
4. **Delayed transitions in parallel states can't be reset** (XState #753)
5. **onError fires for all substates in parallel** (XState #427)
6. **Parallel region reentrancy on external transitions** (XState commit e35493f)
7. **Raised events in parallel don't propagate to siblings** (XState #4456)
8. **Data not passed to parent onDone for parallel** (XState #335)
9. **Entry action for state with eventless transition called after next state** (XState #1508)

---

## Sources

- [W3C SCXML Specification](https://www.w3.org/TR/scxml/)
- [SCXML Framework Compliance Tests](https://alexzhornyak.github.io/SCXML-tutorial/Tests/)
- [Harel's Original Statecharts Paper (PDF)](https://www.state-machine.com/doc/Harel87.pdf)
- [XState: Parallel states transitions behave differently on V5](https://github.com/statelyai/xstate/issues/4793)
- [XState: Race condition in credit check example](https://github.com/statelyai/xstate/discussions/4895)
- [XState: Issues completing nested Parallel states](https://github.com/statelyai/xstate/issues/1341)
- [XState: Nested parallel states don't bubble up done event](https://github.com/davidkpiano/xstate/issues/2349)
- [XState: Parallel machine transition resetting sibling states](https://github.com/statelyai/xstate/issues/400)
- [XState: Surprising external transitions behavior for parallel states](https://github.com/davidkpiano/xstate/discussions/1829)
- [XState: External self transitions not working for parallel states](https://github.com/statelyai/xstate/issues/531)
- [XState: Raised events in parallel don't propagate to siblings](https://github.com/statelyai/xstate/discussions/4456)
- [XState: Fixed parallel region reentrancy](https://github.com/statelyai/xstate/commit/e35493f59d277ca57f0982417d5ba3bca0a352ed)
- [XState: Delayed events in parallel states cannot be reset](https://github.com/statelyai/xstate/issues/753)
- [XState: Delayed self transition on parallel state treated as internal](https://github.com/statelyai/xstate/issues/1118)
- [XState: Issue with onError in parallel states](https://github.com/statelyai/xstate/issues/427)
- [XState: Data not passed to parent onDone for parallel](https://github.com/davidkpiano/xstate/issues/335)
- [XState: Entry for state with eventless transition called after next state](https://github.com/statelyai/xstate/issues/1508)
- [XState: Eventless transition inside compound state triggers infinite loop](https://github.com/statelyai/xstate/discussions/1592)
- [XState: Eventless (always) transitions documentation](https://stately.ai/docs/eventless-transitions)
- [XState: Events and transitions documentation](https://stately.ai/docs/transitions)
- [XState: Self-transitions and internal transitions](https://egghead.io/lessons/xstate-use-internal-transitions-in-xstate-to-avoid-state-exit-and-re-entry)
- [Statecharts.dev: Guard glossary](https://statecharts.dev/glossary/guard.html)
- [Statecharts.dev: Delayed transition glossary](https://statecharts.dev/glossary/delayed-transition.html)
- [Sismic: Statecharts execution documentation](https://sismic.readthedocs.io/en/latest/execution.html)
- [UML State Machine - Wikipedia](https://en.wikipedia.org/wiki/UML_state_machine)
- [State Hierarchy and LCA](https://dev.ionous.net/2012/06/state-hierarchy-and-lca.html)
- [Zephyr: State Machine Framework RFC](https://github.com/zephyrproject-rtos/zephyr/issues/71675)
- [QuickCheck State Machine library](https://hackage.haskell.org/package/quickcheck-state-machine)
- [Stateful property-based testing with QuickCheck](https://dev.to/meeshkan/stateful-property-based-testing-with-quickcheck-state-machine-4mp5)
- [Model Checking and the State Explosion Problem](https://pzuliani.github.io/papers/LASER2011-Model-Checking.pdf)
- [Model-Based Testing Using State Machines](https://abstracta.us/blog/software-testing/model-based-testing-using-state-machines/)
- [Common Pitfalls with State Machines](https://statemachine.app/article/Common_pitfalls_to_avoid_when_working_with_state_machines.html)
- [Safety and Liveness Properties](https://www.hillelwayne.com/post/safety-and-liveness/)
- [Event Versioning in Event Sourcing](https://event-driven.io/en/how_to_do_event_versioning/)
- [Optimistic Concurrency for Pessimistic Times](https://event-driven.io/en/optimistic_concurrency_for_pessimistic_times/)
- [Idempotent Command Handling](https://event-driven.io/en/idempotent_command_handling/)
- [Formal Verification of Statecharts using Model Checkers](https://ieeexplore.ieee.org/document/1668157/)
- [Formalizing UML State Machines for Automated Verification](https://arxiv.org/pdf/2407.17215)
- [STATEMATE Semantics of Statecharts](https://bears.ece.ucsb.edu/class/ece253/papers/harel96.pdf)
- [XState: Persistence documentation](https://stately.ai/docs/persistence)
- [MassTransit: State Machine Events](https://masstransit.io/documentation/configuration/sagas/event)
- [Boost StateChart: Event processing order in async machine](https://boost-users.boost.narkive.com/B93S8bd3/statechart-event-processing-order-in-async-machine)
