# LocalQA Test Environment Setup

LocalQA tests run against **real MySQL + Redis + Horizon** (NOT SQLite/sync/Bus::fake).
They live in `tests/LocalQA/` and are excluded from `composer test`.

## Setup — Temporary Laravel Project

LocalQA requires a temporary Laravel project because:
- Package tests run via testbench (no artisan horizon)
- Horizon needs a real Laravel app with autoload for test stubs

### Quick Setup Script

```bash
# 1. Create project
cd /tmp && composer create-project laravel/laravel qa-v7-review
cd /tmp/qa-v7-review

# 2. Add package as path repo
python3 -c "
import json
with open('composer.json') as f:
    data = json.load(f)
data['repositories'] = [{'type': 'path', 'url': '/Users/deligoez/Developer/github/tarfin-labs/event-machine', 'options': {'symlink': True}}]
data['minimum-stability'] = 'dev'
data['prefer-stable'] = True
# Add test stubs to autoload so Horizon can resolve them
if 'autoload-dev' not in data: data['autoload-dev'] = {}
if 'psr-4' not in data['autoload-dev']: data['autoload-dev']['psr-4'] = {}
data['autoload-dev']['psr-4']['Tarfinlabs\\\\EventMachine\\\\Tests\\\\'] = 'vendor/tarfin-labs/event-machine/tests/'
with open('composer.json', 'w') as f:
    json.dump(data, f, indent=4)
"
composer require tarfin-labs/event-machine:@dev
composer require laravel/horizon
php artisan horizon:install

# 3. Fix .env (Horizon reads this!)
sed -i '' 's/DB_CONNECTION=sqlite/DB_CONNECTION=mysql/' .env
sed -i '' 's/# DB_HOST=127.0.0.1/DB_HOST=127.0.0.1/' .env
sed -i '' 's/# DB_PORT=3306/DB_PORT=3306/' .env
sed -i '' 's/# DB_DATABASE=laravel/DB_DATABASE=qa_event_machine_v7/' .env
sed -i '' 's/# DB_USERNAME=root/DB_USERNAME=root/' .env
sed -i '' 's/# DB_PASSWORD=/DB_PASSWORD=/' .env
sed -i '' 's/QUEUE_CONNECTION=database/QUEUE_CONNECTION=redis/' .env
echo 'REDIS_PREFIX=laravel_database_' >> .env

# 4. Fix .env.testing (test process reads this)
cat > .env.testing << 'ENVEOF'
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=qa_event_machine_v7
DB_USERNAME=root
DB_PASSWORD=
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PREFIX=laravel_database_
ENVEOF

# 5. Horizon config — listen to ALL queues, enough workers for full suite
# In config/horizon.php, change defaults:
#   'queue' => ['default', 'child-queue'],
#   'maxProcesses' => 8,
#   'minProcesses' => 4,    # prevent auto-scaling to 1 worker during test suite
#   'tries' => 3,

# 6. DB + migrations
mysql -u root -e "CREATE DATABASE IF NOT EXISTS qa_event_machine_v7;"
php artisan vendor:publish --provider="Tarfinlabs\EventMachine\MachineServiceProvider"
sed -i '' "s/json('machine_value')->index()/json('machine_value')/" database/migrations/*_create_machine_events_table.php
php artisan migrate:fresh
```

## Running Tests

```bash
# Option A: Automated (recommended)
bash tests/LocalQA/run-qa.sh

# Option B: Manual
pkill -9 -f horizon 2>/dev/null; sleep 1
cd /tmp/qa-v7-review && redis-cli FLUSHALL && php artisan horizon &
sleep 5
cd /path/to/event-machine && vendor/bin/pest tests/LocalQA/
pkill -9 -f horizon
```

## Critical Gotchas

