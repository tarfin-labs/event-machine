# Race Condition Analysis: Machine::send() vs ParallelRegionJob::handle()

Created: 2026-03-08
Author: architect-agent

---

## 1. Component Summary

| Component | File | Role |
|-----------|------|------|
| `Machine::send()` | `src/Actor/Machine.php:175-230` | External event entry point |
| `ParallelRegionJob::handle()` | `src/Jobs/ParallelRegionJob.php:40-157` | Async region entry-action executor |
| `State::isInParallelState()` | `src/Actor/State.php:204-207` | Returns `count($this->value) > 1` |
| `MachineLockManager::acquire()` | `src/Locks/MachineLockManager.php:33-91` | Row-level lock via `machine_locks` table |
| `MachineAlreadyRunningException` | `src/Exceptions/MachineAlreadyRunningException.php` | Thrown when `send()` cannot acquire lock |

---

## 2. Exact Lock/Unlock Timeline

### 2.1  Machine::send() — Lock Lifecycle

```
send(event) called
│
├─ IF parallel_dispatch.enabled AND state exists:
│   ├─ MachineLockManager::acquire(timeout=0)     ← IMMEDIATE, fails if locked
│   │   └─ on failure → throw MachineAlreadyRunningException
│   └─ lockHandle obtained
│
├─ try {
│   ├─ definition->transition(event, state)        ← state mutation (in-memory)
│   ├─ persist()                                   ← DB write (upsert machine_events)
│   └─ handleValidationGuards()
│   └─ shouldDispatch = true
│ } finally {
│   ├─ lockHandle->release()                       ← LOCK RELEASED
│   └─ dispatchPendingParallelJobs()               ← jobs queued AFTER lock release
│ }
```

**Key observation:** `send()` uses `timeout=0`. If ANY lock is held (by a ParallelRegionJob or another `send()`), it throws `MachineAlreadyRunningException` immediately. It does NOT wait.

### 2.2  ParallelRegionJob::handle() — Lock Lifecycle

```
handle() called (queue worker process)
│
├─ Step 1: Reconstruct machine from DB (NO LOCK)
├─ Step 2: Guard: isInParallelState()? (NO LOCK)
├─ Step 3-4: Find region, check still at initial state (NO LOCK)
├─ Step 5: Snapshot context before (NO LOCK)
│
├─ Step 6: RUN ENTRY ACTIONS ← EXPENSIVE, NO LOCK HELD
│   (this is the entire point — actions run without holding a lock
│    so other regions can run concurrently)
│
├─ Step 7: Capture context diff + drained event queue (NO LOCK)
│
├─ Step 8: MachineLockManager::acquire(timeout=30)  ← BLOCKING, waits up to 30s
│   └─ lockHandle obtained
│
├─ try {
│   ├─ Step 9:  Reload FRESH state from DB
│   ├─ Step 10: Double-guard: isInParallelState()? region still initial?
│   │           └─ If NO → return (silent abort, context diff DISCARDED)
│   │
│   ├─ DB::transaction {
│   │   ├─ Step 11: Apply context diff to fresh state
│   │   ├─ Step 12: Record PARALLEL_REGION_ENTER event
│   │   ├─ Step 13: Process raised events (transitions)
│   │   ├─ Step 14: areAllRegionsFinal()? → processParallelOnDone()
│   │   └─ Step 15: persist()
│   │ }
│   └─ shouldDispatch = true
│ } finally {
│   ├─ lockHandle->release()                        ← LOCK RELEASED
│   └─ dispatchPendingParallelJobs()
│ }
```

---

## 3. The Race Condition Window

### 3.1 The Dangerous Window: Between Steps 6 and 8

