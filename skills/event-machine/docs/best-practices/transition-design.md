# Transition Design

Transitions connect states. They define _when_ and _how_ a machine moves from one condition to another. Understanding the different transition types -- and their subtleties -- prevents a class of bugs that are hard to diagnose at runtime.

## Self-Transitions vs Targetless Transitions

These two concepts look similar but behave differently.

### Self-Transition

A self-transition targets the _current state_. The machine exits and re-enters the same state, firing exit actions, transition actions, and entry actions.

```php ignore
'awaiting_payment' => [
    'entry' => 'sendPaymentReminderAction',
    'exit'  => 'logPaymentAttemptAction',
    'on'    => [
        'PAYMENT_RETRY_REQUESTED' => [
            'target'  => 'awaiting_payment',   // self-transition: exit + re-enter
            'actions' => 'incrementRetryAction',
        ],
    ],
],
```

When `PAYMENT_RETRY_REQUESTED` fires: `logPaymentAttemptAction` (exit) -> `incrementRetryAction` (transition) -> `sendPaymentReminderAction` (entry). The state "restarts".

### Targetless Transition

A targetless transition has no `target`. Actions run, but the state does not change. No exit or entry actions fire.

```php ignore
'awaiting_payment' => [
    'entry' => 'sendPaymentReminderAction',
    'on'    => [
        'UPDATE_AMOUNT' => [
            'actions' => 'recalculateAmountAction',   // runs, but no state change
        ],
    ],
],
```

When `UPDATE_AMOUNT` fires: only `recalculateAmountAction` runs. The machine stays in `awaiting_payment` without re-triggering entry or exit.

**Decision rule:** Need to re-initialize the state? Self-transition. Just update context? Targetless.

## @always Chains

`@always` transitions fire immediately after entering a state. They are powerful for routing but dangerous if misused.

