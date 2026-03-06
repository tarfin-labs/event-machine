# Writing Transitions

Transitions define how your machine moves between states in response to events.

## Basic Transitions

The simplest transition maps an event to a target state:

```php ignore
'pending' => [
    'on' => [
        'SUBMIT' => 'processing',  // SUBMIT event -> go to processing
    ],
],
```

## Transition Syntax Options

EventMachine supports several syntax forms for flexibility:

### String Target (Simple)

```php ignore
'SUBMIT' => 'processing',
```

### Array with Target

```php ignore
'SUBMIT' => [
    'target' => 'processing',
],
```

### Array with Actions

```php ignore
'SUBMIT' => [
    'target' => 'processing',
    'actions' => 'logSubmissionAction',
],
```

### Array with Multiple Options

```php ignore
'SUBMIT' => [
    'target' => 'processing',
    'actions' => ['validateInputAction', 'logSubmissionAction'],
    'guards' => 'canSubmitGuard',
    'calculators' => 'computeMetricsCalculator',
    'description' => 'Submit for processing',
],
```

### Null (Forbidden)

```php ignore
'CANCEL' => null,  // Block this event
```

## Transition Properties

| Property | Type | Description |
|----------|------|-------------|
| `target` | string\|null | The destination state (`null` = forbidden) |
| `actions` | string\|array | Actions to execute during transition |
| `guards` | string\|array | Conditions that must pass |
| `calculators` | string\|array | Context computations before guards |
| `description` | string | Human-readable description |

## Guarded Transitions

Guards control whether a transition can occur:

```php ignore
'pending' => [
    'on' => [
        'PAY' => [
            'target' => 'paid',
            'guards' => 'hasValidPaymentGuard',
        ],
    ],
],
```

If the guard returns `false`, the transition doesn't happen.

### Multiple Guards

All guards must pass for the transition to proceed:

```php ignore
'PAY' => [
    'target' => 'paid',
    'guards' => ['hasValidPaymentGuard', 'hasStockGuard', 'notExpiredGuard'],
],
```

## Conditional Transitions (Multi-Branch)

Route to different states based on conditions:

```php ignore
'pending' => [
    'on' => [
        'PAY' => [
            [
                'target' => 'paid',
                'guards' => 'isFullPaymentGuard',
            ],
            [
                'target' => 'partial',
                'guards' => 'isPartialPaymentGuard',
            ],
            [
                'target' => 'failed',  // Default fallback
            ],
        ],
    ],
],
```

Guards evaluate in order. The first matching branch wins.

::: tip Branch Order Matters
Always put more specific guards first. A branch without guards acts as the default fallback.
:::

## Transition Actions

Execute code during a transition:

```php ignore
'pending' => [
    'on' => [
        'PAY' => [
            'target' => 'paid',
            'actions' => 'processPaymentAction',
        ],
    ],
],
```

### Multiple Actions

```php ignore
'PAY' => [
    'target' => 'paid',
    'actions' => [
        'validatePaymentAction',
        'deductBalanceAction',
        'sendReceiptAction',
        'notifyWarehouseAction',
    ],
],
```

Actions execute in the order listed.

### Action Arguments

Pass arguments to actions using colon syntax:

```php ignore
'actions' => 'notifyAction:email,sms',  // Calls notifyAction with ['email', 'sms']
```

## Calculators

Calculators run before guards to prepare context data:

```php ignore
'CHECKOUT' => [
    'target' => 'processing',
    'calculators' => 'computeTotalCalculator',
    'guards' => 'hasSufficientFundsGuard',
    'actions' => 'processCheckoutAction',
],
```

Execution order:
1. **Calculators** - Prepare data
2. **Guards** - Check conditions
3. **Actions** - Execute side effects

If a calculator fails (throws an exception), the transition aborts.

## Self Transitions

Transition to the same state, triggering exit and entry actions:

```php ignore
'active' => [
    'entry' => 'logEntryAction',
    'exit' => 'logExitAction',
    'on' => [
        'REFRESH' => [
            'target' => 'active',  // Same state
            'actions' => 'reloadDataAction',
        ],
    ],
],
```

This triggers: exit actions -> transition actions -> entry actions.

## Internal Transitions

Stay in the same state without triggering entry/exit actions:

```php ignore
'active' => [
    'entry' => 'logEntryAction',    // NOT called on HEARTBEAT
    'exit' => 'logExitAction',      // NOT called on HEARTBEAT
    'on' => [
        'HEARTBEAT' => [
            'actions' => 'updateTimestampAction',  // No target = internal
        ],
    ],
],
```

::: info Internal vs Self Transitions
- **Internal**: No target, entry/exit actions skipped
- **Self**: Target equals current state, entry/exit actions run

**When to use Internal Transitions:**
- Updating context without re-initializing state (heartbeats, counter increments)
- Handling events that shouldn't trigger expensive entry/exit actions
- Refreshing data without losing current state setup

**When to use Self Transitions:**
- Resetting state (re-running initialization logic)
- Reloading data from scratch
- Restarting a process within the same state
:::

## Forbidden Transitions

Block specific events by setting the transition target to `null`:

```php ignore
'checkout' => [
    'initial' => 'payment',
    'on' => [
        'CANCEL' => 'cancelled',  // Parent allows cancel
    ],
    'states' => [
        'payment' => [
            'on' => [
                'PROCEED' => 'confirmation',
            ],
        ],
        'confirmation' => [
            'on' => [
                'CANCEL' => null,  // Block cancel in confirmation
            ],
        ],
    ],
],
```

When `CANCEL` is sent while in `confirmation` state:
- The child state's `null` transition overrides the parent's `CANCEL => 'cancelled'`
- The event is effectively blocked - no transition occurs

### Use Cases

**Override parent transitions:**
```php ignore
'parent' => [
    'on' => [
        'RESET' => 'initial',  // Available to all children
    ],
    'states' => [
        'critical' => [
            'on' => [
                'RESET' => null,  // Except in critical state
            ],
        ],
    ],
],
```

**Disable events in specific states:**
```php ignore
'states' => [
    'processing' => [
        'on' => [
            'SUBMIT' => null,    // Can't submit while processing
            'CANCEL' => null,    // Can't cancel while processing
        ],
    ],
],
```

::: warning Null vs Omitted
- `'EVENT' => null` - Explicitly forbidden, blocks even inherited transitions
- Event not defined - Falls through to parent, or throws `NoTransitionDefinitionFoundException`
:::

## Always Transitions

Transitions that evaluate immediately after entering a state:

```php ignore
'processing' => [
    'entry' => 'processDataAction',
    'on' => [
        '@always' => [
            [
                'target' => 'success',
                'guards' => 'isProcessingCompleteGuard',
            ],
            [
                'target' => 'processing',  // Stay and retry
            ],
        ],
    ],
],
```

The `@always` key is a reserved event that fires automatically.

## Hierarchical Transitions

Transitions in compound states:

```php ignore
'checkout' => [
    'initial' => 'cart',
    'states' => [
        'cart' => [
            'on' => [
                'PROCEED' => 'shipping',
            ],
        ],
        'shipping' => [
            'on' => [
                'PROCEED' => 'payment',
                'BACK' => 'cart',
            ],
        ],
        'payment' => [
            'on' => [
                'COMPLETE' => 'confirmation',
                'BACK' => 'shipping',
            ],
        ],
        'confirmation' => [],
    ],
    // Parent-level transition applies to all children
    'on' => [
        'CANCEL' => 'cancelled',  // Can cancel from any child state
    ],
],
```

Parent transitions are inherited by child states.

## Transition Using Event Classes

Reference event classes directly:

```php ignore
use App\Events\PaymentReceived;

'pending' => [
    'on' => [
        PaymentReceived::class => [
            'target' => 'paid',
            'actions' => 'processPayment',
        ],
    ],
],
```

## Complete Example

```php ignore
MachineDefinition::define(
    config: [
        'id' => 'order',
        'initial' => 'cart',
        'states' => [
            'cart' => [
                'on' => [
                    'ADD_ITEM' => [
                        'actions' => 'addToCartAction',
                    ],
                    'REMOVE_ITEM' => [
                        'actions' => 'removeFromCartAction',
                    ],
                    'CHECKOUT' => [
                        'target' => 'checkout',
                        'guards' => 'hasItemsGuard',
                    ],
                ],
            ],
            'checkout' => [
                'entry' => 'calculateTotalsAction',
                'on' => [
                    'APPLY_COUPON' => [
                        'calculators' => 'validateCouponCalculator',
                        'actions' => 'applyCouponAction',
                    ],
                    'PAY' => [
                        [
                            'target' => 'paid',
                            'guards' => ['hasStockGuard', 'paymentValidGuard'],
                            'actions' => ['processPaymentAction', 'reserveStockAction'],
                        ],
                        [
                            'target' => 'payment_failed',
                            'actions' => 'logFailureAction',
                        ],
                    ],
                    'BACK' => 'cart',
                ],
            ],
            'paid' => [
                'entry' => 'sendConfirmationAction',
                'on' => [
                    'SHIP' => [
                        'target' => 'shipped',
                        'actions' => 'createShipmentAction',
                    ],
                ],
            ],
            'payment_failed' => [
                'on' => [
                    'RETRY' => 'checkout',
                    'CANCEL' => 'cancelled',
                ],
            ],
            'shipped' => [
                'on' => [
                    'DELIVER' => 'delivered',
                ],
            ],
            'delivered' => [
                'type' => 'final',
                'result' => 'orderSummaryResult',
            ],
            'cancelled' => [
                'type' => 'final',
            ],
        ],
    ],
    behavior: [
        'actions' => [...],
        'guards' => [...],
        'calculators' => [...],
        'results' => [...],
    ],
);
```

## Transition Execution Order

When a transition fires, this is the execution sequence:

```
1. Event received
2. Find matching transition definition
3. Run calculators (prepare context)
4. Evaluate guards (can transition happen?)
5. If guards pass:
   a. Run source state exit actions
   b. Run transition actions
   c. Run target state entry actions
   d. Check for @always transitions
   e. Process queued events
6. If guards fail:
   - Stay in current state
   - No actions execute
```