```
Timeline (wall clock) →

ParallelRegionJob (Worker A)          Machine::send() (Web Request)
─────────────────────────────         ────────────────────────────
Step 1-5: reconstruct, guard
Step 6: RUN ENTRY ACTIONS  ←─── NO LOCK ───→
  (e.g., call external API,                   send() called
   compute result, 5-30s)                     │
                                              ├─ acquire(timeout=0)
                                              │   → NO LOCK EXISTS → SUCCESS
                                              │
                                              ├─ transition(event, state)
                                              │   (state read from DB does NOT
                                              │    reflect in-flight entry actions)
                                              │
                                              ├─ persist()
                                              │   (writes new state to DB)
                                              │
                                              └─ release lock
                                                  dispatch new jobs
Step 7: capture diff
Step 8: acquire(timeout=30)
  → SUCCESS (send's lock already released)
Step 9: reload from DB
  → sees send()'s mutation!
Step 10: double-guard
  → isInParallelState()? DEPENDS...
```

### 3.2 Scenario A — send() Transitions WITHIN the Parallel State

If the event triggers a transition within one of the active regions (e.g., `REGION_A_DONE` moves `region_a.working_a` → `region_a.done_a`):

- `send()` updates `state->value` from `[region_a.working_a, region_b.working_b]` to `[region_a.done_a, region_b.working_b]`
- `send()` persists this new state
- Worker A's Step 9 reloads: sees `[region_a.done_a, region_b.working_b]`
- Step 10 double-guard: `isInParallelState()` → true (count=2)
- BUT: `in_array($freshRegionInitial->id, $freshMachine->state->value)` checks if `region_a.working_a` is still in the value array
  - If Worker A was processing region_a → **FAILS** (region_a already moved to done_a)
  - If Worker A was processing region_b → **PASSES** (region_b still at initial)
- **Result:** Worker A's context diff for region_a is **silently discarded** even though the entry action completed and may have had side effects (API calls, etc.)

### 3.3 Scenario B — send() Transitions OUT of the Parallel State

If the event has a handler on the parallel parent or a global event that moves the machine to a completely different state:

- `send()` changes `state->value` from `[region_a.working_a, region_b.working_b]` to `[some_other_state]`
- Worker A's Step 10: `isInParallelState()` → false (count=1)
- **Result:** Worker A's entry action side effects are lost, context diff discarded silently

### 3.4 Scenario C — send() Triggers areAllRegionsFinal() Prematurely

If `send()` transitions the last non-final region to its final state while other regions' entry actions are still running:

