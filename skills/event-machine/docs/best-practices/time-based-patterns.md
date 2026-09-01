# Time-Based Patterns

Time is just another event source in EventMachine. The `after` and `every` keys on transitions let you define deadlines and recurring actions directly in your machine definition, without managing delayed jobs or cron entries manually.

## Core Patterns

### `after` -- Deadlines

"If nothing happens within N time, fire this event."

```php ignore
'awaiting_payment' => [
    'on' => [
        'PAYMENT_RECEIVED' => 'processing',
        'ORDER_EXPIRED'    => ['target' => 'cancelled', 'after' => Timer::days(7)],
    ],
],
```

Use `after` for deadlines and timeouts. If the machine leaves `awaiting_payment` before 7 days (via `PAYMENT_RECEIVED`), the timer is implicitly cancelled -- there is no explicit cancel needed.

### `every` -- Recurring Actions

"While in this state, fire this event every N time."

```php ignore
'active_subscription' => [
    'on' => [
        'BILLING'          => ['actions' => 'processBillingAction', 'every' => Timer::days(30)],
        'SUBSCRIPTION_CANCELLED' => 'cancelled',
    ],
],
```

Use `every` for recurring work like billing cycles, health checks, or periodic notifications. The timer stops when the machine leaves the state.

### `max` + `then` -- Escalation

"Retry N times, then escalate."

```php ignore
'retrying_payment' => [
    'on' => [
        'PAYMENT_RETRY_REQUESTED'    => [
            'actions' => 'retryPaymentAction',
            'every'   => Timer::hours(6),
            'max'     => 3,
            'then'    => 'MAX_RETRIES',
        ],
        'MAX_RETRIES'      => 'awaiting_manual_review',
        'PAYMENT_RECEIVED' => 'paid',
    ],
],
```

After 3 fires, `MAX_RETRIES` is sent exactly once and the recurring timer stops. This is the standard pattern for retry-with-escalation.

## Anti-Pattern: Timer as Polling

```php ignore
// Anti-pattern: using every to poll a database

'checking_status' => [
    'on' => [
        'CHECK_STATUS' => [
            'actions' => 'queryDatabaseForStatusAction',  // polls DB every minute
            'every'   => Timer::minutes(1),
        ],
    ],
],
```

Timers run per machine _instance_. With 10,000 active instances, this is 10,000 database queries per minute. The sweep command batches them, but the underlying work does not scale.

**Fix:** Use scheduled events for batch queries. A single scheduled event runs one query that finds all instances needing attention, rather than each instance polling independently.

```php ignore
// Scheduled event: one query for all instances
'schedules' => [
    'CHECK_EXPIRED' => [
        'cron'     => '* * * * *',
        'resolver' => ExpiredOrdersResolver::class,
    ],
],
```

## Anti-Pattern: Very Short Intervals

```php ignore
// Anti-pattern: sub-minute interval

'monitoring' => [
    'on' => [
        'HEALTH_CHECK' => [
            'actions' => 'checkHealthAction',
            'every'   => Timer::seconds(10),   // 6 fires per minute per instance
        ],
    ],
],
```

The sweep command runs on a cron schedule (default: every minute). A 10-second interval means the sweep catches up on missed fires, potentially dispatching multiple events at once. With many instances, this creates queue backpressure.

**Fix:** Keep intervals at 1 minute or longer. For sub-minute requirements, use a dedicated monitoring tool outside the state machine.

## Anti-Pattern: Non-Idempotent Timer Action

Timer events are processed by a sweep command. If a sweep runs twice (server restart, overlapping cron), the action may execute again for the same timer fire. The `machine_timer_fires` table provides deduplication for `after` timers, but your action should still be safe to run twice.

```php no_run
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\ContextManager;

// Anti-pattern: non-idempotent timer action

class SendPaymentReminderAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // BAD: sends duplicate email on retry
        Mail::to($context->get('customerEmail'))
            ->send(new PaymentReminderMail($context->get('orderId')));
    }
}
```

**Fix:** Track whether the reminder was already sent.

