# Feature Plan: Parallel Dispatch Deep Edge Case QA (Scenarios 5-10)
Created: 2026-03-08
Author: architect-agent

## Overview

Design of six additional QA scenarios that probe dangerous concurrent edge cases in the parallel dispatch system. These scenarios target the lock serialization protocol, context merge strategy, failure coordination, and the guard rails that protect machine state consistency during genuine multi-worker execution.

## Erotetic Frame

- **X** = deep edge case QA scenarios for parallel dispatch
- **Q** = For each scenario: (1) What exactly to test? (2) What machine/actions are needed? (3) Is it testable with current QA infra? (4) What is the expected vs. buggy behavior?

---

## Scenario 5: Context Merge Conflict

### What Exactly To Test

Two regions write to the **same context key** during their entry actions. Since entry actions run WITHOUT a lock (step 6 in `ParallelRegionJob::handle()`), both regions snapshot context, mutate the same key, then compute independent diffs. When the lock is acquired and diffs are applied sequentially (steps 8-11), the **last writer wins** because `computeContextDiff` captures the full new value for scalar keys, and `apply context diff` (step 11) does a simple `set()` for non-array values.

**Expected behavior (verified from code):**

- For **scalar** context keys (string, int, bool): last-to-acquire-lock overwrites. The first region's value is silently lost. This is a **design-level data loss** scenario, not a crash -- but users may not realize it.
- For **array** context keys: `ArrayUtils::recursiveMerge` is used, so both regions' additions survive IF they use different array keys. If both regions write to the same nested key inside the array, last-writer-wins again.

**Potential bug surface:**

1. **Silent data loss on scalars**: Region A sets `shared_counter = 10`, Region B sets `shared_counter = 20`. Only one survives. No warning, no error, no merge conflict detection.
2. **Array merge with same nested key**: Region A sets `results.score = 85`, Region B sets `results.score = 92`. The `recursiveMerge` will pick whichever runs second under lock.
3. **Context diff includes stale reads**: Both regions snapshot the SAME `contextBefore` (since they read before their mutations). Both diffs will include the key. The second applier reads fresh state but its diff still says "change X from null to Y" -- the apply logic doesn't detect that X was already changed by the first applier.

**What to verify in QA:**
- Both regions write to `shared_key` with different values.
- After completion, `shared_key` contains exactly one region's value (deterministic = whichever acquired lock second).
- Log/output shows which region "won" and which was silently overwritten.
- Separately: test array merge where both regions add different keys to the same array -- both should survive.
- Separately: test array merge where both regions write the same nested key -- last writer wins.

### Machine Definition Needed

```
QAContextConflictMachine
├── processing (parallel, onDone → completed)
│   ├── region_a
│   │   ├── working_a (entry: WriteSharedKeyAAction)
│   │   │   on: REGION_A_DONE → finished_a
│   │   └── finished_a (final)
│   └── region_b
│       ├── working_b (entry: WriteSharedKeyBAction)
│       │   on: REGION_B_DONE → finished_b
│       └── finished_b (final)
├── completed (final)
└── error (final)

Context:
  shared_scalar: null       # Both regions write here (scalar conflict)
  shared_array: {}          # Both regions write here (array merge)
  region_a_wrote: null      # Proof A ran
  region_b_wrote: null      # Proof B ran
```

### Action Classes Needed

**WriteSharedKeyAAction**: 
- `sleep(3)` to ensure overlap
- `$context->set('shared_scalar', 'value_from_a')`
- `$context->set('shared_array', ['from_a' => true, 'score' => 85])`
- `$context->set('region_a_wrote', true)`
- `$this->raise(['type' => 'REGION_A_DONE'])`

**WriteSharedKeyBAction**:
- `sleep(3)` to ensure overlap
- `$context->set('shared_scalar', 'value_from_b')`
- `$context->set('shared_array', ['from_b' => true, 'score' => 92])`
- `$context->set('region_b_wrote', true)`
- `$this->raise(['type' => 'REGION_B_DONE'])`

### Testability

**Fully testable** with current QA infrastructure. This uses the same 2-region pattern as Scenario 1. The verification checks are:

```php
$checks = [
    'State = completed'                    => $state->id === '...completed',
    'Both regions executed'                => $ctx->get('region_a_wrote') && $ctx->get('region_b_wrote'),
    'Scalar = last-writer-wins'            => in_array($ctx->get('shared_scalar'), ['value_from_a', 'value_from_b']),
    'Array merge: both from_* keys exist'  => $ctx->get('shared_array')['from_a'] === true 
                                              && $ctx->get('shared_array')['from_b'] === true,
    'Array merge: score = last writer'     => in_array($ctx->get('shared_array')['score'], [85, 92]),
];
```

### Risk Assessment

**High relevance.** This scenario exposes a design gap: users with parallel regions that write to the same context keys will experience silent data loss. The system has no conflict detection or warning mechanism. This is not a bug per se (the diff/merge strategy is documented in code), but it is a dangerous footgun.

---

## Scenario 6: Rapid Successive Dispatches (Instance Isolation)

### What Exactly To Test

Dispatch Machine Instance A, wait 1 second, dispatch Machine Instance B using the **same machine class**. Both instances have different `root_event_id` values. This tests that:

1. Lock isolation is per `root_event_id` (not per machine class).
2. Queue jobs for Instance A do not interfere with Instance B.
3. Context from Instance A does not leak into Instance B.
4. Both instances reach `completed` with their own correct context values.

**Expected behavior (verified from code):**

- `MachineLockManager::acquire()` uses `root_event_id` as the lock's primary key. Two different machine instances have different root_event_ids, so their locks are completely independent.
- `ParallelRegionJob` stores `rootEventId` in its constructor -- each job is bound to its own instance.
- State restoration via `Machine::create(state: $rootEventId)` queries by `root_event_id`, so results are isolated.

**Potential bug surface:**

1. **Static state bleed**: If `MachineDefinition` or any static property leaks state between job executions on the same worker process, Instance B could see stale data from Instance A.
2. **Event queue contamination**: `MachineDefinition::$eventQueue` is an instance property, but if a worker reuses the same definition object, raised events from Instance A might persist into Instance B's processing.
3. **Lock cleanup timer (`$lastCleanupAt`)**: This is a **static** property. If cleanup fires during Instance A's processing, it sets `$lastCleanupAt`. Instance B's job on the same process will skip cleanup even if stale locks exist. This is by design (rate limiting) but could hide issues.

**What to verify in QA:**
- Both instances reach `completed` state.
- Context values are isolated (region PIDs, results, elapsed times are correct for each instance).
- No cross-contamination in `machine_events` table (each root_event_id has its own clean event chain).

### Machine Definition Needed

**No new machine class needed.** Reuse `QAConcurrentMachine`. The scenario is about dispatching two separate instances of the same class with a 1-second gap.

### Action Classes Needed

**No new actions needed.** Reuse `SlowRegionAAction` and `SlowRegionBAction`.

### Testability

**Fully testable** with current QA infrastructure. It is essentially a variant of Scenario 4 (stress test) but with explicit timing control:

```php
// Instance A
$machineA = QAConcurrentMachine::create();
$machineA->persist();
$rootA = $machineA->state->history->first()->root_event_id;
$machineA->dispatchPendingParallelJobs();

sleep(1); // 1s gap

// Instance B
$machineB = QAConcurrentMachine::create();
$machineB->persist();
$rootB = $machineB->state->history->first()->root_event_id;
$machineB->dispatchPendingParallelJobs();

// Wait, then verify both independently
```

Verification:
```php
$checks = [
    'Instance A completed'           => $restoredA->state->currentStateDefinition->id === '...completed',
    'Instance B completed'           => $restoredB->state->currentStateDefinition->id === '...completed',
    'Instance A has own results'     => $ctxA->get('region_a_result') === 'done_by_a',
    'Instance B has own results'     => $ctxB->get('region_a_result') === 'done_by_a',
    'Different root_event_ids'       => $rootA !== $rootB,
    'No event cross-contamination'   => MachineEvent::where('root_event_id', $rootA)->count() ===
                                        MachineEvent::where('root_event_id', $rootB)->count(),
];
```

### Risk Assessment

**Medium relevance.** Instance isolation is architecturally sound (keyed by root_event_id), but the static `$lastCleanupAt` and potential worker-process-level state reuse make this worth verifying empirically.

---