- `send()` makes region_a final, region_b was already final
- `transitionParallelState()` at line 1345: `areAllRegionsFinal()` → **true**
- Calls `processParallelOnDone()` → exits parallel state, transitions to onDone target
- Worker B (still running region_b's entry actions) gets to Step 10:
  - `isInParallelState()` → false → **silently aborts**
- **Result:** onDone fires before all regions genuinely completed. The onDone target state's entry actions may depend on context from all regions, but region_b's context was never applied.

### 3.5 Scenario D — Context Diff Applied to Stale Base

Even when the double-guard passes (Worker A processes region_b, and send() only affected region_a's value but also mutated shared context keys):

- Worker A computed `contextDiff` against pre-lock snapshot
- Step 11 applies diff via deep merge to fresh state
- But the fresh state's context may have been modified by `send()` on overlapping keys
- Deep merge (`ArrayUtils::recursiveMerge`) will overwrite send()'s changes for scalar keys, or merge arrays
- **Result:** Last-writer-wins on scalar context keys. send()'s context mutations may be silently overwritten.

---

## 4. State Check Timing: Before vs After Lock

| Check | When | Lock Held? | Reliable? |
|-------|------|------------|-----------|
| `isInParallelState()` (Step 2) | Before entry actions | No | **No** — stale by the time lock is acquired |
| Region at initial state (Step 4) | Before entry actions | No | **No** — same reason |
| Context snapshot (Step 5) | Before entry actions | No | **No** — send() can change context meanwhile |
| `isInParallelState()` (Step 10) | After lock acquired | Yes | **Yes** — reads fresh from DB |
| Region at initial (Step 10) | After lock acquired | Yes | **Yes** — reads fresh from DB |
| Context diff application (Step 11) | Inside DB transaction | Yes | **Partially** — diff was computed pre-lock |

The pre-lock guards (Steps 2-4) are **optimization-only**: they prevent running expensive entry actions if the machine has already moved on. They are NOT safety guards. The real safety comes from the double-guard at Step 10.

---

## 5. What Happens to In-Flight Context Changes When Double-Guard Triggers

When the double-guard at Step 10 returns early:

```php
if (!$freshMachine->state->isInParallelState()) {
    return;  // ← context diff ($contextDiff) is DISCARDED
}
```

1. **Entry actions already ran** (Step 6) — their side effects (API calls, file writes, queue dispatches) are permanent and irrecoverable
2. **Context diff computed** (Step 7) but never applied — the in-memory `$contextDiff` array is garbage collected
3. **Raised events drained** (Step 7) from the event queue but never processed — the `$raisedEvents` array is garbage collected
4. **No logging, no exception, no event recorded** — the abort is completely silent

This is the most concerning aspect: **fire-and-forget side effects with silent context loss**.

---

## 6. Can send() Trigger onDone Prematurely?

**Yes, confirmed.** Here is the exact path:

1. Machine enters parallel state with regions A, B, C
2. ParallelRegionJobs dispatched for all three
3. Job A completes, marks region_a as final (via raised events in Step 13)
4. Job B completes, marks region_b as final
5. Before Job C runs its entry actions, user calls `send('FORCE_COMPLETE_C')`
6. `send()` acquires lock (timeout=0, succeeds because no job holds it at that moment)
7. `transition()` → `transitionParallelState()` → transitions region_c to its final state
8. Line 1345: `areAllRegionsFinal()` → true (all three regions now final)
9. Line 1346: `processParallelOnDone()` fires → machine exits parallel state → moves to onDone target
10. Job C's entry actions complete, arrives at Step 8, acquires lock
11. Step 10 double-guard: `isInParallelState()` → false → **silent return**

**The onDone handler executed without region_c's entry actions ever completing.** If region_c's entry action was "charge the customer's credit card" and it was processing when send() jumped ahead, the charge may or may not have gone through, and the context from that charge is permanently lost.

---

## 7. MachineAlreadyRunningException Usage

The exception is thrown in exactly one place:

```php
// Machine.php:190-192
} catch (MachineLockTimeoutException) {
    throw MachineAlreadyRunningException::build($rootEventId);
}
```

It is only thrown when:
- `parallel_dispatch.enabled` is `true` (line 180)
- The machine has existing state (line 180)
- Lock acquisition with `timeout=0` fails (line 184-188)

**Critical gap:** If no ParallelRegionJob currently holds the lock (they only hold it during Steps 8-15, which is the fast DB-write phase), `send()` will acquire the lock successfully even while entry actions are running. The lock does NOT protect the entry-action execution window.

---

## 8. Fix Options Evaluation

### Option A: Always Reject send() When isInParallelState()

**Implementation:**
```php
// In Machine::send(), before lock acquisition:
if ($this->state instanceof State && $this->state->isInParallelState()) {
    throw new MachineInParallelStateException($rootEventId);
}
```

| Dimension | Assessment |
|-----------|------------|
| **Complexity** | Trivial (3-5 lines) |
| **Backwards compatibility** | **BREAKING** — any existing code that sends events to transition between states within a parallel region will break. Sequential-mode parallel states (no dispatch) legitimately receive events via send(). |
| **User experience** | Poor — users cannot send events during parallel execution at all, even in sequential mode where it's safe |
| **Edge cases** | Breaks legitimate use cases: cross-region events, manual region completion, sequential-mode parallel transitions |

**Verdict: Reject.** Too broad. Sequential parallel execution legitimately uses `send()` to transition within regions.

---

### Option B: Reject send() Only When parallel_dispatch=enabled AND isInParallelState()

**Implementation:**
```php
// In Machine::send(), after the existing lock block (line 180-193):
if ($this->state instanceof State
    && config('machine.parallel_dispatch.enabled', false)
    && $this->state->isInParallelState()
) {
    throw MachineAlreadyRunningException::build(
        $this->state->history->first()->root_event_id
    );
}
```

| Dimension | Assessment |
|-----------|------------|
| **Complexity** | Trivial (5 lines, no new classes) |
| **Backwards compatibility** | Safe for sequential mode. Breaking for dispatch mode, but dispatch mode is new and this documents the intended contract. |
| **User experience** | Clear — "you cannot send events while parallel jobs are running." Users must wait for onDone or use the event queue within regions. |
| **Edge cases** | 1) What if all jobs completed but the machine is still technically in parallel state (waiting for onDone)? The lock would be free, isInParallelState() still true → rejected. But this is actually correct — the last job's Step 14 should have fired onDone. If it didn't, there's a bug. 2) What about events that should be valid during parallel execution (e.g., "CANCEL" on the parallel parent)? Those get blocked too. |

**Verdict: Strong candidate.** Simple, safe, documents the contract. The edge case about "CANCEL during parallel" is real but can be addressed by queueing (see Option D) or by allowing onFail-style abort events.

---

### Option C: Log Warning When Double-Guard Aborts with Non-Empty Context Diff

**Implementation:**
```php
// In ParallelRegionJob::handle(), Step 10:
if (!$freshMachine->state->isInParallelState()) {
    if ($contextDiff !== []) {
        Log::warning('ParallelRegionJob: context diff discarded (machine left parallel state)', [
            'root_event_id' => $this->rootEventId,
            'region_id'     => $this->regionId,
            'discarded_keys' => array_keys($contextDiff),
            'raised_events_count' => count($raisedEvents),
        ]);
    }
    return;
}
```

| Dimension | Assessment |
|-----------|------------|
| **Complexity** | Trivial (5-10 lines) |
| **Backwards compatibility** | Fully backwards compatible — no behavior change |
| **User experience** | Observability only — doesn't prevent the problem, just makes it visible |
| **Edge cases** | None — purely additive |

**Verdict: Necessary but insufficient.** Should be implemented regardless of which primary fix is chosen. This is a debugging and operational safety net.

---

### Option D: Queue the Event and Replay After Parallel Completion

**Implementation concept:**
```php
// In Machine::send():
if ($this->state instanceof State
    && config('machine.parallel_dispatch.enabled', false)
    && $this->state->isInParallelState()
) {
    // Store event in a new `pending_events` table or in machine_events with a special type
    PendingMachineEvent::create([
        'root_event_id' => $rootEventId,
        'event_type'    => $event->type,
        'payload'       => $event->payload,
        'queued_at'     => now(),
    ]);
    return $this->state; // Return current state, event will be replayed
}

// In ParallelRegionJob::handle(), after Step 14 (processParallelOnDone):
// After exiting parallel state, drain pending_events and replay them
$pendingEvents = PendingMachineEvent::where('root_event_id', $this->rootEventId)
    ->orderBy('queued_at')
    ->get();
foreach ($pendingEvents as $pending) {
    $freshMachine->state = $freshMachine->definition->transition(
        ['type' => $pending->event_type, 'payload' => $pending->payload],
        $freshMachine->state
    );
}
$pendingEvents->each->delete();
```

| Dimension | Assessment |
|-----------|------------|
| **Complexity** | **High** — new migration, new model, replay logic, ordering concerns, error handling for replay failures |
| **Backwards compatibility** | Fully compatible — send() still works, just deferred |
| **User experience** | Best UX — send() succeeds, event is eventually processed. But the return value is the CURRENT state, not the post-event state, which may confuse callers expecting immediate transition. |
| **Edge cases** | 1) Event may no longer be valid by replay time (state changed). 2) Ordering: multiple queued events need ordering guarantees. 3) The onDone transition itself might make the queued event invalid. 4) Caller expects the returned State to reflect the transition — it won't. 5) What if the event is transactional? The transaction boundary is lost. |

