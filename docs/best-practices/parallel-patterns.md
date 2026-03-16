# Parallel Patterns

Parallel states let multiple independent processes run simultaneously within a single machine. Each region has its own state hierarchy and processes events independently. When all regions reach a final state, `@done` fires.

## The Independence Rule

Regions must be independent. No region should assume it runs before or after another. No region should depend on a specific state of a sibling. If two processes need to coordinate step-by-step, they are not parallel -- they are sequential.

## Separate Context Keys Per Region

Each region should own its own context keys. If two regions write to the same key, the last writer wins -- a silent data loss that is extremely hard to debug.

```php ignore
// Do: separate keys per region

'context' => [
    'payment_status'  => null,
    'payment_id'      => null,
    'shipping_status' => null,
    'tracking_id'     => null,
    'document_status' => null,
    'document_url'    => null,
],
```

```php ignore
// Don't: shared keys

'context' => [
    'status'  => null,   // Which region writes this?
    'result'  => null,   // Payment result or shipping result?
],
```

## @done Fires When ALL Regions Reach Final

A parallel state's `@done` transition fires only when every region has reached a final state. This is the synchronisation point -- no explicit coordination needed.

```php ignore
'fulfillment' => [
    'type'  => 'parallel',
    '@done' => 'completed',
    'states' => [
        'payment' => [
            'initial' => 'pending',
            'states'  => [
                'pending'  => ['on' => ['PAYMENT_RECEIVED' => 'settled']],
                'settled'  => ['type' => 'final'],
            ],
        ],
        'shipping' => [
            'initial' => 'preparing',
            'states'  => [
                'preparing' => ['on' => ['SHIPMENT_DISPATCHED' => 'shipped']],
                'shipped'   => ['type' => 'final'],
            ],
        ],
    ],
],
'completed' => ['type' => 'final'],
```

`completed` is reached only when both `payment` is `settled` and `shipping` is `shipped`, regardless of which finishes first.

## Anti-Pattern: Regions Depending on Execution Order

```php ignore
// Anti-pattern: region B assumes region A has already run

'fulfillment' => [
    'type'   => 'parallel',
    'states' => [
        'payment' => [
            'initial' => 'charging',
            'states'  => [
                'charging' => [
                    'entry' => 'chargePaymentAction',  // writes payment_id to context
                    'on'    => ['PAYMENT_CHARGED' => 'charged'],
                ],
                'charged' => ['type' => 'final'],
            ],
        ],
        'notification' => [
            'initial' => 'sending',
            'states'  => [
                'sending' => [
                    // BAD: reads payment_id that may not exist yet
                    'entry' => 'sendPaymentConfirmationAction',
                    'on'    => ['NOTIFICATION_SENT' => 'sent'],
                ],
                'sent' => ['type' => 'final'],
            ],
        ],
    ],
],
```

There is no guarantee that `payment`'s entry action runs before `notification`'s. With parallel dispatch enabled, they may execute on different queue workers simultaneously.

**Fix:** If one process depends on another's result, they are sequential, not parallel. Move the notification to after `@done`, or use a non-parallel approach.

```php ignore
// Fix: notification happens after payment completes

'fulfillment' => [
    'type'  => 'parallel',
    '@done' => [
        'target'  => 'completed',
        'actions' => 'sendPaymentConfirmationAction',  // runs after ALL regions done
    ],
    'states' => [
        'payment'  => [...],
        'shipping' => [...],
    ],
],
```

## Anti-Pattern: Shared Context Key Mutation

```php ignore
// Anti-pattern: both regions write to 'status'

'payment' => [
    'initial' => 'pending',
    'states'  => [
        'pending' => [
            'entry' => 'setStatusPaymentPendingAction',  // writes context.status = 'payment_pending'
            'on'    => ['PAYMENT_RECEIVED' => 'done'],
        ],
        'done' => ['type' => 'final'],
    ],
],
'shipping' => [
    'initial' => 'preparing',
    'states'  => [
        'preparing' => [
            'entry' => 'setStatusShippingAction',  // writes context.status = 'shipping'  -- OVERWRITES!
            'on'    => ['SHIPPED' => 'done'],
        ],
        'done' => ['type' => 'final'],
    ],
],
```

