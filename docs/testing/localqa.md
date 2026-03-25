# Real Infrastructure Testing

## When Unit Tests Aren't Enough

`Machine::test()` with `withoutPersistence()` covers behavior logic without a database. But some scenarios require real MySQL, Redis, and Horizon workers:

- **Async child delegation** — `ChildMachineJob` dispatched to queue, `ChildMachineCompletionJob` routes `@done` back to parent
- **Parallel dispatch** — `ParallelRegionJob` runs entry actions in separate queue jobs with lock coordination
- **Timer sweep** — `machine:process-timers` artisan command fires due timers via `Bus::batch`
- **Scheduled events** — `machine:process-scheduled` dispatches batch operations
- **Forward endpoints** — HTTP routing delivers events to running child machines
- **Lock contention** — concurrent `send()` calls on the same machine instance

## Prerequisites

| Service | Required | Why |
|---------|----------|-----|
| MySQL | Yes | `machine_events`, `machine_children`, `machine_current_states` tables |
| Redis | Yes | Queue driver for Horizon, cache for locks |
| Horizon | Yes | Real queue workers that process `ChildMachineJob`, `ParallelRegionJob` |

::: warning Not SQLite, Not Sync
LocalQA tests must use **real MySQL** (not SQLite) and **real Redis queue** (not sync driver). SQLite lacks JSON column support used by machine tables. Sync driver processes jobs inline, hiding real async behavior.
:::

## Laravel Project Setup