**Verdict: Overengineered for now.** The complexity is substantial and the semantic mismatch (send returns stale state) creates new problems. Could be a v2 enhancement.

---

### Option E: Add a "parallel_running" Flag to machine_locks

**Implementation concept:**
```php
// When entering parallel dispatch:
// Machine::send() persists, then dispatches jobs.
// Before dispatching, write a "parallel_running" marker:
MachineStateLock::create([
    'root_event_id' => $rootEventId,
    'owner_id'      => 'parallel_sentinel',
    'acquired_at'   => now(),
    'expires_at'    => now()->addSeconds(config('machine.parallel_dispatch.sentinel_ttl', 3600)),
    'context'       => 'parallel_sentinel',
]);

// ParallelRegionJob removes the sentinel when the LAST region completes:
// After processParallelOnDone(), delete the sentinel.

// Machine::send() checks for sentinel:
if (MachineStateLock::where('root_event_id', $rootEventId)
    ->where('context', 'parallel_sentinel')->exists()) {
    throw MachineAlreadyRunningException::build($rootEventId);
}
```

| Dimension | Assessment |
|-----------|------------|
| **Complexity** | Medium — uses existing table, but needs sentinel lifecycle management (create on dispatch, delete on completion/failure, handle TTL expiry) |
| **Backwards compatibility** | Fully compatible — existing lock table reused |
| **User experience** | Good — clear signal that parallel execution is in progress. But sentinel could become orphaned if all jobs fail silently. |
| **Edge cases** | 1) Sentinel orphaning: if all jobs fail and the failed() handler also fails, sentinel stays forever until TTL expires. 2) The existing lock uses `root_event_id` as primary key — a sentinel and a real lock cannot coexist for the same machine. Would need schema change or separate table. 3) TTL-based cleanup is imprecise. |

