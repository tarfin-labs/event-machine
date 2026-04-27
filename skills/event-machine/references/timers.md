# Timers Cheat-Sheet (curated)

Synthesis of `docs/advanced/time-based-events.md`, `docs/best-practices/time-based-patterns.md`, and `docs/testing/time-based-testing.md`. Optimized for agent lookup.

## Core API

- `Timer::seconds(N)` / `minutes(N)` / `hours(N)` / `days(N)` / `weeks(N)` — duration value object
- `'after' => Timer::days(7)` — one-shot timer; fires once when state has been active that long
- `'every' => Timer::hours(1)` — recurring timer; fires repeatedly while state is active
- `'every' => ['interval' => Timer::hours(1), 'max' => 3, 'then' => 'ESCALATE']` — recurring with escalation cap

## Transition + timer combinations

```php
// after — deadline
'PAYMENT_TIMEOUT' => ['target' => 'cancelled', 'after' => Timer::days(7)],

// every — recurring action
'REMIND' => ['actions' => SendReminderAction::class, 'every' => Timer::days(1)],

// every with cap + escalation
'RETRY' => [
    'actions' => RetryPaymentAction::class,
    'every'   => ['interval' => Timer::minutes(30), 'max' => 3, 'then' => 'ESCALATE'],
],
```

## Sweep architecture

Timers are **NOT** delayed jobs. A scheduled `php artisan machine:process-timers` (auto-registered, runs every minute by default) sweeps `machine_current_states` for instances past their deadline and dispatches events.

Implications:
- Sub-minute intervals are not supported reliably (sweep granularity)
- Implicit cancel: when the machine leaves the state, the sweep simply doesn't find it anymore
- Idempotency required: sweeps may overlap on server restart; actions must be safe to run twice
- `machine_timer_fires` table dedups `after` timer fires (status='fired')

## CRITICAL: No sliding-window API

There is **no** `Timer::slidingOn()`, no `'internal' => false` flag, no action-level `resetStateTimer()` helper. By design, EventMachine self-loops preserve `state_entered_at` (Machine.php `syncCurrentStates` is diff-based — same state set produces no row update).

To express "deadline resets when event X arrives," **transition through a transit state**:

```php
// WRONG — self-loop does not reset state_entered_at, timer never re-arms
'awaiting' => [
    'on' => [
        'OFFER_UPDATED' => ['target' => 'awaiting', 'actions' => UpdateAction::class],  // ❌
        'EXPIRED'       => ['target' => 'expired', 'after' => Timer::days(7)],
    ],
],

// CORRECT — transit state exits and re-enters awaiting; row exchange refreshes state_entered_at
'awaiting' => [
    'on' => [
        'OFFER_UPDATED' => 'offer_received',
        'EXPIRED'       => ['target' => 'expired', 'after' => Timer::days(7)],
    ],
],
'offer_received' => [
    'entry' => UpdateAction::class,
    'on'    => ['@always' => 'awaiting'],
],
```

This is **not a workaround** — it is the idiomatic answer. Each new offer constitutes a new lifecycle; statecharts model lifecycle as state. The transit state:
- Self-documents the renewal moment in the state graph
- Produces a clean audit trail (`offer_received.enter` events)
- Generalizes if more behavior gets attached to the renewal
- Aligns with statechart theory (a meaningful event = a state transition)

When NOT to use: events that genuinely should NOT reset the lifecycle (idempotent ack, logging-only). For those, keep the self-loop — the diff-based persistence is correct.

## Decision rule for self-loop vs transit state

> **"Should an outside observer see this event in the audit log as a state transition?"**
> - Yes → use a transit state. Lifecycle reset is a feature.
> - No → use a self-loop. State is preserved, no audit noise.

## Anti-patterns

| # | Anti-pattern | Fix |
|---|--------------|-----|
| 1 | Sub-minute `every` interval for many instances | Use scheduled events (`schedules:` config) — one batch query, not per-instance polling |
| 2 | Non-idempotent timer action | Track sent-state via cache key or context flag — sweep may re-run |
| 3 | Self-loop to reset timer | Transit state pattern (above) |
| 4 | State without `after` timeout for external events | Add a `@timeout` or `after` transition — every wait state needs a max-wait |

## Testing timers

```php
OrderMachine::test()
    ->send('SUBMIT')
    ->assertHasTimer('ORDER_EXPIRED')
    ->advanceTimers(Timer::days(7))     // virtual time advance
    ->assertState('cancelled');
```

LocalQA pattern (real timers + Horizon): see `references/qa-setup.md`.

## See also

- `docs/advanced/time-based-events.md` — reference: Timer API, sweep architecture, configuration
- `docs/best-practices/time-based-patterns.md` — design patterns including renewable timers
- `docs/advanced/scheduled-events.md` — cron-driven batch operations (different from per-instance timers)
- `docs/testing/time-based-testing.md` — `advanceTimers()`, virtual time
- `docs/testing/scheduled-testing.md` — testing scheduled events