| Problem | Cause | Fix |
|---------|-------|-----|
| Jobs silently discarded | `.env` still has `DB_CONNECTION=sqlite` | Fix `.env` to use mysql |
| "must exist and extend" error | Test stubs not in Horizon autoload | Add test namespace to QA app's `autoload-dev` |
| Jobs in Redis but not processed | Redis prefix mismatch | Set `REDIS_PREFIX=laravel_database_` in both `.env` and `.env.testing` |
| Horizon only processes `default` queue | `config/horizon.php` only lists `default` | Add `child-queue` to queue array |
| Multiple old Horizon instances | Previous sessions left orphans | `pkill -9 -f horizon` before starting |
| Horizon auto-scales to 1 worker | `minProcesses=1` default | Set `minProcesses=4` in `config/horizon.php` |
| waitFor timeout in full suite | default was 45s, too short under Horizon load | Default is now 60s. Use 90s for heavy tests (deep delegation, parallel chains) |
| Wrong context key in waitFor | Machine uses `retry_count` not `billing_count` | Always verify context key name from machine stub |

## Rules

1. **NEVER** use `Bus::fake()`, `Queue::fake()`, `Machine::fake()`, `Mockery`, sync queue, or in-memory SQLite in LocalQA tests
2. **ALWAYS** use `LocalQATestCase::cleanTables()` in `beforeEach` — drain queues + truncate, not `RefreshDatabase`
3. **ALWAYS** use `LocalQATestCase::waitFor()` for async assertions — poll DB with 60s+ timeout
4. **ALWAYS** wait for BOTH fire_count AND context update in timer tests — `fire_count` updates before Horizon processes the job
5. **ALWAYS** `redis-cli FLUSHALL` before test session (NOT between tests)
6. **ALWAYS** `pkill -9 -f horizon` before starting fresh Horizon
7. **ALWAYS** verify 3 things before running: MySQL OK, Redis PONG, Horizon workers running
8. **ALWAYS** run LocalQA tests in real environment after writing them — never skip this step
9. **ALWAYS** `pkill -9 -f horizon` after tests complete — never leave orphan Horizon processes
10. **NEVER** use `sleep()` except for negative assertions (verifying something does NOT happen). Document with comment why sleep is needed.

## Learned Patterns (from QA infrastructure overhaul)

### waitFor must check the FINAL observable state, not intermediate
Timer fire_count is written by the artisan command. Machine context is updated by the Horizon job. These are different processes with different timings. Always wait for the **end result** (context value) not just the intermediate record (fire_count).

### Quiet-period settlement is unnecessary
`cleanTables()` does NOT need to wait for idle workers. Each test uses a unique `root_event_id`. Orphan jobs from previous tests fail with `RestoringStateException` on truncated data — this is harmless and does not affect the new test.

### afterEach cleanup is counterproductive
Running `cleanTables()` in `afterEach` adds 500ms+ per test (quiet period wait). With 68 tests, this adds 34+ seconds. Since tests are already isolated by `root_event_id`, afterEach cleanup provides no benefit.

### Horizon minProcesses prevents flakiness
Auto-scaling can reduce workers to 1, causing queue backlog. Set `minProcesses=4` (minimum) for small suites, `minProcesses=8` for 80+ test suites.

### Negative assertions require sleep — document why
When verifying something does NOT happen (timer does NOT fire, parent does NOT receive completion), `waitFor` can't help — you can't wait for absence. Use `sleep(1)` with a comment explaining the negative assertion. Keep sleep duration minimal (1s).

### Diagnostics dump is essential for debugging
`waitFor` dumps queue sizes, last 5 events, current states, children status, failed job exceptions on every timeout. This turns "test failed" into "test failed because X" — always add a `$description` parameter.

### Use `run-qa.sh` for automated lifecycle
`bash tests/LocalQA/run-qa.sh` handles: preflight checks → kill orphan Horizon → FLUSHALL → start Horizon → run tests → cleanup. No manual steps needed.

### Parallel dispatch requires `should_persist` + idle→parallel transition
ParallelRegionJobs are only dispatched when entry actions exist AND the machine **transitions into** a parallel state. Starting directly in a parallel state (`initial => processing`) does NOT dispatch ParallelRegionJobs. Always:
1. Add `'should_persist' => true` to machine config
2. Start from `idle` state and transition via event (e.g., `START => processing`)
3. Ensure regions have entry actions (no-op actions are fine)