```php no_run
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\ContextManager;

// Idempotent: checks before sending

class SendPaymentReminderAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $reminderKey = 'reminder_sent_' . $context->get('orderId');

        if (cache()->has($reminderKey)) {
            return;  // already sent
        }

        Mail::to($context->get('customerEmail'))
            ->send(new PaymentReminderMail($context->get('orderId')));

        cache()->put($reminderKey, true, now()->addDay());
    }
}
```

## Example: Payment Retry Escalation

A complete pattern combining `after`, `every`, `max`, and `then`:

```php ignore
'id'      => 'order_workflow',
'initial' => 'awaiting_payment',
'context' => [
    'orderId'    => null,
    'orderTotal' => 0,
    'retryCount' => 0,
],
'states' => [
    'awaiting_payment' => [
        'on' => [
            'PAYMENT_RECEIVED' => 'paid',
            'SEND_REMINDER'    => [
                'actions' => 'sendPaymentReminderAction',
                'after'   => Timer::days(1),                // reminder after 1 day
            ],
            'ORDER_EXPIRED' => [
                'target' => 'expired',
                'after'  => Timer::days(7),                 // expire after 7 days
            ],
        ],
    ],
    'paid' => [
        'on' => ['PROCESSING_STARTED' => 'processing'],
    ],
    'processing' => [
        'on' => [
            'PAYMENT_CONFIRMED' => 'completed',
            'PAYMENT_FAILED'    => 'retrying_payment',
        ],
    ],
    'retrying_payment' => [
        'on' => [
            'PAYMENT_RETRY_REQUESTED' => [
                'actions' => 'retryPaymentAction',
                'every'   => Timer::hours(6),               // retry every 6 hours
                'max'     => 3,                              // up to 3 times
                'then'    => 'MAX_RETRIES',                  // then escalate
            ],
            'MAX_RETRIES'      => 'awaiting_manual_review',
            'PAYMENT_RECEIVED' => 'paid',
        ],
    ],
    'awaiting_manual_review' => [
        'on' => [
            'MANUAL_RESOLUTION' => [
                ['target' => 'paid',      'guards' => 'isManuallyApprovedGuard'],
                ['target' => 'cancelled'],
            ],
            'REVIEW_EXPIRED' => [
                'target' => 'cancelled',
                'after'  => Timer::days(30),                // final deadline
            ],
        ],
    ],
    'completed' => ['type' => 'final'],
    'expired'   => ['type' => 'final'],
    'cancelled' => ['type' => 'final'],
],
```

The flow: wait for payment (remind after 1 day, expire after 7 days) -> if payment fails, retry 3 times over 18 hours -> escalate to human review (with a 30-day final deadline).

## Timer as Reliability Guard

Every state that waits for an external response -- a webhook, an API callback, a user action -- **must** have an `after` timeout. This is not a convenience feature. It is a reliability requirement. Without a timer, the machine can hang indefinitely in a state that no event will ever resolve.

### Anti-Pattern: Waiting Without Timeout

```php ignore
'awaiting_webhook' => [
    'on' => [
        'WEBHOOK_RECEIVED' => 'processing',
    ],
    // If the webhook never arrives, this machine hangs forever
],
```

If the external service goes down, changes its API, or simply drops the callback, the machine stays in `awaiting_webhook` permanently. No alert fires. No retry happens. The instance is silently stuck.

### Fix: Add a Timer Guard

```php ignore
'awaiting_webhook' => [
    'on' => [
        'WEBHOOK_TIMEOUT'  => ['target' => 'timed_out', 'after' => Timer::hours(1)],
        'WEBHOOK_RECEIVED' => 'processing',
    ],
],
'timed_out' => [
    'entry' => 'handleWebhookTimeoutAction',  // alert, retry, or fail gracefully
],
```

::: tip The Reliability Question
For every state, ask: **"If the expected event never arrives, what happens?"** If the answer is "the machine hangs forever," you need an `after` transition.
:::

## Renewable Timers (Sliding Windows)

Sometimes a deadline should reset whenever a specific event arrives — *"the customer has 7 days to respond, but every new counter-offer resets the clock."* The naive approach is a self-loop with the same `after` timer:

### Anti-Pattern: Self-Loop with Hidden Timer Reset