**Primary blocker:** The `machine_locks` table has `root_event_id` as primary key. You cannot insert both a sentinel row and a real job lock for the same root_event_id. This would require either a schema change (composite PK) or a separate table.

**Verdict: Workable but requires schema change.** More robust than Option B in theory, but the implementation cost is higher and the sentinel lifecycle has its own failure modes.

---

## 9. Recommended Approach

**Primary fix: Option B + Option C combined.**

### Rationale

1. **Option B** is the right semantic contract: when `parallel_dispatch` is enabled, the system has chosen async execution. External events during that window are a programming error or a race condition — they should fail fast, not silently corrupt state.

2. **Option C** makes the existing double-guard abort visible for debugging. Even with Option B preventing send(), there are still timing windows (e.g., between job dispatch and job pickup) where understanding what happened is critical.

3. **Option B is reversible.** If users later need "CANCEL during parallel" semantics, it can be evolved into Option D (queue+replay) or into a special `sendOrQueue()` method. Starting with a strict reject is safer than starting permissive and trying to tighten later.

### Detailed Implementation Plan

#### Phase 1: Guard in send() (Option B)

**File:** `src/Actor/Machine.php`

Add after line 193 (after the lock acquisition block):

```php
// Reject external events while parallel regions are dispatched.
// When parallel_dispatch is enabled, entry actions run in background jobs.
// Allowing send() to mutate state during this window would cause:
// - Silent context loss (double-guard discards in-flight work)
// - Premature onDone (areAllRegionsFinal triggered before actions complete)
// - Context overwrites (last-writer-wins on shared keys)
if ($this->state instanceof State
    && config('machine.parallel_dispatch.enabled', false)
    && $this->state->isInParallelState()
) {
    $rootEventId = $this->state->history->first()->root_event_id;
    throw MachineAlreadyRunningException::build($rootEventId);
}
```

**Note:** This check runs AFTER lock release (line 193 releases the lock from the first block). Actually, looking more carefully: the lock acquisition at lines 180-193 would succeed if no job holds it, and then the check at the new location would throw, but the lock would need to be released. The check should go BEFORE the lock acquisition, or the lock should be released in the exception path.

