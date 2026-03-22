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
6. **Configure Horizon queues** — add `child-queue` to `config/horizon.php` queue array, set `maxProcesses` ≥ 3
7. **Autoload test stubs** — add the package's `tests/` namespace to `composer.json` `autoload-dev` so Horizon can resolve test machine classes

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
| `cleanTables()` | Truncates all machine tables + drains Redis queues. Use in `beforeEach`. |
| `waitFor(callback, timeout)` | Polls until callback returns `true` or timeout (default 30s). Use for async assertions. |

## Example: Async Child Delegation

Test that a parent dispatches a child machine via queue and the completion job routes `@done`:

<!-- doctest-attr: no_run -->
```php
it('async child completes via Horizon', function (): void {
    $parent = OrderMachine::create();
    $parent->send(['type' => 'START_PAYMENT']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Parent should be in delegating state (child dispatched to queue)
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('processing_payment');

    // Wait for Horizon to complete the child and route @done
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed');
    }, timeoutSeconds: 30);

    expect($completed)->toBeTrue('Child delegation not completed by Horizon');
});
```

## Example: Parallel Regions

Test that both parallel regions complete and `@done` fires:

<!-- doctest-attr: no_run -->
```php
it('parallel regions complete via Horizon', function (): void {
    $machine = ShippingMachine::create();
    $machine->send(['type' => 'START']);
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Wait for both warehouse + delivery regions to complete
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs !== null && str_contains($cs->state_id, '.shipped');
    }, timeoutSeconds: 45);

    expect($completed)->toBeTrue('Parallel regions did not complete');
});
```

## Gotchas

| Issue | Fix |
|-------|-----|
| Jobs silently discarded | Check `.env` uses `DB_CONNECTION=mysql`, not `sqlite` |
| Redis prefix mismatch | Set `REDIS_PREFIX=laravel_database_` in both `.env` and `.env.testing` |
| Horizon only processes `default` queue | Add `child-queue` to `config/horizon.php` queue array |
| Old Horizon processes interfere | Run `pkill -9 -f horizon` before starting fresh |
| Tests timeout at 30s | Increase `waitFor` timeout to 45s — Horizon startup can be slow |
| Jobs in Redis but not processed | Verify Horizon workers are running: `php artisan horizon:status` |

## Running LocalQA Tests

<!-- doctest-attr: ignore -->
```bash
# 1. Ensure services are running
mysql -u root -e "SELECT 1"
redis-cli PING

# 2. Flush Redis and start Horizon
redis-cli FLUSHALL
cd /path/to/qa-project && php artisan horizon &
sleep 5

# 3. Run tests from package directory
cd /path/to/event-machine
vendor/bin/pest tests/LocalQA/
```

::: tip
LocalQA tests are excluded from `composer test`. Run them separately with `vendor/bin/pest tests/LocalQA/`.
:::

## Related

- [Persistence Testing](/testing/persistence-testing) — DB-level assertions without real queues
- [Recipes](/testing/recipes#end-to-end) — E2E recipe patterns
- [Testing Overview](/testing/overview) — when fakes aren't enough
