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
        'RETRY_PAYMENT'    => [
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
        Mail::to($context->get('customer_email'))
            ->send(new PaymentReminderMail($context->get('order_id')));
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
        $reminderKey = 'reminder_sent_' . $context->get('order_id');

        if (cache()->has($reminderKey)) {
            return;  // already sent
        }

        Mail::to($context->get('customer_email'))
            ->send(new PaymentReminderMail($context->get('order_id')));

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
    'order_id'    => null,
    'order_total' => 0,
    'retry_count' => 0,
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
            'RETRY_PAYMENT' => [
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

## Guidelines

1. **`after` for deadlines.** "If nothing happens in X time, do Y."

2. **`every` for recurring work.** "While here, do Y every X time."

3. **`max` + `then` for escalation.** "Try X times, then escalate."

4. **Timer actions must be idempotent.** Sweeps may re-fire. Design for it.

5. **Keep intervals >= 1 minute.** Shorter intervals risk queue backpressure with many instances.

6. **Use scheduled events for batch queries.** One query for all instances is better than per-instance polling.

## Related

- [Time-Based Events](/advanced/time-based-events) -- reference documentation
- [Scheduled Events](/advanced/scheduled-events) -- cron-based batch operations
- [Time-Based Testing](/testing/time-based-testing) -- `advanceTimers()` in tests
- [Action Design](./action-design) -- idempotency patterns
