# LocalQA Setup Cheat-Sheet (curated)

Condensed from `docs/testing/localqa.md` and `.claude/rules/localqa-setup.md`. LocalQA tests run against **real MySQL + Redis + Horizon** (NOT SQLite/sync/`Bus::fake`). They live in `tests/LocalQA/` and are **excluded from `composer test`**.

## When to write a LocalQA test

Unit tests using `Queue::fake()` / `Machine::fake()` mask real async behavior. Every async pattern tested with fakes **must** have a corresponding LocalQA test. Cover:

- Job actor dispatch + completion (`ChildJobJob` → `ChildMachineCompletionJob`)
- Fire-and-forget job (`queue:` with no `@done`)
- Guarded `@done` on job actors
- Mixed chains: machine delegation → `@done` → job actor → `@done` → completed
- Job `@done` → `@always` chain
- Concurrent events during job processing (lock serialization)
- Parallel dispatch with real queue workers

## Quickstart

```bash
# Option A: automated (recommended)
bash tests/LocalQA/run-qa.sh

# Option B: manual
pkill -9 -f horizon 2>/dev/null; sleep 1
cd /tmp/qa-v7-review && redis-cli FLUSHALL && php artisan horizon &
sleep 5
cd /path/to/event-machine && vendor/bin/pest tests/LocalQA/
pkill -9 -f horizon
```

## Temporary Laravel project setup

LocalQA needs a real Laravel app with autoload for test stubs (testbench/Horizon limitation). See `docs/testing/localqa.md` for the full creation script.

Key configuration points:

- `.env` — DB_CONNECTION=mysql, QUEUE_CONNECTION=redis, REDIS_PREFIX=laravel_database_
- `.env.testing` — same as `.env` (Horizon reads `.env`, test process reads `.env.testing`)
- `config/horizon.php` — `queue: ['default', 'child-queue']`, `minProcesses: 4`, `maxProcesses: 8`, `tries: 3`
- Add test stubs namespace to QA app's `autoload-dev` so Horizon can resolve them

## The 10 non-negotiable rules

1. **NEVER** use `Bus::fake()` / `Queue::fake()` / `Machine::fake()` / Mockery / sync queue / in-memory SQLite
2. **ALWAYS** use `LocalQATestCase::cleanTables()` in `beforeEach` — drain queues + truncate, not `RefreshDatabase`
3. **ALWAYS** use `LocalQATestCase::waitFor()` — poll DB, 60s+ timeout (90s for heavy tests)
4. **ALWAYS** wait for BOTH fire_count AND context update in timer tests — `fire_count` updates before Horizon processes the job
5. **ALWAYS** `redis-cli FLUSHALL` before a session (not between tests)
6. **ALWAYS** `pkill -9 -f horizon` before starting fresh Horizon
7. **ALWAYS** verify: MySQL OK, Redis PONG, Horizon workers running
8. **ALWAYS** run LocalQA tests in real environment after writing them
9. **ALWAYS** `pkill -9 -f horizon` after tests complete
10. **NEVER** use `sleep()` except for negative assertions (verifying something does NOT happen). Document why.

## waitFor usage

```php
$childDone = LocalQATestCase::waitFor(
    fn() => MachineCurrentState::where('root_event_id', $id)
        ->where('state_id', 'completed')->exists(),
    timeoutSeconds: 60,
    description: 'child reaches completed state',
);
```

`waitFor` dumps diagnostics on timeout: queue sizes, last 5 events, current states, children status, failed job exceptions. Add a `$description` — turns "test failed" into "test failed because X".

## Common troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Jobs silently discarded | `.env` still has `DB_CONNECTION=sqlite` | Fix `.env` to use mysql |
| "must exist and extend" error | Test stubs not in Horizon autoload | Add test namespace to QA app's `autoload-dev` |
| Jobs in Redis but not processed | Redis prefix mismatch | Set `REDIS_PREFIX=laravel_database_` in both `.env` and `.env.testing` |
| Only `default` queue processed | `config/horizon.php` missing `child-queue` | Add to queue array |
| Workers scale to 1 | `minProcesses=1` default | Set `minProcesses=4` |
| `waitFor` timeout in full suite | Under load default 45s too short | Bump to 60s+ / 90s for heavy tests |

## Test patterns

### Pattern: parallel dispatch
```php
// 1. Add 'should_persist' => true to machine config
// 2. Start from 'idle', transition via event (NOT initial=parallel)
// 3. Ensure regions have entry actions (no-op is fine)

$m = OrderMachine::create();
$m->send(['type' => 'START_PROCESSING']);

LocalQATestCase::waitFor(fn() =>
    MachineCurrentState::where('root_event_id', $m->id)
        ->where('state_id', 'fulfilled')->exists(),
    timeoutSeconds: 60,
);
```

### Pattern: deep delegation chain
```php
// Grandparent → parent → child
// Use timeoutSeconds: 90 — propagation crosses 3 queue hops
// Verify by restoring grandparent and checking its state
```

### Pattern: negative assertion (must sleep)
```php
// Verifying a timer does NOT fire
$m->send('CANCEL');
sleep(1);  // negative assertion: verifying ORDER_EXPIRED does NOT fire
expect(MachineEvent::where('type', 'ORDER_EXPIRED')->exists())->toBeFalse();
```

## Gotchas learned the hard way

- **Static counters in stubs pollute workers** — `ThrowOnceAction` and similar persist across jobs in same worker. `beforeEach` only resets test-process state, not worker state. Use separate machine stubs per counter-dependent test.
- **Concurrent queued listeners cause lost-update** — Exit + transition ListenerJobs run concurrently; last to persist wins. Separate machines per listener type.
- **First QA run after Horizon start may have 1-2 transient failures** — Horizon worker warm-up. Run twice.
- **Forward endpoint responses are async** — don't read `data.child` from the immediate HTTP response. `waitFor` child to process, then restore and verify.

## See also

- `docs/testing/localqa.md` — canonical reference
- `tests/LocalQA/README.md` — in-repo setup script
- `tests/LocalQA/run-qa.sh` — automated lifecycle runner
- `.claude/rules/localqa-setup.md` — project-internal detailed runbook