### Static counters in stub actions pollute Horizon workers
ThrowOnceAction (and similar stubs with `static` counters) persist across jobs in the same Horizon worker process. `beforeEach` resets in the test process, NOT in the worker. Fix: use **separate machine stubs** for tests that depend on specific counter states. Design actions to work regardless of counter value (e.g., ThrowOnceAction succeeds when counter != 1, which handles both fresh workers and accumulated runs).

### Concurrent queued listeners cause lost-update
When a single transition dispatches BOTH exit and transition ListenerJobs, they run concurrently. Each restores machine → modifies context → persists. The last to persist wins, losing the other's changes. Fix: use **separate machines** with only ONE listener type each (ListenerExitOnlyMachine, ListenerTransitionOnlyMachine).

### dispatchToParent is transient-only (not persisted)
`ContextManager::setMachineIdentity()` stores parent identity in `$internalParentRootEventId` — transient properties NOT in the data array. Parent identity is set AFTER `start()` in `ChildMachineJob`, so:
- Entry actions during initial `start()` CANNOT use `dispatchToParent`
- After child is persisted and restored via `SendToMachineJob`, parent identity is LOST
- `dispatchToParent` only works reliably from code that runs during `ChildMachineJob::handle()` AFTER `setMachineIdentity()` and BEFORE `persist()`

### Event type naming uses dot-notation, not enum names
Internal events use the pattern `{machine}.parallel.{placeholder}.region.timeout`, NOT `PARALLEL_REGION_TIMEOUT`. When querying `machine_events`, use `LIKE '%region.timeout%'` not `LIKE '%PARALLEL_REGION_TIMEOUT%'`.

### ChildMachineCompletionJob auto-restores archived parents
If a parent machine is archived while its child is still running, `ChildMachineCompletionJob` catches `RestoringStateException` and calls `ArchiveService::restoreMachine()` before retrying. Without this, child completion events for archived parents were silently discarded. Test pattern: create parent → delegate → archive parent → dispatch `ChildMachineCompletionJob` directly → verify parent auto-restores and reaches completed.

### Partial parallel failure: Region A context may be lost (last-writer-wins)
When Region A succeeds and Region B fails, Region A's context changes may be lost if Region B's `failed()` handler persists first using the dispatch-time snapshot. This is documented last-writer-wins behavior. In tests, don't assert Region A's context survives under partial failure — only assert the machine reaches `failed` state.

### ChildMachineCompletionJob propagates deep delegation chains
After fix: when ChildMachineCompletionJob routes @done/@fail and the parent reaches a final state, it checks if the parent is itself a managed child and dispatches another ChildMachineCompletionJob to the grandparent. This enables Parent→Child→Grandchild async chains.

### waitFor must check ACTUAL state, not event record
In parallel dispatch, `machine_events` may contain a transition event (e.g., `INVENTORY_CHECKED.finish`) but `machine_current_states` still shows the old state. This happens when concurrent `SendToMachineJob` or `ParallelRegionJob` overwrites the state after the transition was logged. Always wait for the **restored machine's state value array** or `MachineCurrentState`, not just event existence in `machine_events`.

### Concurrent SendToMachineJobs to same parallel machine cause lost-update
Two `SendToMachineJob`s dispatched simultaneously to the same parallel machine can cause one event's state change to be lost. Both jobs restore from the same base state → apply their event → persist. The last to persist overwrites the first's changes. In tests, always **wait for the first event to fully commit** (check `MachineCurrentState` or restored state) before dispatching the second event. `Machine::send()` now acquires a lock for ALL async queues (not just parallel_dispatch) — with `release(1)` retry on contention.

### Machine::send() lock strategy (Solution B)
`Machine::send()` acquires a lock when `queue.default !== 'sync' OR parallel_dispatch.enabled`. This covers:
- **Production (redis/database queue)**: always locked — prevents concurrent mutation
- **Unit tests (sync queue, parallel_dispatch=false)**: no lock — avoids re-entrant deadlocks
- **Unit tests (sync queue, parallel_dispatch=true)**: locked — tests can verify lock behavior
Re-entrant locking via `Machine::$heldLockIds` prevents deadlock in sync dispatch chains: `send() → ChildMachineJob → ChildMachineCompletionJob → send()` on same parent.