```php ignore
'awaiting_counter_offer_response' => [
    'on' => [
        'COUNTER_OFFER_UPDATED' => [
            'target'  => 'awaiting_counter_offer_response',  // self-loop
            'actions' => [UpdateCounterOfferAction::class],
        ],
        'COUNTER_OFFER_EXPIRED' => [
            'target' => 'counter_offer_expired',
            'after'  => Timer::days(7),
        ],
    ],
],
```

This does NOT work. EventMachine's persistence layer is intentionally diff-based — when a self-loop produces the same state set, the `machine_current_states` row is preserved (and so is `state_entered_at`). The 7-day sweep keeps anchoring on the original entry time. After 7 wall-clock days the timer fires regardless of how many updates arrived.

This is by design. Self-loops in EventMachine carry "no observable lifecycle change" semantics: timers stay anchored, fired-once flags stay fired. Treating self-loops as silent lifecycle resets would conflict with all the other places they appear (transient routing, no-op event acknowledgement).

### Fix: Model the Renewal as a Real State Transition

Each new offer is a new negotiation lifecycle. Statecharts model lifecycle as state. Use a transient transit state — entered on the renewal event, exits via `@always` back to the waiting state.

```php ignore
'awaiting_counter_offer_response' => [
    'on' => [
        'COUNTER_OFFER_UPDATED'  => 'counter_offer_received',
        'COUNTER_OFFER_ACCEPTED' => [
            'target'  => 'approved',
            'actions' => [ApproveAllocationAction::class],
        ],
        'COUNTER_OFFER_EXPIRED'  => [
            'target' => 'counter_offer_expired',
            'after'  => Timer::days(7),
        ],
    ],
],

// Transit state — captures the moment a new offer arrives.
// Entry action processes the update; @always immediately re-enters waiting.
'counter_offer_received' => [
    'entry' => [UpdateCounterOfferAction::class],
    'on'    => ['@always' => 'awaiting_counter_offer_response'],
],

'counter_offer_expired' => ['type' => 'final'],
'approved'              => ['type' => 'final'],
```

Why this is the idiomatic answer (not a workaround):

1. **Self-documenting graph.** The state diagram now shows `awaiting → received → awaiting` cycle. The renewal moment has a name.
2. **Correct audit trail.** `machine_events` records each `counter_offer_received.enter` — operators can see exactly when the customer's window restarted.
3. **Timer naturally resets.** The transit state exits the waiting state and re-enters it. `state_entered_at` refreshes via row exchange. No special API needed.
4. **Generalizes.** If tomorrow you add "send notification when a new offer arrives," the entry-action slot is already there.
5. **Aligns with statechart theory.** A 7-day-relevant event IS meaningful enough to be a state transition. Hiding it in a self-loop is design malpractice.

### When NOT to use this pattern

If the event genuinely should NOT reset the lifecycle (logging-only, idempotent ack), keep the self-loop. The diff-based persistence is correct for that case — the timer stays anchored, the audit trail doesn't bloat with synthetic transitions.

The decision rule: *"Is this event meaningful enough that an outside observer should see a transition in the audit log?"* If yes, use a transit state. If no, self-loop.

## Guidelines

1. **`after` for deadlines.** "If nothing happens in X time, do Y."

2. **`every` for recurring work.** "While here, do Y every X time."

3. **`max` + `then` for escalation.** "Try X times, then escalate."

4. **Timer actions must be idempotent.** Sweeps may re-fire. Design for it.

5. **Keep intervals >= 1 minute.** Shorter intervals risk queue backpressure with many instances.

6. **Use scheduled events for batch queries.** One query for all instances is better than per-instance polling.

7. **Renew deadlines via transit states, not self-loops.** Self-loops preserve `state_entered_at` by design. To reset a timer on an event, model the event as a real state transition through a transient state.

## Related

- [Time-Based Events](/advanced/time-based-events) -- reference documentation
- [Scheduled Events](/advanced/scheduled-events) -- cron-based batch operations
- [Time-Based Testing](/testing/time-based-testing) -- `advanceTimers()` in tests
- [Action Design](./action-design) -- idempotency patterns
