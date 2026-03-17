# Context Design

Context is the data that travels with your machine. It holds the information that actions, guards, and calculators need to do their work. Keeping context lean and well-structured is the difference between a machine that is easy to reason about and one that becomes a maintenance burden.

## The Decision Rule

> Does this value change which transitions are available? **Put it in states.**
> Does it just carry data that actions or guards read? **Put it in context.**

This is the same rule from [State Design](./state-design), viewed from the other side.

## Anti-Pattern: Boolean Flags That Should Be States

```php ignore
// Anti-pattern: context flag controlling flow

'context' => [
    'is_approved' => false,
],
'states' => [
    'pending' => [
        'on' => [
            'REVIEW_COMPLETED' => [
                'target' => 'processing',
                'guards' => 'isApprovedGuard',     // reads is_approved
            ],
        ],
    ],
    'processing' => [],
],
```

If `is_approved` determines whether the machine moves forward, it is really a state. The machine already has a mechanism for this: separate states.

**Fix:** Model the approval as a state.

```php ignore
'states' => [
    'pending' => [
        'on' => [
            'ORDER_APPROVED' => 'approved',
            'ORDER_REJECTED' => 'rejected',
        ],
    ],
    'approved' => [
        'on' => ['PROCESSING_STARTED' => 'processing'],
    ],
    'rejected' => ['type' => 'final'],
    'processing' => [],
],
```

Now the machine's structure tells the story. No hidden boolean, no guard checking a flag -- just states and transitions.

## Anti-Pattern: Context as Data Dump

```php ignore
// Anti-pattern: 20+ unrelated keys

'context' => [
    'customer_name'        => null,
    'customer_email'       => null,
    'customer_phone'       => null,
    'customer_address'     => null,
    'order_total'          => 0,
    'order_currency'       => 'TRY',
    'order_discount_code'  => null,
    'order_discount_pct'   => 0,
    'shipping_method'      => null,
    'shipping_cost'        => 0,
    'shipping_address'     => null,
    'shipping_tracking_id' => null,
    'payment_method'       => null,
    'payment_status'       => null,
    'payment_transaction'  => null,
    'invoice_id'           => null,
    'invoice_url'          => null,
    'notes'                => null,
    'internal_flags'       => [],
    'audit_log'            => [],
],
```

When context becomes a grab-bag, it is hard to know which keys are relevant at which point in the machine's lifecycle. Keys set early may be irrelevant later. Keys set conditionally may be null when accessed.

**Fix:** Keep context minimal. Store only what the machine _needs_ to make decisions or pass to child machines. Detailed records (audit logs, customer profiles) belong in your database, not in machine context.

```php ignore
// Lean context: only what the machine needs

'context' => [
    'order_id'      => null,
    'order_total'   => 0,
    'payment_id'    => null,
    'retry_count'   => 0,
],
```

Actions can load additional data from the database when they need it. Context should carry references (IDs) and decision-relevant values, not entire entities.

## Anti-Pattern: Storing Derived Values

```php ignore
// Anti-pattern: manually computed value in context

'context' => [
    'item_quantity' => 0,
    'item_price'    => 0,
    'total'         => 0,  // = quantity * price -- who updates this?
],
```

If `total` gets out of sync with `item_quantity` and `item_price`, guards reading `total` will make wrong decisions.

**Fix:** Use a calculator. Calculators run before guards and compute derived values from current context.

```php ignore
'on' => [
    'ORDER_CONFIRMED' => [
        'target'      => 'processing',
        'calculators' => 'orderTotalCalculator',  // computes total from quantity + price
        'guards'      => 'hasSufficientBalanceGuard',
    ],
],
```

## Best Practice: Typed ContextManager

For machines with more than a handful of context keys, a typed `ContextManager` subclass provides autocompletion, type safety, and documentation in one place.

```php
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

class OrderWorkflowContext extends ContextManager
{
    public ?string $orderId     = null;
    public int $orderTotal      = 0;
    public ?string $paymentId   = null;
    public int $retryCount      = 0;
    public ?string $lastError   = null;
}
```

Access in actions and guards is direct property access rather than string-key lookups:

```php
use Tarfinlabs\EventMachine\Behavior\GuardBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

class IsRetryAllowedGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return $context->retryCount < 3;
    }
}
```

## Best Practice: Separate Keys for Parallel Regions

When using parallel states, give each region its own context namespace to avoid write conflicts.

```php ignore
// Each region owns its own keys

'context' => [
    'payment_status'  => null,   // written by payment region
    'payment_id'      => null,   // written by payment region
    'shipping_status' => null,   // written by shipping region
    'tracking_id'     => null,   // written by shipping region
],
```

If two regions write to the same key, the last writer wins -- a subtle and hard-to-debug data loss.

## Refactoring Recipe: Bloated Context to Lean Context + States

Before:

```php ignore
'context' => [
    'is_validated'    => false,
    'is_paid'         => false,
    'is_shipped'      => false,
    'validation_error' => null,
    'payment_error'    => null,
],
'states' => [
    'processing' => [
        'on' => [
            'CHECK_STATUS' => [
                ['target' => 'completed', 'guards' => 'isAllDoneGuard'],
                ['target' => 'processing'],
            ],
        ],
    ],
],
```

After:

```php ignore
'context' => [
    'order_id' => null,
],
'states' => [
    'validating' => [
        'on' => [
            'VALIDATION_PASSED' => 'awaiting_payment',
            'VALIDATION_FAILED' => 'failed',
        ],
    ],
    'awaiting_payment' => [
        'on' => ['PAYMENT_RECEIVED' => 'shipping'],
    ],
    'shipping' => [
        'on' => ['SHIPMENT_DISPATCHED' => 'completed'],
    ],
    'completed' => ['type' => 'final'],
    'failed'    => ['type' => 'final'],
],
```

The boolean flags become states. The "check status" polling loop becomes a natural flow of transitions. The context shrinks to just the reference data the machine needs.

## Guidelines

1. **Context carries data, states carry conditions.** If a value changes available transitions, it is a state.

2. **Keep context minimal.** Store IDs and decision-relevant values. Load full entities in actions when needed.

3. **Avoid derived values.** Use calculators to compute them on demand.

4. **Namespace keys by region.** In parallel machines, each region should own distinct keys.

5. **Prefer typed context for complex machines.** A `ContextManager` subclass catches typos at development time, not runtime.

## Related

- [Working with Context](/understanding/context) -- reference documentation
- [Custom Context](/advanced/custom-context) -- typed ContextManager
- [Calculators](/behaviors/calculators) -- computing derived values
- [State Design](./state-design) -- when to use states instead
- [Parallel Patterns](./parallel-patterns) -- region-safe context