## Scenario 7: Long-Running Region with Lock Timeout

### What Exactly To Test

Region A's entry action takes 45 seconds. The lock timeout is 30 seconds (default `lock_timeout`), and lock TTL is 60 seconds (default `lock_ttl`). This probes two distinct timing boundaries:

1. **Entry action duration vs. job timeout**: Entry actions run WITHOUT a lock (step 6 in `ParallelRegionJob::handle()`). The action itself is not bounded by `lock_timeout` or `lock_ttl`. It is bounded only by `$timeout` on the job (default 300s). So a 45s action will complete fine.

2. **Lock acquisition timing**: After the 45s action completes, Region A tries to acquire the lock (step 8). If Region B already holds the lock, Region A will wait up to `lock_timeout` (30s). But Region B's lock should have been released long ago (Region B with 3s action would finish and release its lock in ~5s total). So no conflict.

3. **The real danger**: If Region B finishes first (at ~5s), acquires the lock, does its DB work, persists, and releases the lock, all is fine. Region A later (at ~45s) acquires the lock and proceeds. The interesting question: **does `areAllRegionsFinal()` behave correctly when called 40 seconds apart?**

**Expected behavior (verified from code):**

- Region B finishes at ~5s, acquires lock, applies diff, checks `areAllRegionsFinal()` → false (Region A is still at `working_a`). Persists. Releases lock.
- Region A finishes at ~45s, acquires lock, reloads fresh state from DB (step 9), applies diff, transitions via raised event, checks `areAllRegionsFinal()` → now true (both regions in final states). Fires `processParallelOnDone`. Persists.
- Machine transitions to `completed`.

**Potential bug surface:**

1. **Lock TTL expiry during lock HOLD**: If Region A somehow held the lock for more than 60s (TTL), the cleanup sweep could delete the lock row while Region A is still inside the `DB::transaction()`. This would mean another job could acquire the "same" lock. In practice, the transaction is fast (not 60s), so this is unlikely. But worth testing.
2. **Job timeout**: If `job_timeout` is set too low (e.g., 30s), the queue worker will kill Region A's job before it completes. The job will be retried (`$tries = 3`). After all retries fail, `failed()` fires. This is a configuration error, not a code bug -- but worth demonstrating.
3. **Stale lock from crashed worker**: If the worker process dies (OOM kill, SIGKILL) while holding a lock, the lock row remains until TTL expiry. Other regions' jobs will block up to `lock_timeout`. If TTL < lock_timeout, the cleanup sweep should eventually clear it.

**What to verify in QA:**

Two sub-scenarios:

**(7a) Long action, default config (job_timeout=300, lock_timeout=30, lock_ttl=60):**
- Region A sleeps 45s, Region B sleeps 3s.
- Both should complete, machine reaches `completed`.
- Total wall time ~47s (Region A 45s + lock/persist overhead).

**(7b) Long action, tight job_timeout (job_timeout=10):**
- Region A sleeps 45s but job is killed at 10s by queue worker.
- After 3 retries, `failed()` fires.
- Machine transitions to `error` via `onFail`.

### Machine Definition Needed

```
QALongRunningMachine
├── processing (parallel, onDone → completed, onFail → error)
│   ├── region_a
│   │   ├── working_a (entry: VerySlowRegionAction)  # 45s sleep
│   │   │   on: REGION_A_DONE → finished_a
│   │   └── finished_a (final)
│   └── region_b
│       ├── working_b (entry: SlowRegionBAction)  # reuse, 3s sleep
│       │   on: REGION_B_DONE → finished_b
│       └── finished_b (final)
├── completed (final)
└── error (final)

Context: same pattern as QAConcurrentMachine but with region_a timing fields
```

### Action Classes Needed

**VerySlowRegionAction**:
- `sleep(45)` or configurable via environment variable for flexibility
- Sets context keys and raises `REGION_A_DONE`

### Testability

**Testable but requires patience and config override.** 

- Scenario 7a needs `--wait=60` or higher.
- Scenario 7b needs a config override: either `MACHINE_PARALLEL_DISPATCH_JOB_TIMEOUT=10` or a custom job class with `$timeout = 10`. The config-based approach (`config('machine.parallel_dispatch.job_timeout')`) is already read in the `ParallelRegionJob` constructor, so setting the env var before dispatch is sufficient. However, it affects ALL parallel region jobs globally, so 7b should run in isolation (not alongside other scenarios).

