# Time-Based Events

Time-based events let you define `after` (one-shot) and `every` (recurring) timers directly on transitions. Time is just another event source — the timer auto-triggers an event after a duration or at intervals while the machine stays in a state.

## Timer Value Object

Use the `Timer` class to define durations:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Support\Timer;

Timer::seconds(30)    // 30 seconds
Timer::minutes(5)     // 5 minutes
Timer::hours(6)       // 6 hours
Timer::days(7)        // 7 days
Timer::weeks(2)       // 2 weeks
```

Duration must be positive. Passing zero or a negative value throws `InvalidTimerDefinitionException`.

## `after` — One-Shot Timer

"If the machine stays in this state for N time, auto-trigger this event."

<!-- doctest-attr: ignore -->
```php
'awaiting_payment' => [
    'on' => [
        'PAY'           => 'processing',
        'ORDER_EXPIRED' => ['target' => 'cancelled', 'after' => Timer::days(7)],
    ],
],
```

After 7 days in `awaiting_payment`, the `ORDER_EXPIRED` event fires automatically. The machine transitions to `cancelled`. If the machine leaves `awaiting_payment` before 7 days (e.g., via `PAY`), the timer is implicitly cancelled.

One-shot: fires once per state entry. Tracked via `machine_timer_fires` table.

## `every` — Recurring Timer

"While in this state, auto-trigger this event every N time."

<!-- doctest-attr: ignore -->
```php
'active_subscription' => [
    'on' => [
        'BILLING' => ['actions' => 'processBillingAction', 'every' => Timer::days(30)],
        'CANCEL'  => 'cancelled',
    ],
],
```

Every 30 days, `BILLING` fires and runs the billing action. The machine stays in the same state. When the machine leaves (e.g., via `CANCEL`), the timer stops.

### `every` with `max` and `then`

<!-- doctest-attr: ignore -->
```php
'retrying_payment' => [
    'on' => [
        'RETRY_PAYMENT'   => ['actions' => 'retryPaymentAction', 'every' => Timer::hours(6), 'max' => 3, 'then' => 'MAX_RETRIES'],
        'MAX_RETRIES'     => 'payment_failed',
        'PAYMENT_SUCCESS' => 'paid',
    ],
],
```

After 3 fires, `MAX_RETRIES` is sent exactly once. The timer stops.

## All Transition + Timer Combinations

<!-- doctest-attr: ignore -->
```php
'on' => [
    // 1. Simple target (no timer)
    'PAY' => 'processing',

    // 2. Simple target + after
    'ORDER_EXPIRED' => ['target' => 'cancelled', 'after' => Timer::days(7)],

    // 3. Simple target + every
    'HEARTBEAT' => ['target' => 'checked', 'every' => Timer::hours(1)],

    // 4. Guarded single branch + after
    'ORDER_EXPIRED' => ['target' => 'cancelled', 'guards' => 'isNotPaidGuard', 'after' => Timer::days(7)],

    // 5. Guarded single branch + every
    'BILLING' => ['actions' => 'billingAction', 'guards' => 'isActiveGuard', 'every' => Timer::days(30)],

    // 6. Guarded multi-branch + after (mixed array)
    'ORDER_EXPIRED' => [
        ['target' => 'cancelled', 'guards' => 'isNotPaidGuard'],
        ['target' => 'late_payment'],
        'after' => Timer::days(7),
    ],

    // 7. Guarded multi-branch + every with max/then
    'RETRY' => [
        ['target' => 'paid', 'guards' => 'isPaymentSuccessGuard'],
        ['actions' => 'retryAction'],
        'every' => Timer::hours(6),
        'max'   => 3,
        'then'  => 'MAX_RETRIES',
    ],

    // 8. Actions only + after (no target, stays in state)
    'SEND_REMINDER' => ['actions' => 'sendReminderAction', 'after' => Timer::days(1)],

    // 9. Actions only + every (recurring action, stays in state)
    'CHECK_STATUS' => ['actions' => 'checkStatusAction', 'every' => Timer::hours(6)],

    // 10. Timer on delegation state (with @done/@fail/@timeout)
    // See Inter-Machine Integration section below
],
```

## Testing Timers

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Support\Timer;

OrderMachine::test()
    ->send('SUBMIT')
    ->assertState('awaiting_payment')
    ->assertHasTimer('ORDER_EXPIRED')
    ->advanceTimers(Timer::days(7))
    ->assertState('cancelled')
    ->assertTimerFired('ORDER_EXPIRED');
```

::: tip Full Testing Guide
For comprehensive timer testing patterns, see [Time-Based Testing](/testing/time-based-testing).
:::

