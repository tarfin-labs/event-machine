# Lock Infrastructure: config + migration + model + service + exception
type: task
priority: 1
labels: parallel-dispatch, phase-a, infrastructure
estimate: 120
---

## Overview
Create the database lock infrastructure that replaces Redis `Cache::lock()`. This is the foundation for all parallel dispatch coordination.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Step 1 (all sub-steps) + Step 2

## TDD Approach

### 1. Write failing tests FIRST (Phase A tests #1-6)

Create `tests/Features/ParallelStates/MachineLockManagerTest.php`:

- `MachineLockManager::acquire() acquires lock` — returns MachineLockHandle, row visible in machine_locks
- `MachineLockManager::acquire() blocks when lock held` — second acquire waits, acquires after first releases
- `MachineLockManager::acquire() times out` — throws MachineLockTimeoutException with holder info after timeout
- `Stale lock cleanup` — expired lock (expires_at < now) is cleaned up before new acquisition
- `MachineLockHandle::release() removes lock` — row deleted, subsequent acquire succeeds immediately
- `MachineLockHandle::extend() extends TTL` — expires_at updated without releasing lock

### 2. Implement to make tests pass

Files to create:
- `config/machine.php` — add `parallel_dispatch` section (enabled, queue, lock_timeout, lock_ttl)
- `database/migrations/create_machine_locks_table.php.stub` — migration (root_event_id PK, owner_id, context, expires_at, created_at)
- `src/Models/MachineStateLock.php` — Eloquent model
- `src/Locks/MachineLockManager.php` — acquire() with two modes (immediate timeout=0, blocking timeout=N), stale cleanup
- `src/Locks/MachineLockHandle.php` — release(), extend(), value object
- `src/Exceptions/MachineLockTimeoutException.php` — includes holder info
- `src/MachineServiceProvider.php` — register new migration

### 3. Edge cases
- PK constraint prevents simultaneous inserts (DB-level safety)
- Stale lock self-healing via expires_at < now() cleanup before insert
- Lock context column for debugging ("parallel_region:region_findeks")

### 4. Quality Gate
Run `composer test` — all existing tests must still pass. New lock tests must pass.

### 5. Commit
Use `/commit` skill with agentic-commits format.

## Acceptance Criteria
- [ ] MachineLockManager acquires, blocks, times out correctly
- [ ] MachineLockHandle releases and extends correctly
- [ ] Stale locks are self-healed
- [ ] Migration is publishable via vendor:publish
- [ ] `composer test` passes (zero regression)

---

# Machine::send() lock migration: Cache::lock → MachineLockManager
type: task
priority: 1
labels: parallel-dispatch, phase-a, breaking-change
estimate: 60
deps: event-machine-LOCK_INFRA
---

## Overview
Replace `Cache::lock()->get()` in `Machine::send()` with `MachineLockManager::acquire()` using immediate mode (timeout=0). This preserves the existing `MachineAlreadyRunningException` fail-fast semantics.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Step 1f, Step 3

## TDD Approach

### 1. Write failing tests FIRST (Phase A test #7 + Phase E #48)

Create `tests/Features/ParallelStates/MachineSendLockTest.php`:

- `Machine::send() uses database lock instead of Cache::lock` — send acquires MachineLockManager lock, row visible in machine_locks during execution
- `Machine::send() uses immediate lock mode (timeout=0)` — two concurrent send() calls: first acquires, second throws MachineAlreadyRunningException immediately (no waiting)
- `Machine::send() lock semantics preserved` — existing MachineAlreadyRunningException behavior is identical

### 2. Implement

Modify `src/Actor/Machine.php`:
- Replace `Cache::lock("mre:{$rootEventId}")->get(callback)` with `MachineLockManager::acquire(rootEventId, timeout: 0)` + try/finally
- Catch `MachineLockTimeoutException` → rethrow as `MachineAlreadyRunningException` (preserves existing API)
- Lock release in finally block

### 3. Quality Gate
Run `composer test` — ALL existing tests must pass. This is a behavioral change in locking mechanism, so full regression is critical.

### 4. Commit
Use `/commit` skill with agentic-commits format.

## Acceptance Criteria
- [ ] Machine::send() uses MachineLockManager instead of Cache::lock
- [ ] MachineAlreadyRunningException behavior preserved (immediate fail, no waiting)
- [ ] ALL existing tests pass (zero regression)
- [ ] `composer test` passes