**Infrastructure concern:** Scenario 7a takes ~50s minimum. This is slow for QA. Consider making the sleep duration configurable via context or env var (e.g., `QA_LONG_SLEEP=45`).

### Risk Assessment

**Medium-High relevance.** The 45s duration is realistic for long API calls (credit checks, document processing). The interaction between job timeout, lock TTL, and retry mechanics is exactly the kind of edge case that surfaces in production but not in unit tests.

---

## Scenario 8: Region Completes Entry Action but Raises No Event

### What Exactly To Test

A region's entry action sets context but does NOT call `$this->raise()`. The region stays in its initial state (`working_a`) forever. This tests:

1. Does `areAllRegionsFinal()` correctly return `false` when one region never transitions to its final state?
2. Does the machine remain stuck in the parallel state indefinitely?
3. Is the context diff still applied correctly even without a raised event?

**Expected behavior (verified from code):**

Looking at `ParallelRegionJob::handle()` steps 7-13:

- Step 7: `$raisedEvents` will be an empty array (nothing was raised).
- Step 11: Context diff IS applied (the action set context keys, the diff is non-empty).
- Step 12: `PARALLEL_REGION_ENTER` event is recorded (this always happens regardless of raised events).
- Step 13: The `foreach ($raisedEvents as $event)` loop simply doesn't execute. No transition happens.
- Step 14: `areAllRegionsFinal()` checks if the region's active state (`working_a`) is a final state. `working_a` is NOT final, so `areAllRegionsFinal()` returns `false`. No `processParallelOnDone`.
- Step 15: Persist happens (with the context changes and the PARALLEL_REGION_ENTER event).

The machine will be stuck with `state->value = ['...working_a', '...finished_b']` (assuming Region B completes normally). It will never reach `completed`.

**Potential bug surface:**

1. **No timeout/watchdog**: There is no mechanism to detect that a region is "stuck" (entry action completed but no event raised). The machine silently hangs. In production, this could mean orders stuck in `processing` with no alert.
2. **Context is applied but state doesn't advance**: The `PARALLEL_REGION_ENTER` event IS recorded, which might mislead operators into thinking the region completed. But the state value still shows `working_a`.
3. **Subsequent `Machine::send()` behavior**: If someone later sends an event to this stuck machine, the `send()` method checks `isInParallelState()` (true, since value has 2 elements). It tries to acquire the lock with `timeout: 0`. If no other job holds the lock, it succeeds. The event is then broadcast to all active states via `transitionParallelState()`. If the event matches a transition on `working_a`, the machine can recover. If not, `NoTransitionDefinitionFoundException` is thrown.

**What to verify in QA:**

- Region A sets context but doesn't raise.
- Region B completes normally (raises event, transitions to final).
- After wait period, machine is NOT in `completed` state.
- Machine value shows `[...working_a, ...finished_b]`.
- Context changes from Region A ARE persisted.
- `PARALLEL_REGION_ENTER` event for Region A IS in the event history.
- Machine is effectively "stuck" -- the parallel state cannot complete.

### Machine Definition Needed

```
QANoRaiseMachine
├── processing (parallel, onDone → completed, onFail → error)
│   ├── region_a
│   │   ├── working_a (entry: NoRaiseAction)  # Sets context, no raise()
│   │   │   on: REGION_A_DONE → finished_a
│   │   └── finished_a (final)
│   └── region_b
│       ├── working_b (entry: SlowRegionBAction)  # reuse, raises normally
│       │   on: REGION_B_DONE → finished_b
│       └── finished_b (final)
├── completed (final)
└── error (final)

Context:
  region_a_context_set: null   # Proof that entry action ran and context was applied
  region_b_result: null        # Standard Region B tracking
  ...
```

### Action Classes Needed

**NoRaiseAction**:
```php
public function __invoke(ContextManager $context): void
{
    sleep(2);
    $context->set('region_a_context_set', 'yes_but_no_raise');
    $context->set('region_a_pid', getmypid());
    // Deliberately: NO $this->raise() call
}
```

### Testability