::: tip No Need to Copy Event Data (v8+)
Since v8, behaviors on `@always` transitions receive the original triggering event. You no longer need to copy event payload into context before an `@always` chain. See [@always Transitions — Event Preservation](/advanced/always-transitions#event-preservation).
:::

### The Termination Rule

Every `@always` chain must eventually reach a state without `@always`, or use guards that will eventually fail. If it does not, the machine hits the depth limit (100) and throws `MaxTransitionDepthExceededException`.

```php ignore
// Safe: linear chain terminates

'evaluating' => [
    'entry' => 'computeScoreAction',
    'on'    => [
        '@always' => [
            ['target' => 'approved', 'guards' => 'isScoreHighGuard'],
            ['target' => 'under_review'],    // fallback -- no @always, terminates
        ],
    ],
],
'approved' => [],
'under_review'   => [],
```

```php ignore
// Dangerous: cycle without exit

'state_a' => [
    'on' => ['@always' => 'state_b'],
],
'state_b' => [
    'on' => ['@always' => 'state_a'],   // infinite loop!
],
```

### Always Include a Fallback

Multi-branch `@always` transitions should end with an unguarded fallback:

```php ignore
'@always' => [
    ['target' => 'express_processing',  'guards' => 'isExpressGuard'],
    ['target' => 'prioritized',         'guards' => 'isPriorityGuard'],
    ['target' => 'standard_processing'],   // always reachable
],
```

Without the fallback, if no guard passes, the machine stays in the current state -- which may cause the `@always` to re-evaluate on the next event, leading to confusion.

## Multi-Branch Transitions

When an event has multiple possible targets, guards determine which branch wins. The first matching guard takes the transition.

```php ignore
'awaiting_approval' => [
    'on' => [
        'APPROVAL_SUBMITTED' => [
            ['target' => 'auto_approved',     'guards' => 'isUnderAutoLimitGuard'],
            ['target' => 'awaiting_manager_approval',   'guards' => 'isUnderManagerLimitGuard'],
            ['target' => 'awaiting_director_approval'],  // fallback
        ],
    ],
],
```

### Anti-Pattern: Relying on Definition Order

```php ignore
// Anti-pattern: implicit fallback depends on ordering

'APPROVAL_SUBMITTED' => [
    ['target' => 'auto_approved',  'guards' => 'isLowRiskGuard'],
    ['target' => 'under_manual_review',  'guards' => 'isHighRiskGuard'],
    // What if neither guard passes? No transition fires.
],
```

**Fix:** Always include an explicit unguarded fallback as the last branch, or ensure your guards are exhaustive.

```php ignore
'APPROVAL_SUBMITTED' => [
    ['target' => 'auto_approved',  'guards' => 'isLowRiskGuard'],
    ['target' => 'under_manual_review',  'guards' => 'isHighRiskGuard'],
    ['target' => 'pending_review'],  // explicit fallback
],
```

## Guard Priority: Errors First

In multi-branch transitions, guard evaluation order matters -- the first passing guard wins. Put error and failure guards **before** the happy-path fallback. This ensures failures are caught before the default path takes over.

::: warning This Rule Applies to Multi-Branch Only
Different event keys in the same `on` array (`PAYMENT_CAPTURED`, `PAYMENT_FAILED`) do **not** compete. Each event targets a specific key. Guard priority only matters for multi-branch transitions where the same trigger has multiple possible targets.
:::

### Anti-Pattern: Happy Path First

```php ignore
// Anti-pattern: unguarded fallback first — guards are never evaluated
'evaluating' => [
    'on' => [
        '@always' => [
            ['target' => 'processing'],                                  // matches immediately
            ['target' => 'retrying', 'guards' => 'canRetryGuard'],       // unreachable
            ['target' => 'failed', 'guards' => 'hasErrorGuard'],         // unreachable
        ],
    ],
],
```

The unguarded branch matches first every time. The error and retry guards are never evaluated.

**Fix:** Error guards first, happy-path fallback last:

```php ignore
'evaluating' => [
    'on' => [
        '@always' => [
            ['target' => 'failed', 'guards' => 'hasErrorGuard'],       // error first
            ['target' => 'retrying', 'guards' => 'canRetryGuard'],     // retry second
            ['target' => 'processing'],                                  // fallback last
        ],
    ],
],
```

Same principle for guarded transitions on a specific event:

```php ignore
'PAYMENT_RESULT' => [
    ['target' => 'failed', 'guards' => 'isPaymentDeclinedGuard'],    // error first
    ['target' => 'captured'],                                          // fallback last
],
```

## Anti-Pattern: @always Without Terminal Path

```php ignore
// Anti-pattern: @always cycle through context mutation

'retrying' => [
    'entry' => 'incrementRetryAction',
    'on'    => [
        '@always' => [
            ['target' => 'processing', 'guards' => 'canRetryGuard'],
            // No fallback -- if canRetryGuard returns true forever, infinite loop
        ],
    ],
],
'processing' => [
    'on' => ['PROCESSING_FAILED' => 'retrying'],
],
```

If `canRetryGuard` always returns `true`, the `@always` chain never terminates within a macrostep. In practice, the depth limit (100) catches this, but it is a design error, not a feature.

**Fix:** Add a terminal fallback.

```php ignore
'retrying' => [
    'entry' => 'incrementRetryAction',
    'on'    => [
        '@always' => [
            ['target' => 'processing', 'guards' => 'canRetryGuard'],
            ['target' => 'failed'],   // terminal when retries exhausted
        ],
    ],
],
```

## Example: Approval With Escalation

A complete multi-branch pattern with escalation:

```php ignore
'id'      => 'order_workflow',
'initial' => 'submitted',
'context' => [
    'order_total' => 0,
    'approved_by' => null,
],
'states' => [
    'submitted' => [
        'on' => [
            '@always' => [
                [
                    'target' => 'auto_approved',
                    'guards' => 'isUnderAutoApprovalLimitGuard',
                    'actions' => 'logAutoApprovalAction',
                ],
                [
                    'target' => 'awaiting_manager_approval',
                    'guards' => 'isUnderManagerLimitGuard',
                ],
                ['target' => 'awaiting_director_approval'],
            ],
        ],
    ],
    'auto_approved'               => ['on' => ['@always' => 'processing']],
    'awaiting_manager_approval'   => ['on' => ['ORDER_APPROVED' => 'processing']],
    'awaiting_director_approval'  => ['on' => ['ORDER_APPROVED' => 'processing']],
    'processing'                  => [],
],
```

Orders under the auto-approval threshold skip human review. Mid-range orders go to a manager. Large orders go to a director. The `@always` chain terminates because every branch leads to a state without `@always`.

## Guidelines

1. **Self-transition to restart, targetless to update.** Know which one you need before defining the transition.

2. **Every `@always` chain must terminate.** End with a fallback or a guard that eventually fails. The depth limit is a safety net, not flow control.

3. **First guard wins.** In multi-branch transitions, order matters. Put the most specific guard first, the broadest last.

4. **Always include a fallback.** The last branch in a multi-target transition should have no guard.

5. **Document escalation paths.** When transitions branch based on thresholds, a comment explaining the business rule is worth more than the code itself.

## Related

- [Transitions](/understanding/states-and-transitions) -- reference documentation
- [@always Transitions](/advanced/always-transitions) -- eventless transitions
- [Guard Design](./guard-design) -- writing pure guards
- [Event Bubbling](./event-bubbling) -- how handlers are resolved