::: warning Testing Timers
`advanceTimers()` works in-memory and is sufficient for most timer tests. To verify the `machine:process-timers` sweep command reads from DB and fires correctly, see [Recipe: Timer Sweep in Real Environment](/testing/recipes#recipe-timer-sweep-in-real-environment).
:::

## Architecture: Sweep, Not Delayed Jobs

Timer events are processed by a sweep command (`machine:process-timers`) that runs on a schedule via Laravel Scheduler.

### Why Not Delayed Jobs?

| Problem | Impact |
|---------|--------|
| Redis flush/restart | All delayed jobs lost |
| AWS SQS max 15min delay | 7-day delays impossible |
| Queue worker restart | Delayed jobs may be lost |

### Why Sweep?

| Advantage | Description |
|-----------|-------------|
| Survives restarts | Cron is independent of queue |
| No delay limit | Works for any duration |
| Self-healing | Missed timers caught on next sweep |
| Deployment-friendly | New timer configs automatically apply to existing instances |
| PHP-native | Cron is PHP's natural timer mechanism |

### Registration

Register timer sweeps in `routes/console.php` for each machine that uses `@after` or `@every` timers:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Scheduling\MachineTimer;

MachineTimer::register(OrderMachine::class);          // everyMinute (default)
MachineTimer::register(BillingMachine::class)
    ->everyFiveMinutes()                              // custom frequency
    ->environments(['production', 'staging']);
```

`register()` returns Laravel's `SchedulingEvent` for full fluent chaining (`->withoutOverlapping()` and `->runInBackground()` are applied by default).

### How It Works

1. You register each timer machine in `routes/console.php` via `MachineTimer::register()`
2. Laravel Scheduler runs `machine:process-timers --class=X` at the configured frequency
3. Sweep command queries `machine_current_states` table for instances past deadline
4. Dispatches `SendToMachineJob` for eligible instances via `Bus::batch`

### Implicit Cancel

Timers have no explicit cancel. When a machine leaves the state, the sweep simply won't find it anymore — natural cancellation.

### No Sliding-Window API — Use Transit States

There is no `Timer::slidingOn(...)` or "renewable" timer API. By design, self-loops do NOT reset `state_entered_at`, so a self-loop with an `after` timer keeps anchoring on the original entry. To reset a deadline on an event, transition through a transit state — see the [Renewable Timers pattern](/best-practices/time-based-patterns#renewable-timers-sliding-windows).

## Timer Configuration

Configure sweep behavior in `config/machine.php`:

<!-- doctest-attr: ignore -->
```php
'timers' => [
    'batch_size' => 100,                     // instances per query batch
    'backpressure_threshold' => 10000,       // skip sweep if queue exceeds
],
```

Sweep frequency is set per machine via `MachineTimer::register()` (default: `everyMinute`).

## Inter-Machine Integration

Timer transitions work naturally with machine delegation:

<!-- doctest-attr: ignore -->
```php
'awaiting_child' => [
    'machine' => PaymentMachine::class,
    '@done'   => 'completed',
    'on' => [
        'REMIND_CHILD' => ['actions' => 'nudgeChildAction', 'every' => Timer::hours(6)],
        'FORCE_CANCEL' => ['target' => 'timed_out', 'after' => Timer::days(7)],
    ],
],
```

- Every 6 hours: nudge action uses `dispatchTo()` to send event to child
- After 7 days: parent transitions to `timed_out`, child cancelled via `cleanupActiveChildren`

### @timeout Coexistence

`@timeout` (child deadline, delayed job) and `after`/`every` (state timers, sweep) serve different purposes and can coexist:

<!-- doctest-attr: ignore -->
```php
'processing' => [
    'machine'  => PaymentMachine::class,
    '@done'    => 'completed',
    '@timeout' => ['target' => 'child_timed_out', 'after' => 300],
    'on' => [
        'ALERT' => ['actions' => 'sendAlertAction', 'after' => Timer::days(1)],
    ],
],
```

## Guard Handling

Guards work exactly like standard guarded transitions. `after`/`every` fires once regardless of guard result:

<!-- doctest-attr: ignore -->
```php
'on' => [
    // Single-branch guarded
    'ORDER_EXPIRED' => ['target' => 'cancelled', 'guards' => 'isNotPaidGuard', 'after' => Timer::days(7)],

    // Multi-branch guarded (mixed array)
    'ORDER_EXPIRED' => [
        ['target' => 'cancelled', 'guards' => 'isNotPaidGuard'],
        ['target' => 'late_payment'],
        'after' => Timer::days(7),
    ],
],
```

## Artisan Commands

| Command | Description |
|---------|-------------|
| `machine:process-timers --class=X` | Run timer sweep for a machine class (`--class` is required) |
| `machine:timer-status` | Show timer status for all instances |

## Transition Key Reference

| Key | Type | Description |
|-----|------|-------------|
| `after` | `Timer` | Auto-trigger after duration (one-shot) |
| `every` | `Timer` | Auto-trigger at interval (recurring) |
| `max` | `int` | Max fire count (requires `every`) |
| `then` | `string` | Event type or EventBehavior FQCN after max reached |

## Operational Notes

### Missed Sweeps

If a timer sweep is missed (deployment, server restart, queue saturation triggering backpressure skip):

- **`after` timers:** Fire as soon as the next sweep runs after the deadline. No events are lost — the deadline check is absolute (`state_entered_at <= now - delay`).
- **`every` timers:** The next fire happens on the first sweep after `last_fired_at + interval`. The effective interval becomes `configured_interval + missed_sweep_duration`. There is **no catch-up mechanism** — missed intervals are not retroactively fired.

### Backpressure

Timer sweeps check queue size before processing. If the queue exceeds the configured threshold (`machine.timers.backpressure_threshold`, default: 10000), the sweep is skipped entirely to prevent queue saturation. Monitor the `Timer sweep skipped` warning in your logs.

### Infinite Loop Protection

Timer events are dispatched via queue (`Bus::batch`). Each queued job is a separate macrostep — the recursive transition depth counter resets for each job. If a timer event triggers an `@always` loop, only that specific job fails with `MaxTransitionDepthExceededException`. Other timer instances in the same batch are not affected.

## Related

- [Scheduled Events](/advanced/scheduled-events) — Cron-based batch operations targeting all matching instances (different scope than per-instance timers)