**Fully testable** with current QA infrastructure. The verification is different from other scenarios -- instead of waiting for `completed`, we wait a fixed period and then check that the machine is NOT completed:

```php
sleep($wait); // e.g., 15s

$restored = QANoRaiseMachine::create(state: $rootEventId);
$checks = [
    'State != completed (stuck)'           => $restored->state->currentStateDefinition->id !== '...completed',
    'Region A context applied'             => $ctx->get('region_a_context_set') === 'yes_but_no_raise',
    'Region B completed normally'          => $ctx->get('region_b_result') === 'done_by_b',
    'Machine still in parallel'            => $restored->state->isInParallelState(),
    'Value contains working_a'             => in_array('...working_a', $restored->state->value),
    'Value contains finished_b'            => in_array('...finished_b', $restored->state->value),
];
```

**Important:** The `waitForCompletion()` helper will timeout (returns `false`), but that IS the expected behavior for this scenario. The scenario passes if the machine is stuck in the right way.

### Risk Assessment

**High relevance.** This is a realistic production scenario. A developer might write an entry action that conditionally raises an event (e.g., "if API returns success, raise DONE"). If the API returns a non-error but unexpected response, the raise is skipped, and the machine hangs silently. There is no built-in watchdog or stuck-detection mechanism.

---

## Scenario 9: Both Regions Fail

### What Exactly To Test

Both Region A and Region B throw exceptions during their entry actions. This tests the interaction between two concurrent `failed()` handlers:

1. Does `onFail` fire twice? Or does the first failure's `onFail` transition prevent the second?
2. What happens when two `failed()` handlers compete for the lock?
3. Is the final machine state consistent?

**Expected behavior (verified from code):**

Let's trace the execution carefully:

- Region A fails after 2s. After 3 retries (with 30s backoff by default, so total ~62s), `failed()` is called.
- Region B fails after 3s. After 3 retries (total ~63s), `failed()` is called.

Wait -- with `$tries = 3` and `$backoff = 30`, retries happen at: attempt 1 (immediate), attempt 2 (+30s), attempt 3 (+30s). So `failed()` fires ~62s after first dispatch for Region A.

**Actually, the important timing question is: do both fail at roughly the same time?**

With default config (`job_tries=3, job_backoff=30`):
- Region A: fail at 2s, retry at 32s (fail at 34s), retry at 64s (fail at 66s) → `failed()` at ~66s
- Region B: fail at 3s, retry at 33s (fail at 36s), retry at 63s (fail at 66s) → `failed()` at ~66s

Both `failed()` handlers fire at approximately the same time (~66s).

**Inside `failed()` (ParallelRegionJob lines 159-218):**

1. Acquire lock with `failLockTimeout = min(5, 30) = 5s`.
2. Reload machine from DB.
3. Guard: `isInParallelState()` — if still true, proceed.
4. Call `processParallelOnFail()` which transitions to `error` state.
5. Persist.
6. Release lock.

**Race between two `failed()` handlers:**

- **First to acquire lock**: Succeeds. Transitions machine to `error`. Persists. Releases lock. The machine is now in `error` state with `value = ['...error']`.
- **Second to acquire lock**: Reloads machine from DB. Calls `isInParallelState()` which checks `count($this->value) > 1`. Since the machine is now in `error` (single value), `isInParallelState()` returns `false`. The guard on line 174 triggers `return;`. The second `failed()` handler exits cleanly WITHOUT double-transitioning.

**This is correct behavior!** The double-guard pattern works.

**Potential bug surface:**

1. **Lock contention**: Both `failed()` handlers try to acquire with `timeout = 5s`. If the first holds the lock for less than 5s (likely -- DB transaction is fast), the second will acquire after waiting. If the first takes more than 5s, the second throws `MachineLockTimeoutException`, which is caught by the outer `try/catch` in `failed()` (line 209). It logs the error but doesn't crash.
2. **QA timing issue**: With `job_tries=3` and `job_backoff=30`, the scenario takes ~66 seconds to complete. This is very slow for QA. To make it practical, we need to override config: `job_tries=1` (fail immediately, no retries) or `job_backoff=1`.
3. **Edge case with `dispatchPendingParallelJobs()`**: After the first `failed()` persists and transitions to `error`, it calls `dispatchPendingParallelJobs()` in the `finally` block. If `processParallelOnFail` generated any pending dispatches (unlikely for a transition to a simple `error` final state), they would be dispatched. This is safe but worth verifying.

