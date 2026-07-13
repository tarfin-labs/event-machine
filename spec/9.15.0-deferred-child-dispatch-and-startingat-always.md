# Deferred Child Dispatch + startingAt @always Drain

Two DX fixes driven by real-world e2e feedback from the tarfin backend (browser
e2e tests running machines with `QUEUE_CONNECTION=sync`).

## Problem 1 — Silent child-job completion loss on sync queues

`handleJobInvoke()` / `handleAsyncMachineInvoke()` dispatched `ChildJobJob` /
`ChildMachineJob` mid-transition — inside `transition()`, before `Machine::send()`
reached `persist()`. On the sync driver the job ran inline, its
`ChildMachineCompletionJob` restored the parent from the DB at the
**pre-transition** state, failed the idempotency check
(`in_array($parentStateId, $state->value)`), and was silently discarded as
"already transitioned". The outer `persist()` then wrote the job state — the
machine hung in `child.X.start` forever. No exception, no warning, no timeout.

The `!shouldPersist` guard protected test mode only; real sync usage was
unprotected. `->afterCommit()` (already present on the async-machine path) did
not help: without an active DB transaction it dispatches immediately.

This was also a production race window: a fast queue worker could pick up the
job before the parent's persist committed.

### Decision

Not fail-fast ("job actors require an async queue") — making sync work is
strictly better: it enables full-pipeline e2e testing without Horizon. The
invariant: **no job that restores this machine from the DB may be dispatched
until the machine's current transition is persisted.**

### Implementation

- `MachineDefinition::$pendingChildDispatches` — buffer modeled on the existing
  `$pendingParallelDispatches` pattern. Filled (with `->afterCommit()` applied)
  instead of dispatching in:
  - `handleJobInvoke()` (`ChildJobJob`)
  - `handleAsyncMachineInvoke()` (`ChildMachineJob`, `ChildMachineTimeoutJob`)
  - `dispatchListenerJob()` (queued `ListenerJob`)
- `Machine::dispatchPendingChildJobs()` — flushes the buffer at the end of
  `Machine::persist()`, after the DB write. Buffer is cleared before dispatching
  (sync jobs can re-enter `persist()`).
- **Stale-instance reload:** after flushing on the sync driver, if the DB grew
  past the in-memory history (the inline chain advanced the machine), the
  instance reloads via `restoreStateFromRootEventId()`. Without this, a second
  `send()` on the same instance would fork a stale timeline with conflicting
  sequence numbers. The DB-growth check keeps `Queue::fake()` and
  transactional (`afterCommit`-deferred) paths untouched.
- Failure paths (`Machine::send()` finally, `ParallelRegionJob`) clear the
  buffer so failed transitions leave no stale dispatches behind.
- `->afterCommit()` on every buffered job keeps transactional events correct:
  inside `DB::transaction`, dispatch defers to commit (Laravel 11+ honors this
  on the sync driver too).

Out of scope (documented, unchanged): `dispatchTo` / `dispatchToParent` still
dispatch `SendToMachineJob` from inside actions (fire-and-forget contract;
behaviors have no machine reference for buffering).

## Problem 2 — `startingAt()` did not process eventless (@always) transitions

`startingAt('checking_info')` on a state with guarded `@always` routing parked
the machine there — a configuration the real machine can never rest in (the
drain only happened on the next `send()`). Auto-routing states were untestable
directly; consumers had to send artificial events.

### Decision

Drain by default, no new flag. A state whose `@always` guard passes is not a
restable configuration; `startingAt` should stabilize exactly like
`getInitialState()` does. The escape hatch already exists: pin the `@always`
guards false via the `guards:` parameter to park deliberately.

### Implementation

- `TestMachine::startingAt()` — after building the state (and before
  `trackStateEntry()`), runs the same `@always` check/`transition()` call as
  `getInitialState()`. Target's own entry actions stay skipped; actions on the
  `@always` transition and entry actions of drained-into states run (real
  semantics). Test-mode job invokes short-circuit as usual.
- `Machine::assertTransitions()` inherits the drain (rows boot via
  `startingAt`).

## Tests

- `tests/Features/DeferredChildDispatchTest.php` — sync-queue end-to-end job
  actor + async-machine completion (regression for the hang), same-instance
  reuse after inline chain, dispatch-deferred-until-persist, buffer cleared on
  failed transition.
- `tests/Features/TestMachineV2Test.php` — V54/V54b/V54c/V59/V74 updated to the
  new contract (drain, park via guards, drain runs transition actions).
- `tests/Features/JobActorTest.php`, `FireAndForgetMachineDelegationTest.php` —
  raw-definition dispatch assertions converted to `pendingChildDispatches`
  buffer assertions + `Queue::assertNothingPushed()` deferral proof.