### Async forward endpoint responses do NOT contain child state
In v9, forwarded endpoint HTTP responses return the parent's envelope `{id, machineId, state, availableEvents, output}`. The child processes the forwarded event asynchronously via Horizon. The immediate response does NOT include child state/context.

**Pattern for testing forward endpoints in QA:**
1. Send forward request → assert 200
2. `waitFor()` child to process (restore child machine, check state)
3. Verify child context via restore, NOT from HTTP response

```php
// WRONG — child state not in immediate response
$response = $this->postJson("/api/forward/{$machineId}/provide-card", [...]);
$data = $response->json('data.child'); // ← NULL in async mode!

// CORRECT — wait for async processing, then verify via restore
$response->assertStatus(200);
$childUpdated = LocalQATestCase::waitFor(function () use ($machineId) {
    $child = MachineChild::where('parent_root_event_id', $machineId)->first();
    $childMachine = ChildMachine::create(state: $child->child_root_event_id);
    return str_contains(implode(',', $childMachine->state->value), 'expected_state');
}, timeoutSeconds: 30);
```

### MachineOutput must be serialized before queue dispatch
`resolveChildOutput()` can return a `MachineOutput` instance (not just array). Before passing to `ChildMachineCompletionJob`, always serialize:
```php
$resolved = MachineDefinition::resolveChildOutput($state, $context);
$outputData  = $resolved instanceof MachineOutput ? $resolved->toArray() : $resolved;
$outputClass = $resolved instanceof MachineOutput ? $resolved::class : null;
```
This applies in: `ChildMachineJob::handle()`, `MachineController::dispatchChildCompletionIfFinal()`, `MachineDefinition::tryForwardEventToChild()`, `ChildMachineCompletionJob::propagateChainCompletion()`.

### Failure propagation in deep delegation must carry upstream success flag
When `ChildMachineCompletionJob::propagateChainCompletion()` dispatches to the grandparent, it must pass the correct `success` flag. If the middle machine reached a `failed` final state (because its child failed), the propagation to the grandparent must use `success: false` — not always `true`.

### Horizon config for QA: minProcesses=4, maxProcesses=8, tries=3
Auto-scaling to 1 worker causes queue backlog. Set `minProcesses=4` minimum for reliable QA runs. `tries=3` ensures transient failures (lock contention) are retried.

### Job actors skip dispatch in test mode (shouldPersist=false)
In test mode, `handleJobInvoke()` returns early after recording `CHILD_MACHINE_START` — no `ChildJobJob` is dispatched. Without this guard, sync queue would run the job immediately → `@done` fires → next job state → cascade → memory crash. Use `simulateChildDone(JobClass::class)` to step through job states in unit tests. Same guard exists on `handleAsyncMachineInvoke()` for async child machines.

### QA tests must cover all Queue::fake()-masked patterns
Unit tests using `Queue::fake()` or `Machine::fake()` mask real async behavior. Every async pattern tested with fakes MUST have a corresponding QA test verifying the real Horizon pipeline. Key patterns to cover:
- Job actor dispatch + completion via `ChildJobJob` → `ChildMachineCompletionJob`
- Fire-and-forget job (target without @done) — parent transitions immediately
- Guarded @done on job actors — conditional routing after completion
- Mixed chains: machine delegation → @done → job actor → @done → completed
- Job @done → @always chain — transient routing state
- Chained job states — sequential job actors without cascade
- Concurrent events during job processing — lock serialization
- FailingTestJob → `ChildJobJob::failed()` → @fail routing (note: `failed_jobs` record expected)

### FailingTestJob creates failed_jobs records — this is expected
When `FailingTestJob` exhausts all retries, Laravel records a `failed_jobs` entry. This is SEPARATE from the machine-level `@fail` routing (handled by `ChildJobJob::failed()`). In QA stress tests with failing jobs, assert `failed_jobs <= N` (number of failing machines), not `failed_jobs == 0`.

### First QA run after Horizon start may have transient failures
The first full QA run immediately after `redis-cli FLUSHALL && php artisan horizon` may have 1-2 timing-sensitive test failures due to Horizon worker warm-up. Run the suite twice — if the second run passes, the first failures were transient. Design tests to tolerate 60s+ timeouts for heavy async chains.