---

# Validation: onFail key + parallel dispatch prerequisites
type: task
priority: 1
labels: parallel-dispatch, phase-b, validation
estimate: 60
deps: event-machine-LOCK_INFRA
---

## Overview
Add fail-fast validation for parallel dispatch prerequisites. Catch configuration errors at machine definition time, not at runtime.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Step 0 (all sub-steps) + Phase B

## TDD Approach

### 1. Write failing tests FIRST (Phase B tests #8-10)

Create `tests/Features/ParallelStates/ParallelDispatchValidationTest.php`:

- `parallel_dispatch.enabled + should_persist:false → throws requiresPersistence` — at MachineDefinition construction time
- `parallel_dispatch.enabled + base Machine::class → throws requiresMachineSubclass` — at Machine::start() time
- `parallel_dispatch.disabled → no validation (existing behavior)` — both shouldPersist:false and base Machine work fine when dispatch disabled
- `onFail key is accepted in parallel state config` — StateConfigValidator does not reject onFail
- `onFail key is rejected in non-parallel state config` — only parallel states can have onFail

### 2. Implement

Files to modify:
- `src/StateConfigValidator.php` line 21 — add `'onFail'` to allowed state keys list
- `src/Exceptions/InvalidParallelStateDefinitionException.php` — add `requiresPersistence()` + `requiresMachineSubclass()` factory methods
- `src/Definition/MachineDefinition.php::__construct()` — validate parallel_dispatch + shouldPersist
- `src/Actor/Machine.php::start()` — validate parallel_dispatch + Machine subclass, set machineClass + rootEventId on definition

### 3. Quality Gate
Run `composer test` — all existing tests must still pass.

### 4. Commit
Use `/commit` skill with agentic-commits format.

## Acceptance Criteria
- [ ] Invalid config caught at definition/start time with clear exception messages
- [ ] onFail accepted for parallel states only
- [ ] Disabled dispatch = no validation = existing behavior
- [ ] `composer test` passes

---

# API surface: InternalEvent enum + createEventBehavior proxy + areAllRegionsFinal visibility
type: task
priority: 1
labels: parallel-dispatch, phase-c, refactor
estimate: 45
---

## Overview
Prepare the API surface that ParallelRegionJob will need. Add enum case, public proxy method, and change visibility — all without changing any behavior.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Step 4a (properties), 4b (proxy), 4d (visibility), InternalEvent enum

## TDD Approach

### 1. Write failing tests FIRST (Phase E tests #55-56)

Add to existing test file or create `tests/Features/ParallelStates/ParallelApiSurfaceTest.php`:

- `createEventBehavior() delegates to initializeEvent()` — returns same EventBehavior as initializeEvent would, accepts array input
- `areAllRegionsFinal() is public and read-only` — calling it does not modify state or definition, returns correct for: all final, partially final, none final
- `InternalEvent::PARALLEL_FAIL generates correct event name` — uses `{machine}.parallel.{placeholder}.fail` pattern

### 2. Implement

Files to modify:
- `src/Enums/InternalEvent.php` — add `PARALLEL_FAIL` case
- `src/Definition/MachineDefinition.php`:
  - Add 3 new properties: `$machineClass`, `$rootEventId`, `$pendingParallelDispatches`
  - Add `createEventBehavior()` public proxy (delegates to protected `initializeEvent()`)
  - Change `areAllRegionsFinal()` from `protected` to `public`

### 3. Quality Gate
Run `composer test` — pure additive changes, zero regression expected.

### 4. Commit
Use `/commit` skill with agentic-commits format.

## Acceptance Criteria
- [ ] PARALLEL_FAIL enum case generates correct event names
- [ ] createEventBehavior() is public and delegates correctly
- [ ] areAllRegionsFinal() is public and read-only
- [ ] New properties exist on MachineDefinition
- [ ] `composer test` passes (zero regression)

---

# Extract processParallelOnDone() from transitionParallelState()
type: task
priority: 1
labels: parallel-dispatch, phase-c, refactor
estimate: 60
deps: event-machine-API_SURFACE
---