**What to verify in QA:**

- Both regions throw exceptions.
- Machine reaches `error` state (not `completed`, not stuck).
- `PARALLEL_FAIL` event appears in history exactly once (not twice).
- Error payload contains the exception details from whichever region's `failed()` ran first.
- No crash or unhandled exception from the second `failed()` handler.

### Machine Definition Needed

```
QABothFailMachine
├── processing (parallel, onDone → completed, onFail → error)
│   ├── region_a
│   │   ├── working_a (entry: FailAfter2sAction)  # reuse FailingRegionAction
│   │   │   on: REGION_A_DONE → finished_a
│   │   └── finished_a (final)
│   └── region_b
│       ├── working_b (entry: FailAfter3sAction)  # new: fails after 3s
│       │   on: REGION_B_DONE → finished_b
│       └── finished_b (final)
├── completed (final)
└── error (final)

Context:
  (minimal -- neither region succeeds)
```

### Action Classes Needed

**FailAfter3sAction** (new):
```php
public function __invoke(ContextManager $context): void
{
    sleep(3);
    throw new RuntimeException('Simulated failure in Region B after 3s');
}
```

Can reuse existing `FailingRegionAction` (fails after 2s) for Region A.

### Testability

**Testable but requires config override for practical timing.**

With default config (`tries=3, backoff=30`), the scenario takes ~70 seconds. For practical QA:
- Set `MACHINE_PARALLEL_DISPATCH_JOB_TRIES=1` to skip retries.
- Or set `MACHINE_PARALLEL_DISPATCH_JOB_BACKOFF=1` to minimize retry delay.

With `tries=1`: both `failed()` handlers fire at ~3-5s. Total scenario time: ~10s.

```php
$checks = [
    'State = error'                       => $state->id === '...error',
    'PARALLEL_FAIL event count = 1'       => $failEvents->count() === 1,
    'No double onFail transition'         => $events->filter(fn($t) => str_contains($t, 'parallel') && str_ends_with($t, '.fail'))->count() === 1,
    'Region A result null'                => $ctx->get('region_a_result') === null,
    'Region B result null'                => $ctx->get('region_b_result') === null,
];
```

### Risk Assessment

**High relevance.** Dual failure is a realistic scenario (e.g., shared external service goes down). The double-guard pattern in `failed()` appears sound based on code analysis, but empirical verification under real concurrency is essential. The `failLockTimeout = 5s` is short and could cause the second handler to fail to acquire -- this should be verified.

---

## Scenario 10: Machine::send() During Parallel Execution

### What Exactly To Test

While parallel region jobs are still running (entry actions in progress on queue workers), an external caller invokes `$machine->send('SOME_EVENT')` on the same machine instance. This tests:

1. The pre-lock guard in `Machine::send()` (lines 180-193).
2. Whether `MachineAlreadyRunningException` is thrown correctly.
3. Whether a sent event can be processed if timed between lock acquisitions.

**Expected behavior (verified from code):**

`Machine::send()` (lines 175-230):
1. If `parallel_dispatch.enabled` is true, it tries to acquire a lock with `timeout: 0` (immediate, no wait).
2. If the lock is already held by a `ParallelRegionJob`, `MachineLockTimeoutException` is caught and re-thrown as `MachineAlreadyRunningException`.
3. If the lock is NOT currently held (between region completions), the lock is acquired, and `send()` proceeds normally.

**Timing scenarios:**

**(10a) send() while region holds lock:**
- Region A acquires lock at ~5s (after entry action completes).
- External `send()` at ~5s → lock held → `MachineAlreadyRunningException`.
- This is the "fast rejection" path.

**(10b) send() while entry action is running (no lock held):**
- Region A is sleeping in entry action (no lock -- step 6 runs WITHOUT lock).
- External `send()` at ~2s → lock NOT held → lock acquired by `send()`.
- `send()` calls `transition()`, which calls `transitionParallelState()`.
- The event is broadcast to all active states.
- If the event matches a transition on `working_a` or `working_b`, the state transitions.
- This is **DANGEROUS**: the state could transition while entry actions are still running on queue workers!