**Corrected placement:** Insert BEFORE line 195 but AFTER the lock block. Actually, the simplest approach: place it as the very first check in `send()`, before any lock logic:

```php
public function send(EventBehavior|array|string $event): State {
    // Reject external events while parallel dispatch is in progress
    if ($this->state instanceof State
        && config('machine.parallel_dispatch.enabled', false)
        && $this->state->isInParallelState()
    ) {
        $rootEventId = $this->state->history->first()->root_event_id;
        throw MachineAlreadyRunningException::build($rootEventId);
    }

    $lockHandle = null;
    // ... rest of existing code
}
```

This is safe because:
- `isInParallelState()` checks `count($this->value) > 1` — purely in-memory, no DB access needed
- The state was loaded at machine reconstruction time, so it reflects the last persisted state
- No lock is held yet, so no cleanup needed on throw

#### Phase 2: Observability in double-guard abort (Option C)

**File:** `src/Jobs/ParallelRegionJob.php`

In `handle()`, modify the Step 10 guard returns:

```php
// Step 10 - first guard
if (!$freshMachine->state->isInParallelState()) {
    if ($contextDiff !== [] || $raisedEvents !== []) {
        Log::warning('[EventMachine] ParallelRegionJob: double-guard abort — machine left parallel state', [
            'root_event_id'       => $this->rootEventId,
            'region_id'           => $this->regionId,
            'discarded_context'   => array_keys($contextDiff),
            'discarded_events'    => count($raisedEvents),
        ]);
    }
    return;
}

// Step 10 - second guard
if ($freshRegionInitial === null || !in_array($freshRegionInitial->id, $freshMachine->state->value, true)) {
    if ($contextDiff !== [] || $raisedEvents !== []) {
        Log::warning('[EventMachine] ParallelRegionJob: double-guard abort — region no longer at initial state', [
            'root_event_id'       => $this->rootEventId,
            'region_id'           => $this->regionId,
            'initial_state_id'    => $this->initialStateId,
            'current_values'      => $freshMachine->state->value,
            'discarded_context'   => array_keys($contextDiff),
            'discarded_events'    => count($raisedEvents),
        ]);
    }
    return;
}
```

#### Phase 3: Tests

**New test file:** `tests/Features/ParallelStates/ParallelDispatchSendRejectionTest.php`

Test cases:
1. `send() throws MachineAlreadyRunningException when parallel_dispatch enabled and machine is in parallel state`
2. `send() works normally when parallel_dispatch disabled and machine is in parallel state (sequential mode)`
3. `send() works normally when parallel_dispatch enabled but machine is NOT in parallel state`
4. `send() works normally after parallel completion (machine exited parallel state via onDone)`

---

## 10. Open Questions

- [ ] Should there be a way to send a "CANCEL" event during parallel execution? If so, Option B needs an escape hatch (e.g., `$event->bypassParallelGuard` flag or a separate `abort()` method).
- [ ] Should the double-guard abort emit a `PARALLEL_REGION_ABORT` internal event for audit trail, even though it cannot persist (the machine may have moved to a non-parallel state)?
- [ ] Is the `isInParallelState()` check (`count > 1`) reliable for the transition moment between "last job completes onDone" and "machine persists new non-parallel state"? In theory yes, because the last job holds the lock during persist, but worth verifying.
- [ ] Should MachineAlreadyRunningException carry more context (e.g., which regions are still running, how long they've been running)?

---

## 11. Success Criteria

1. `send()` throws `MachineAlreadyRunningException` when `parallel_dispatch.enabled=true` and machine is in a parallel state
2. `send()` continues to work in sequential mode (parallel_dispatch disabled)
3. Double-guard aborts are logged with discarded context keys
4. All existing tests pass unchanged
5. New tests cover the four scenarios listed in Phase 3