## Overview
Extract the inline onDone logic (lines 975-1034 of transitionParallelState) into a public `processParallelOnDone()` method. Both the sequential path and the future ParallelRegionJob will call this single source of truth.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Step 4c

## TDD Approach

### 1. Write failing test FIRST (Phase E test #57)

Add to test file:

- `processParallelOnDone() produces same result as inline logic` — run all existing parallel state onDone tests, verify zero behavioral change after extraction
- `processParallelOnDone() accepts nullable eventBehavior` — sequential path passes event, parallel path passes null, both work
- `transitionParallelState() delegates to processParallelOnDone()` — verify the refactored code path

### 2. Implement

Extract from `src/Definition/MachineDefinition.php::transitionParallelState()` lines 975-1034 into:

```php
public function processParallelOnDone(
    StateDefinition $parallelState,
    State $state,
    ?EventBehavior $eventBehavior = null,
): State
```

Replace the inline block with:
```php
if ($this->areAllRegionsFinal($state->currentStateDefinition, $state)) {
    return $this->processParallelOnDone($state->currentStateDefinition, $state, $eventBehavior);
}
```

### 3. CRITICAL: Full regression
This is a refactor of core parallel state logic. Run `composer test` and verify ALL existing parallel state tests pass identically.

### 4. Commit
Use `/commit` skill with agentic-commits format.

## Acceptance Criteria
- [ ] processParallelOnDone() exists as public method
- [ ] transitionParallelState() delegates to it
- [ ] ALL existing parallel state tests pass (zero regression)
- [ ] `composer test` passes

---

# Add processParallelOnFail() — @fail event handler
type: task
priority: 1
labels: parallel-dispatch, phase-c, feature
estimate: 60
deps: event-machine-EXTRACT_ONDONE
---

## Overview
Add `processParallelOnFail()` — symmetric to `processParallelOnDone()`. Handles the `@fail` internal event when a region job permanently fails. If `onFail` config is defined, transitions the machine to the error target state.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Step 4c-ii

## TDD Approach

### 1. Write failing tests FIRST

Create `tests/Features/ParallelStates/ParallelOnFailTest.php`:

- `processParallelOnFail() with onFail config transitions to error state` — parallel state exits, error state entered
- `processParallelOnFail() without onFail config records PARALLEL_FAIL and stays` — no transition, but internal event in history
- `processParallelOnFail() runs onFail actions before exit` — actions config supported
- `processParallelOnFail() exits children before parent (SCXML ordering)` — same exit ordering as onDone
- `processParallelOnFail() accepts EventBehavior with failure payload` — action can read region_id, error, exception from payload
- `processParallelOnFail() preserves completed sibling context` — fresh state reload shows sibling's context

### 2. Implement

Add to `src/Definition/MachineDefinition.php`:

```php
public function processParallelOnFail(
    StateDefinition $parallelState,
    State $state,
    ?EventBehavior $eventBehavior = null,
): State
```

Structure mirrors processParallelOnDone but:
- Fires `InternalEvent::PARALLEL_FAIL` instead of `PARALLEL_DONE`
- Reads `onFail` config instead of `onDone`
- Runs onFail actions (if defined) before exit actions

### 3. Quality Gate
Run `composer test`.

### 4. Commit
Use `/commit` skill with agentic-commits format.

## Acceptance Criteria
- [ ] processParallelOnFail() handles onFail config (target + actions)
- [ ] Without onFail, records PARALLEL_FAIL event, no transition
- [ ] Exit ordering matches SCXML (children before parent)
- [ ] EventBehavior payload accessible in onFail actions
- [ ] `composer test` passes

---

# Dispatch mechanism: shouldDispatchParallel() + modified enterParallelState()
type: task
priority: 1
labels: parallel-dispatch, phase-c, feature
estimate: 90
deps: event-machine-LOCK_INFRA,event-machine-EXTRACT_ONDONE
---

## Overview
Add the dispatch decision logic and modify `enterParallelState()` to mark pending dispatches instead of running entry actions synchronously when dispatch is enabled.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Step 4e (shouldDispatchParallel), Step 4f (enterParallelState)

## TDD Approach

### 1. Write failing tests FIRST (Phase C tests #11-12)

Create `tests/Features/ParallelStates/ParallelDispatchMechanismTest.php`:

- `shouldDispatchParallel() returns false when config disabled`
- `shouldDispatchParallel() returns false when shouldPersist is false`
- `shouldDispatchParallel() returns false when machineClass is null or base Machine`
- `shouldDispatchParallel() returns false when < 2 regions have entry actions`
- `shouldDispatchParallel() returns true when all conditions met`
- `enterParallelState() with dispatch enabled: pendingParallelDispatches populated, entry actions NOT run`
- `enterParallelState() with dispatch disabled: entry actions run normally (existing behavior)`
- `enterParallelState() dispatch mode records region enter internal events`

### 2. Implement

Add to `src/Definition/MachineDefinition.php`:
- `shouldDispatchParallel(StateDefinition $parallelState): bool`
- Modified `enterParallelState()` — if `shouldDispatchParallel()`, skip entry actions, populate `$this->pendingParallelDispatches[]`

### 3. Quality Gate
Run `composer test`.

### 4. Commit
Use `/commit` skill with agentic-commits format.

## Acceptance Criteria
- [ ] shouldDispatchParallel() evaluates all 4 conditions correctly
- [ ] enterParallelState() marks pending dispatches in dispatch mode
- [ ] enterParallelState() preserves existing behavior in sequential mode
- [ ] Internal events (region enter) still recorded in dispatch mode
- [ ] `composer test` passes

---

# Machine dispatch coordination: dispatchPendingParallelJobs + send() finally block
type: task
priority: 1
labels: parallel-dispatch, phase-c, feature
estimate: 60
deps: event-machine-SEND_LOCK,event-machine-DISPATCH_MECHANISM
---

## Overview
Wire the dispatch mechanism into `Machine::send()`. After persist and lock release, dispatch any pending parallel region jobs. Also set machineClass + rootEventId on definition in `Machine::start()`.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Step 3a (start), Step 3b (send + dispatchPendingParallelJobs)

## TDD Approach

### 1. Write failing tests FIRST (Phase C test #13)

Add to `tests/Features/ParallelStates/ParallelDispatchCoordinationTest.php`:

- `Machine::send() dispatches jobs after persist` — jobs dispatched with correct machineClass, rootEventId, regionId, eventPayload
- `pendingParallelDispatches cleared after dispatch` — array empty after dispatchPendingParallelJobs()
- `Machine::start() sets machineClass on definition` — static::class stored
- `Machine::start() sets rootEventId on definition from history` — root event ID from first history event
- `dispatchPendingParallelJobs() dispatches to configured queue` — respects parallel_dispatch.queue config
- `dispatchPendingParallelJobs() no-ops when no pending dispatches` — empty array = no dispatch calls
- `Sync queue driver does NOT deadlock` — dispatch in finally after lock release, sync job runs, acquires lock successfully (Phase E test #50)

### 2. Implement

Modify `src/Actor/Machine.php`:
- `start()` — set `$this->definition->machineClass = static::class` and `$this->definition->rootEventId`
- `send()` — restructure to: try { transition + persist } finally { lockHandle?.release(); dispatchPendingParallelJobs(); }
- New public method: `dispatchPendingParallelJobs()` — iterates pendingParallelDispatches, creates ParallelRegionJob, dispatches, clears array

### 3. Quality Gate
Run `composer test`.

### 4. Commit
Use `/commit` skill with agentic-commits format.

## Acceptance Criteria
- [ ] Jobs dispatched after persist and after lock release (finally block)
- [ ] Correct job parameters (machineClass, rootEventId, regionId, eventPayload)
- [ ] No deadlock with sync queue driver
- [ ] machineClass and rootEventId set correctly on definition
- [ ] `composer test` passes

---

# ParallelRegionJob: handle() with double-guard pattern
type: task
priority: 1
labels: parallel-dispatch, phase-c, feature
estimate: 120
deps: event-machine-DISPATCH_COORDINATION,event-machine-API_SURFACE,event-machine-EXTRACT_ONDONE
---

## Overview
Create the `ParallelRegionJob` queue job class. This is the core of parallel dispatch — runs entry actions without lock, then serializes state updates under lock with double-guard pattern.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Step 5 (full ParallelRegionJob code)

## TDD Approach

### 1. Write failing tests FIRST (Phase C tests #14-19)

Create `tests/Features/ParallelStates/ParallelRegionJobTest.php`:

- `ParallelRegionJob processes entry action and updates state` — job runs entry action, applies context changes, persists
- `Two regions complete with correct context merge` — Region A sets key_a, Region B sets key_b, after both complete context has both
- `Last job triggers areAllRegionsFinal + onDone` — first job completes (A final, B working), second completes (both final → onDone → next state)
- `Raised events from entry actions cause region transitions` — entry action raises REPORT_SAVED → region transitions to next state
- `@always transitions fire after parallel region completion` — cross-region sync works
- `Chained compound onDone within parallel dispatch` — entry → raise → final → compound onDone

### 2. Also write guard tests (Phase E tests #52-54)

- `Job no-ops when machine is no longer in parallel state (before action)` — external CANCEL moved machine out
- `Job no-ops when machine is no longer in parallel state (under lock)` — external event between action and lock
- `Job no-ops when region is no longer at initial state` — retry scenario, region already advanced

### 3. Implement

Create `src/Jobs/ParallelRegionJob.php`:
- Constructor: machineClass, rootEventId, regionId, eventPayload
- handle(): reconstruct → guard → entry action (no lock) → capture diff + events → lock → fresh reload → guard → merge → transition → onDone check → persist → dispatch
- computeContextDiff() and arrayRecursiveMerge() helper methods
- Double-guard pattern: check before action + check under lock after fresh reload
- Parallel parent resolution: `$region->parent` NOT `currentStateDefinition`

### 4. Quality Gate
Run `composer test`.

### 5. Commit
Use `/commit` skill with agentic-commits format.

## Acceptance Criteria
- [ ] Entry actions run WITHOUT lock (parallel execution)
- [ ] Context diff correctly computed and merged under lock
- [ ] Raised events captured and processed under lock
- [ ] areAllRegionsFinal + processParallelOnDone triggered by last job
- [ ] Double-guard: both pre-action and under-lock checks work
- [ ] Parallel parent resolved via $region->parent
- [ ] `composer test` passes

---

# ParallelRegionJob: failed() with @fail event
type: task
priority: 1
labels: parallel-dispatch, phase-c, feature
estimate: 60
deps: event-machine-REGION_JOB,event-machine-ONFAIL
---

## Overview
Implement the `failed()` method on ParallelRegionJob. When all retries are exhausted, trigger the `@fail` internal event. If `onFail` is defined, transition the machine to the error state.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Step 5 (failed() method code)

## TDD Approach

### 1. Write failing tests FIRST (Phase G tests #70-72, #79)

Add to `tests/Features/ParallelStates/ParallelRegionJobFailedTest.php`:

- `Region job fails with onFail defined → machine transitions to error state` — failed() acquires lock → processParallelOnFail → exits parallel → enters error
- `Region job fails without onFail defined → machine stays in parallel state` — PARALLEL_FAIL event recorded, no transition
- `@fail payload contains failure details` — EventBehavior payload: region_id, error, exception class, attempts
- `@fail handler itself throws → last-resort logging` — outer catch logs both errors, machine stays in parallel state

### 2. Implement

Add to `src/Jobs/ParallelRegionJob.php::failed()`:
- Acquire lock (blocking mode)
- Reload fresh state, guard: still in parallel?
- Create EventDefinition with @fail payload (region_id, error, exception, attempts)
- Call processParallelOnFail()
- Persist
- Dispatch pending parallel jobs (onFail target could be parallel)
- Last-resort try/catch for handler failures

### 3. Quality Gate
Run `composer test`.

### 4. Commit
Use `/commit` skill with agentic-commits format.

## Acceptance Criteria
- [ ] @fail fires when all retries exhausted
- [ ] onFail config respected (target + actions)
- [ ] Failure payload accessible in onFail actions
- [ ] Handler failure caught and logged
- [ ] `composer test` passes

---

# Nested parallel dispatch: transitionParallelState modification
type: task
priority: 1
labels: parallel-dispatch, phase-c, feature
estimate: 60
deps: event-machine-REGION_JOB
---

## Overview
When a transition within a parallel region targets a nested parallel state, the dispatch mechanism should apply to the inner parallel state too. Modify `transitionParallelState()` lines 903-918 to use dispatch mode for nested parallel entry.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Step 4g

## TDD Approach

### 1. Write failing tests FIRST (Phase D tests #44-45, Phase E #58)

Create `tests/Features/ParallelStates/NestedParallelDispatchTest.php`:

- `Transition INTO a nested parallel state within a region dispatches correctly` — region event → sub-parallel state → new ParallelRegionJobs for sub-regions
- `Three-level nesting: parallel → region → parallel → region` — outer dispatches 2 jobs, one enters inner parallel, inner dispatches its own jobs
- `onDone target is another parallel state → new dispatch cycle` — last job triggers onDone → target is parallel → processParallelOnDone enters it → marks pending → dispatches

### 2. Implement

Modify `src/Definition/MachineDefinition.php::transitionParallelState()` at lines 903-918:
- When transitioning INTO a parallel state, check `shouldDispatchParallel()`
- If dispatching, mark pending and skip entry actions (same pattern as enterParallelState)

### 3. Quality Gate
Run `composer test`.

### 4. Commit
Use `/commit` skill with agentic-commits format.

## Acceptance Criteria
- [ ] Nested parallel states dispatch their own region jobs
- [ ] Multi-level nesting works (parallel within parallel)
- [ ] Chained dispatch (onDone → parallel → dispatch) works
- [ ] `composer test` passes

---

# Integration tests: core parallel dispatch end-to-end
type: task
priority: 1
labels: parallel-dispatch, phase-c, test
estimate: 90
deps: event-machine-REGION_JOB
---

## Overview
End-to-end integration tests that verify the complete parallel dispatch lifecycle with realistic machine configurations. These tests use the sync queue driver to verify the full flow in a single process.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Phase C tests #14-20

## TDD Approach

### 1. Create test machine stubs

Create `tests/Stubs/Machines/ParallelDispatchMachine.php` — a Machine subclass with:
- Two parallel regions (region_findeks, region_turmob)
- Each with entry actions that set context and raise events
- onDone config transitioning to review state
- onFail config transitioning to error state

### 2. Write integration tests

Create `tests/Features/ParallelStates/ParallelDispatchIntegrationTest.php`:

- `Full lifecycle: enter parallel → dispatch → both complete → onDone` — end-to-end with sync driver
- `Fallback to sequential when config disabled` — same machine, dispatch off, sequential behavior
- `Fallback to sequential when conditions not met` — < 2 regions with entry actions
- `Context from all regions available in onDone target` — onDone entry action reads both regions' context
- `Raised events chain within region` — entry → raise → transition → raise → final
- `@always cross-region sync works with dispatch` — region waits for sibling via @always guard
- `Multiple external events interleaved with job completions` — Event A → lock → transition → release → Job B → lock
- `Async queue driver dispatch timing` — jobs queued, see persisted state when run

### 3. Quality Gate
Run `composer test`.

### 4. Commit
Use `/commit` skill with agentic-commits format.

## Acceptance Criteria
- [ ] Full end-to-end lifecycle works with sync driver
- [ ] Sequential fallback works correctly
- [ ] Context merging works across regions
- [ ] Event chaining and @always work in dispatch mode
- [ ] `composer test` passes

---

# Edge case tests: SCXML/XState conformance
type: task
priority: 2
labels: parallel-dispatch, phase-d, test, conformance
estimate: 90
deps: event-machine-INTEGRATION_TESTS
---

## Overview
Tests derived from XState parallel tests, SCXML W3C conformance tests. Verify that parallel dispatch respects statechart semantics.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Phase D tests #21-32

## Tests to implement

Create `tests/Features/ParallelStates/ParallelDispatchConformanceTest.php`:

### Done Event Ordering (SCXML test570, test417, test372)
- `Region done events fire BEFORE parallel done event`
- `Compound child done.state fires within parallel region`
- `Region done event fires AFTER all onentry actions complete`

### Entry/Exit Action Ordering (SCXML test404, test405, test406)
- `Parallel state entry: parent entered before children`
- `Parallel state exit: children exited before parent`
- `Entry/exit ordering across default entry into nested parallel compound states`

### Simultaneous Orthogonal Transitions (XState)
- `Same event transitions multiple regions simultaneously`
- `Targetless transition in parallel region is a no-op`

### Cross-Region Targeting (XState)
- `Cross-region event does NOT re-enter parallel state`
- `Re-entering transitions fire entry actions again`

### Internal Events Priority (SCXML test421)
- `Internal events (raised) take priority over external events`

### History States (XState #3170)
- `History states do NOT interfere with areAllRegionsFinal()`

## Quality Gate
Run `composer test`.

## Commit
Use `/commit` skill with agentic-commits format.

---

# Edge case tests: job failure, lock contention, external events
type: task
priority: 2
labels: parallel-dispatch, phase-d, test, edge-cases
estimate: 90
deps: event-machine-INTEGRATION_TESTS
---

## Overview
Tests for failure scenarios, lock contention, and external event interference during parallel dispatch. Derived from Spring State Machine issues and our own design review.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Phase D tests #33-35, #39-43, #46-47

## Tests to implement

Create `tests/Features/ParallelStates/ParallelDispatchFailureTest.php`:

### Job Failure & Partial State (Spring SM #493)
- `Single region job failure leaves machine in partial-final state`
- `Job failure does NOT corrupt other regions' state`
- `All region jobs fail → machine stuck in parallel initial`

### Context Merge Edge Cases
- `Deeply nested context keys merge correctly`
- `Both regions write to same top-level key → last writer wins`
- `Empty context diff does NOT cause spurious persist`

### Lock Contention
- `Both jobs finish at exact same millisecond → one waits, both succeed`
- `Lock TTL expires during long action → stale lock self-heals`

### External Events During Dispatch
- `External event sent while region jobs are in-flight`
- `Multiple external events interleaved with job completions`

### onDone Timing
- `onDone actions see fully merged context from all regions`
- `onDone target state's entry actions fire in the last job's process`

## Quality Gate
Run `composer test`.

## Commit
Use `/commit` skill with agentic-commits format.

---

# Edge case tests: review-discovered issues
type: task
priority: 2
labels: parallel-dispatch, phase-e, test, edge-cases
estimate: 60
deps: event-machine-INTEGRATION_TESTS
---

## Overview
Tests addressing issues found during plan review. Verify lock modes, sync driver safety, and API surface contracts.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Phase E tests #48-58

## Tests to implement

Create `tests/Features/ParallelStates/ParallelDispatchReviewTest.php`:

### Lock Mode Semantics
- `Machine::send() uses immediate lock mode (timeout=0)` (if not already in send lock task)
- `ParallelRegionJob uses blocking lock mode (timeout=30)`

### Sync Queue Driver Safety
- `Sync queue driver does NOT deadlock` (if not already in coordination task)
- `Async queue driver dispatch timing`

### API Surface Changes
- `createEventBehavior() delegates to initializeEvent()`
- `areAllRegionsFinal() is public and read-only`
- `processParallelOnDone() produces same result as inline logic`

### Chained Parallel Dispatch
- `onDone target is another parallel state → new dispatch cycle`

## Quality Gate
Run `composer test`.

## Commit
Use `/commit` skill with agentic-commits format.

---

# Event queue isolation tests
type: task
priority: 2
labels: parallel-dispatch, phase-f, test
estimate: 90
deps: event-machine-INTEGRATION_TESTS
---

## Overview
Verify that the in-memory event queue works correctly in parallel dispatch mode. No events lost or duplicated across processes.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Phase F tests #59-69

## Tests to implement

Create `tests/Features/ParallelStates/ParallelEventQueueIsolationTest.php`:

### Region Entry Action Raises
- `Region entry action raises single event → captured and processed by job`
- `Region entry action raises multiple events → all processed in order`
- `Region entry action raises NO events → context-only update works`
- `Two regions raise different events → each job processes its own`

### Parallel State's Own Entry Action Raises
- `Parallel state's entry action raises event → processed in Phase 1 (same process)`
- `Parallel state's entry action raises event that does NOT target regions → safe`

### Cross-Region Raised Events
- `Job A's raised event broadcasts to all regions via transitionParallelState()`
- `Cross-region event advances sibling → sibling job detects stale state and no-ops`
- `Cross-region event when sibling job hasn't started yet → no race condition`

### Event Queue Isolation
- `Job's eventQueue starts empty (not carried over from Phase 1)`
- `Multiple jobs do NOT share eventQueue (process isolation)`

## Quality Gate
Run `composer test`.

## Commit
Use `/commit` skill with agentic-commits format.

---

# @fail event tests
type: task
priority: 2
labels: parallel-dispatch, phase-g, test
estimate: 90
deps: event-machine-JOB_FAILED
---

## Overview
Comprehensive tests for the @fail internal event — symmetric to @done, triggered when a region job permanently fails.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Phase G tests #70-82

## Tests to implement

Create `tests/Features/ParallelStates/ParallelFailEventTest.php`:

### Basic @fail Flow
- `Region job fails with onFail defined → machine transitions to error state`
- `Region job fails without onFail defined → machine stays in parallel state`
- `@fail payload contains failure details`

### @fail and Sibling Job Interaction
- `@fail cancels sibling job that hasn't started yet`
- `@fail cancels sibling job that is running entry action`
- `@fail cancels sibling job waiting for lock`
- `Both regions fail → first @fail wins, second no-ops`

### @fail with Completed Sibling
- `One region completes, other fails → completed context preserved`
- `One region completes and triggers compound onDone, then sibling fails`

### @fail Edge Cases
- `@fail handler itself throws → last-resort logging`
- `onFail target is another parallel state → new dispatch cycle`
- `onFail with actions config`
- `@fail after external event already moved machine out of parallel`

## Quality Gate
Run `composer test`.

## Commit
Use `/commit` skill with agentic-commits format.

---

# Documentation: parallel-dispatch.md
type: task
priority: 2
labels: parallel-dispatch, phase-h, docs
estimate: 120
deps: event-machine-FAIL_TESTS
---

## Overview
Create the main documentation page for parallel dispatch. This is the most comprehensive doc — covers everything a user needs to know.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Phase H, G1

## Create `docs/advanced/parallel-states/parallel-dispatch.md`

Follow the detailed outline in the plan (G1 section). Key sections:

1. **What is Parallel Dispatch?** — problem, solution, machine-driven vs actor-driven, zero code changes
2. **How It Works** — lifecycle (3 phases), timing example, sequence diagram
3. **Configuration** — enabling, publishing migration, all 4 config keys
4. **Requirements** — Machine subclass, persistence, queue worker
5. **How Region Jobs Work** — action execution, state update under lock, onDone
6. **@fail Event** — onFail config, payload details, HandleFailureAction example
7. **Constraints and Best Practices** — region independence, context key separation, event queue behavior, external events, idempotency warning
8. **Machine Design Guidelines** — onDone + onFail recommendation table
9. **Database Locks** — why not Redis, lock modes, monitoring
10. **Monitoring and Debugging** — status checks, common issues

After writing, run `vendor/bin/doctest docs/advanced/parallel-states/parallel-dispatch.md -v` to verify all code blocks.

## Quality Gate
DocTest passes. `composer test` passes.

## Commit
Use `/commit` skill with agentic-commits format.

---

# Documentation: update existing pages + sidebar
type: task
priority: 2
labels: parallel-dispatch, phase-h, docs
estimate: 60
deps: event-machine-DOC_MAIN
---

## Overview
Update all existing documentation pages to reference parallel dispatch. Add sidebar entry, config reference, warnings, and upgrade notes.

## Plan Reference
`thoughts/shared/plans/parallel-dispatch-implementation.md` — Phase H, G2-G8

## Files to update

### G2. `docs/.vitepress/config.ts`
Add sidebar entry: `{ text: 'Parallel Dispatch', link: '/advanced/parallel-states/parallel-dispatch' }`

### G3. `docs/advanced/parallel-states/index.md`
Add related page link + best practice #7 (use parallel dispatch for expensive entry actions)

### G4. `docs/advanced/parallel-states/event-handling.md`
Add ::: tip about dispatch timing + ::: warning about context in parallel dispatch

### G5. `docs/advanced/parallel-states/persistence.md`
Add "Database Locks for Parallel Dispatch" section

### G6. `docs/advanced/raised-events.md`
Add ::: warning about raised events in parallel dispatch (event queue isolation, constraint)

### G7. `docs/building/configuration.md`
Add parallel_dispatch config reference table

### G8. `docs/getting-started/upgrading.md`
Add upgrade notes: migration required, breaking lock change, opt-in enable

After each file, run `vendor/bin/doctest <file> -v` to verify code blocks.

## Quality Gate
All DocTest checks pass. `composer test` passes.

## Commit
Use `/commit` skill with agentic-commits format.