LocalQA tests run inside a real Laravel application (not the package's testbench). A few things need to be configured:

1. **Require the package** as a path repository so tests use your local copy
2. **Install Horizon** — `composer require laravel/horizon && php artisan horizon:install`
3. **Configure `.env`** — MySQL connection, `QUEUE_CONNECTION=redis`, `REDIS_PREFIX=laravel_database_`
4. **Publish migrations** — `php artisan vendor:publish --provider="Tarfinlabs\EventMachine\MachineServiceProvider"`
5. **Run migrations** — `php artisan migrate`
6. **Configure Horizon queues** — add `child-queue` to `config/horizon.php` queue array
7. **Set worker limits** — `maxProcesses=8`, `minProcesses=4` (prevents auto-scaling to 1 worker during test suite)
8. **Autoload test stubs** — add the package's `tests/` namespace to `composer.json` `autoload-dev` so Horizon can resolve test machine classes

::: tip
If you already have a Laravel project that uses EventMachine, you can skip steps 1-5 and run LocalQA tests directly against your existing database. Just ensure `QUEUE_CONNECTION=redis` and Horizon is configured.
:::

## Test Structure

Extend `LocalQATestCase` — the base class configures MySQL + Redis connections:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
});
```

### Key Helpers

| Method | Purpose |
|--------|---------|
| `cleanTables()` | Drains all Redis queues + truncates all machine tables. Use in `beforeEach`. |
| `waitFor(callback, timeout, description)` | Polls until callback returns `true` or timeout (default 45s). Uses exponential backoff (100ms→1s). Dumps diagnostics on timeout. |

## Rules

1. **Never fake** — `Bus::fake()`, `Queue::fake()`, `Machine::fake()`, `Mockery` are all forbidden. LocalQA tests must use real Horizon workers.
2. **Never sleep for positive assertions** — use `waitFor()` instead. `sleep()` is only acceptable for negative assertions (verifying something does NOT happen), and must be documented with a comment.
3. **Wait for context, not just fire records** — timer tests must wait for both `fire_count` AND context update (e.g., `retry_count`). The fire record is written by the sweep command, but the context update happens when Horizon processes the job — these are different timings.
4. **Use generous timeouts** — 60s minimum for `waitFor`, 90s for heavy concurrent tests. Horizon workers may be processing other tests' chain completions.
5. **Every test starts with `cleanTables()`** — drains Redis queues and truncates tables. Each test is isolated by unique `root_event_id`.
6. **Always add `$description`** to `waitFor()` — on timeout, the diagnostics dump shows queue state, last events, failed jobs. Without a description, debugging is blind.

## Diagnostics on Timeout

When `waitFor()` times out, it dumps a JSON diagnostic snapshot to STDERR:

```json
{
  "description": "every timer: waiting for fire_count>=1",
  "machine_events": 13,
  "last_5_events": ["state.retrying.enter", "transition.retrying.RETRY.finish", ...],
  "current_states": ["every_max.retrying"],
  "children": [],
  "locks": 0,
  "failed_jobs": ["MaxTransitionDepthExceededException..."],
  "queue:default:pending": 0,
  "queue:default:reserved": 1,
  "queue:child-queue:delayed": 2
}
```

This shows exactly **what happened** (last events), **where the machine is** (current states), **what's stuck** (queue sizes), and **why it failed** (exception messages).

## Example: Async Child Delegation

Test that a parent dispatches a child machine via queue and the completion job routes `@done`:

<!-- doctest-attr: no_run -->
```php
it('async child completes via Horizon', function (): void {
    $parent = OrderMachine::create();
    $parent->send(['type' => 'START_PAYMENT']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for Horizon to complete the child and route @done
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 60, description: 'async child: waiting for completed state');

    expect($completed)->toBeTrue('Child delegation not completed by Horizon');
});
```

## Example: Timer with Context Verification

When testing timers, wait for **both** the fire record AND the context update:

<!-- doctest-attr: no_run -->
```php
it('every timer fires and updates context', function (): void {
    $machine = EveryTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Backdate past timer interval
    DB::table('machine_current_states')
        ->where('root_event_id', $rootEventId)
        ->update(['state_entered_at' => now()->subDays(31)]);

    Artisan::call('machine:process-timers', ['--class' => EveryTimerMachine::class]);

    // Wait for BOTH fire_count AND context update
    $fired = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $fire = MachineTimerFire::where('root_event_id', $rootEventId)->first();
        if (!$fire || $fire->fire_count < 1) {
            return false;
        }

        // Also wait for Horizon to process the timer job
        $restored = EveryTimerMachine::create(state: $rootEventId);
        return $restored->state->context->get('billing_count') >= 1;
    }, timeoutSeconds: 60, description: 'every timer: fire_count + billing_count');

    expect($fired)->toBeTrue();
});
```

::: warning Common Mistake
Waiting only for `fire_count` is not enough — the fire record is written by the artisan command, but the machine context is updated by the Horizon job. These happen at different times.
:::

## Example: Negative Assertion (sleep)

When verifying something does **not** happen, `sleep()` is the only option:

<!-- doctest-attr: no_run -->
```php
it('fire-and-forget child does NOT send completion to parent', function (): void {
    $parent = FireAndForgetMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for child to finish running
    LocalQATestCase::waitFor(function () {
        return DB::table('machine_current_states')
            ->where('state_id', 'LIKE', '%child%')
            ->exists();
    }, timeoutSeconds: 60);

    // Negative assertion: verify NO completion job fires.
    // sleep required — cannot waitFor absence.
    sleep(1);

    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('processing'); // Parent unaffected
});
```

## Gotchas

| Issue | Fix |
|-------|-----|
| Jobs silently discarded | Check `.env` uses `DB_CONNECTION=mysql`, not `sqlite` |
| Redis prefix mismatch | Set `REDIS_PREFIX=laravel_database_` in both `.env` and `.env.testing` |
| Horizon only processes `default` queue | Add `child-queue` to `config/horizon.php` queue array |
| Old Horizon processes interfere | Run `pkill -9 -f horizon` before starting fresh |
| Horizon auto-scales to 1 worker | Set `minProcesses=4` in `config/horizon.php` |
| Tests timeout under load | Use 60s+ for all `waitFor`, 90s for concurrent tests |
| Timer test flaky | Wait for context update, not just fire_count |
| Wrong context key in assertion | Verify key name from machine stub (e.g., `retry_count` not `billing_count`) |
| `RestoringStateException` in logs | Harmless — orphan job from previous test found truncated table. Each test uses unique `root_event_id` |

## Running LocalQA Tests

<!-- doctest-attr: ignore -->
```bash
# Automated (recommended)
bash tests/LocalQA/run-qa.sh

# With filter
bash tests/LocalQA/run-qa.sh --filter="async delegation"

# Manual
pkill -9 -f horizon 2>/dev/null; sleep 1
redis-cli FLUSHALL
cd /path/to/qa-project && php artisan horizon &
sleep 5
cd /path/to/event-machine && vendor/bin/pest tests/LocalQA/
pkill -9 -f horizon
```

::: tip
LocalQA tests are excluded from `composer test`. Run them separately with `vendor/bin/pest tests/LocalQA/` or via `run-qa.sh`.
:::

## Related

- [Persistence Testing](/testing/persistence-testing) — DB-level assertions without real queues
- [Recipes](/testing/recipes#end-to-end) — E2E recipe patterns
- [Testing Overview](/testing/overview) — when fakes aren't enough