**Potential bug surface:**

1. **State mutated under running entry actions**: If `send()` transitions `working_a → finished_a` while `ParallelRegionJob` for Region A is still running its entry action (sleep), when the job finishes and tries to acquire the lock, its guard check (step 10, line 104) will see that `freshRegionInitial` is no longer in `$freshMachine->state->value`. The double-guard protects against this -- the job will `return` without applying its diff. But the context changes from the entry action are LOST (they were computed but never applied).
2. **send() triggers onDone prematurely**: If `send()` transitions Region A to `finished_a`, and Region B is already in `finished_b` (e.g., Region B's fast entry action completed via job), then `areAllRegionsFinal()` returns true inside `transitionParallelState()`, and `processParallelOnDone` fires. The machine transitions to `completed` while Region A's entry action is still running in the background.
3. **send() during parallel state but event only matches one region**: The event is sent, one region transitions, state values update, but the other region's job is still running. The double-guard in the still-running job should catch this.

**What to verify in QA:**

**(10a) Rejection path:**
- Start machine, dispatch parallel jobs.
- Immediately (within 1s) try `$machine->send('SOME_EVENT')`.
- Expect either `MachineAlreadyRunningException` (if a job holds the lock) or the send succeeds (if no job has acquired the lock yet -- entry actions run lockless).

**(10b) Dangerous mutation path:**
- Start machine with 10s entry actions (slow enough to guarantee overlap).
- At ~2s, call `send()` with an event that transitions Region A.
- Verify: Does Region A's job detect the state change in its double-guard? Does it abort cleanly? Is context from Region A's entry action lost?

### Machine Definition Needed

**For 10a/10b, can reuse `QAConcurrentMachine`** but the `send()` call needs to happen from the QA command while workers are processing.

However, the tricky part: the QA command runs in a separate process from the queue workers. To send an event, the QA process needs to reconstruct the machine from the root_event_id:

```php
$machine = QAConcurrentMachine::create(state: $rootEventId);
$machine->send('EXTERNAL_EVENT');
```

The machine definition needs an additional event that can be sent externally. Current `QAConcurrentMachine` only defines `REGION_A_DONE` and `REGION_B_DONE` on the working states. We could add a transition on the parallel state itself, or define a new machine class.

```
QASendDuringParallelMachine
├── processing (parallel, onDone → completed, onFail → error)
│   ├── region_a
│   │   ├── working_a (entry: VerySlowAction [10s])
│   │   │   on: 
│   │   │     REGION_A_DONE → finished_a
│   │   │     FORCE_COMPLETE_A → finished_a  # External trigger
│   │   └── finished_a (final)
│   └── region_b
│       ├── working_b (entry: SlowRegionBAction [3s])
│       │   on: REGION_B_DONE → finished_b
│       └── finished_b (final)
├── completed (final)
└── error (final)
```

### Action Classes Needed

**VerySlowAction** (10s version): Same as `VerySlowRegionAction` from Scenario 7 but with 10s sleep.

Or better: make `SlowRegionAAction` configurable via env var for sleep duration.

### Testability

**Testable but requires careful orchestration.**

The QA command needs to:
1. Dispatch the machine.
2. Wait ~2s (enough for jobs to start but not finish).
3. Reconstruct machine from DB: `$machine = QASendDuringParallelMachine::create(state: $rootEventId);`
4. Call `$machine->send('FORCE_COMPLETE_A')` and catch `MachineAlreadyRunningException`.
5. Record whether the exception was thrown or the send succeeded.
6. Wait for final state.
7. Verify consistency.

```php
// 10a: Immediate send (likely to hit lock)
try {
    $machine = QASendDuringParallelMachine::create(state: $rootEventId);
    $machine->send('FORCE_COMPLETE_A');
    $sendResult = 'accepted';
} catch (MachineAlreadyRunningException $e) {
    $sendResult = 'rejected';
}

$checks = [
    'send() result is deterministic' => in_array($sendResult, ['accepted', 'rejected']),
    // If rejected: machine should complete normally via jobs
    // If accepted: verify state consistency
];
```

**Infrastructure concern:** Timing is non-deterministic. The test outcome depends on whether a worker has acquired the lock at the exact moment `send()` runs. This makes the test flaky. To make it reliable:

- **For rejection path (10a)**: Use a machine with very fast entry actions (1s) and send at ~1.5s when the lock is likely held.
- **For acceptance path (10b)**: Use a machine with very slow entry actions (30s) and send at ~2s when no lock is held (entry actions run lockless).

The acceptance path (10b) is the more dangerous one because it can mutate state under running jobs.

### Risk Assessment

**Very high relevance.** This is the most dangerous scenario of all six. The `Machine::send()` method acquires the lock with `timeout: 0`, but entry actions run lockless. This creates a window where `send()` can succeed and mutate state while jobs are still running their entry actions. The double-guard in `ParallelRegionJob` should protect against data corruption, but the entry action's context changes will be silently dropped. This is a real production scenario: an admin panel retries an event, or a webhook fires while parallel processing is ongoing.

---

## Summary Table

| Scenario | Test Target | New Machine? | New Actions? | Timing | Difficulty | Risk |
|----------|------------|-------------|-------------|--------|------------|------|
| 5: Context merge conflict | Context diff/merge for shared keys | Yes | Yes (2) | ~8s | Low | High |
| 6: Rapid successive dispatches | Instance isolation | No (reuse) | No (reuse) | ~10s | Low | Medium |
| 7: Long-running region | Lock TTL boundaries, job timeout | Yes | Yes (1) | ~50s / ~15s | Medium | Med-High |
| 8: No raise() | Stuck machine detection | Yes | Yes (1) | ~15s | Low | High |
| 9: Both regions fail | Dual failure handler coordination | Yes | Yes (1) | ~10s* | Medium | High |
| 10: send() during parallel | Lock guard, state mutation window | Yes | Yes (1)** | ~15s | High | Very High |

\* With `job_tries=1` override  
\** Can reuse with configurable sleep duration

## Recommended Execution Order

1. **Scenario 8** (no raise) -- simplest, no timing sensitivity, reveals design gap
2. **Scenario 5** (context conflict) -- simple setup, clear pass/fail criteria
3. **Scenario 6** (instance isolation) -- reuses existing code, validates fundamentals
4. **Scenario 9** (both fail) -- needs config override, tests failure coordination
5. **Scenario 7** (long-running) -- slow execution, tests timeout boundaries
6. **Scenario 10** (send during parallel) -- most complex, needs careful orchestration

## Open Questions

- [ ] Should context merge conflicts produce a warning/log? Currently silent.
- [ ] Should there be a configurable watchdog timeout for regions that never reach final state?
- [ ] Should `Machine::send()` refuse ALL events while `isInParallelState()` is true, not just when the lock is held?
- [ ] For Scenario 9, is `job_tries=1` acceptable for QA, or should we test with retries to verify backoff behavior?
- [ ] For Scenario 10b, is the silent loss of entry-action context changes acceptable, or should the system detect and warn?

## Key Code References

| File | Lines | Relevance |
|------|-------|-----------|
| `src/Jobs/ParallelRegionJob.php` | 65-76 | Context diff computation (Scenario 5) |
| `src/Jobs/ParallelRegionJob.php` | 83-88 | Lock acquisition with configurable timeout (Scenario 7) |
| `src/Jobs/ParallelRegionJob.php` | 99-106 | Double-guard pattern (Scenarios 8, 10) |
| `src/Jobs/ParallelRegionJob.php` | 110-119 | Context diff application with recursiveMerge (Scenario 5) |
| `src/Jobs/ParallelRegionJob.php` | 159-218 | `failed()` handler with lock contention (Scenario 9) |
| `src/Actor/Machine.php` | 175-230 | `send()` with pre-lock guard (Scenario 10) |
| `src/Actor/State.php` | 204-207 | `isInParallelState()` = `count(value) > 1` (Scenarios 8, 9, 10) |
| `src/Locks/MachineLockManager.php` | 33-91 | Lock acquisition with TTL and cleanup (Scenario 7) |
| `src/Definition/MachineDefinition.php` | 938-971 | `areAllRegionsFinal()` (Scenarios 8, 10) |
| `src/Definition/MachineDefinition.php` | 1014-1039 | `processParallelOnFail()` (Scenario 9) |