Last-writer-wins. You cannot predict which value `status` holds.

**Fix:** Use namespaced keys: `payment_status` and `shipping_status`.

## Anti-Pattern: Using Parallel for Sequential Phases

```php ignore
// Anti-pattern: phases that must run in order

'processing' => [
    'type'   => 'parallel',   // wrong!
    'states' => [
        'validation' => [...],   // must complete before payment
        'payment'    => [...],   // must complete before shipping
        'shipping'   => [...],   // depends on payment result
    ],
],
```

If phases depend on each other, they are not parallel. Use sequential states or machine delegation.

**Fix:** Sequential states, optionally with child machines.

```php ignore
'states' => [
    'validating' => [
        'on' => ['VALIDATION_PASSED' => 'processing_payment'],
    ],
    'processing_payment' => [
        'on' => ['PAYMENT_RECEIVED' => 'shipping'],
    ],
    'shipping' => [
        'on' => ['SHIPMENT_DISPATCHED' => 'completed'],
    ],
    'completed' => ['type' => 'final'],
],
```

## Cross-Region Coordination

Sometimes one region needs to wait for a sibling. The standard approach is an `@always` transition with a guard that checks the sibling's state via `$state->matches()`:

```php ignore
'dealer' => [
    'initial' => 'pricing',
    'states'  => [
        'pricing' => [
            'on' => ['PRICING_COMPLETED' => 'awaiting_approval'],
        ],
        'awaiting_approval' => [
            'on' => [
                '@always' => [
                    ['target' => 'finalizing', 'guards' => 'isCustomerApprovedGuard'],
                ],
            ],
        ],
        'finalizing' => ['type' => 'final'],
    ],
],
```

```php no_run
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class IsCustomerApprovedGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event, State $state): bool
    {
        return $state->matches('fulfillment.customer.approved');
    }
}
```

The guard re-evaluates whenever any region transitions, so the waiting region automatically unblocks when the sibling reaches the expected state.

## Example: Order Fulfillment

```php ignore
'id'      => 'order_workflow',
'initial' => 'fulfillment',
'context' => [
    'order_id'        => null,
    'payment_id'      => null,
    'tracking_id'     => null,
    'documents_ready' => false,
],
'states' => [
    'fulfillment' => [
        'type'  => 'parallel',
        '@done' => 'completed',
        'states' => [
            'payment' => [
                'initial' => 'awaiting_payment',
                'states'  => [
                    'awaiting_payment' => [
                        'on' => ['PAYMENT_RECEIVED' => 'settled'],
                    ],
                    'settled' => ['type' => 'final'],
                ],
            ],
            'shipping' => [
                'initial' => 'preparing',
                'states'  => [
                    'preparing' => [
                        'on' => ['SHIPMENT_DISPATCHED' => 'in_transit'],
                    ],
                    'in_transit' => [
                        'on' => ['DELIVERY_CONFIRMED' => 'delivered'],
                    ],
                    'delivered' => ['type' => 'final'],
                ],
            ],
            'documents' => [
                'initial' => 'generating',
                'states'  => [
                    'generating' => [
                        'entry' => 'generateInvoiceAction',
                        'on'    => ['DOCUMENT_READY' => 'collected'],
                    ],
                    'collected' => ['type' => 'final'],
                ],
            ],
        ],
    ],
    'completed' => ['type' => 'final'],
],
```

Payment, shipping, and document generation proceed independently. The order is `completed` only when all three regions reach their final states.

## Guidelines

1. **Regions must be independent.** No shared mutable state, no execution order assumptions.

2. **Separate context keys per region.** `payment_status` and `shipping_status`, never a shared `status`.

3. **Let `@done` synchronise.** Do not manually check if siblings are done -- that is what `@done` is for.

4. **Sequential work is not parallel.** If phase B depends on phase A's result, use sequential states.

5. **Use `@always` guards for cross-region coordination.** Check sibling state via `$state->matches()` when one region must wait for another.

## Related

- [Parallel States](/advanced/parallel-states/) -- reference documentation
- [Parallel Dispatch](/advanced/parallel-states/parallel-dispatch) -- concurrent execution
- [Parallel Testing](/testing/parallel-testing) -- testing parallel machines
- [Context Design](./context-design) -- region-safe context keys
