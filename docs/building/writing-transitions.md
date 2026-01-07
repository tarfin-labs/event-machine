# Writing Transitions

Transitions define how your machine moves between states in response to events.

## Basic Transitions

The simplest transition maps an event to a target state:

```php
'pending' => [
    'on' => [
        'SUBMIT' => 'processing',  // SUBMIT event -> go to processing
    ],
],
```

## Transition Syntax Options

EventMachine supports several syntax forms for flexibility:

### String Target (Simple)

```php
'SUBMIT' => 'processing',
```

### Array with Target

```php
'SUBMIT' => [
    'target' => 'processing',
],
```

### Array with Actions

```php
'SUBMIT' => [
    'target' => 'processing',
    'actions' => 'logSubmission',
],
```

### Array with Multiple Options

```php
'SUBMIT' => [
    'target' => 'processing',
    'actions' => ['validateInput', 'logSubmission'],
    'guards' => 'canSubmit',
    'calculators' => 'computeMetrics',
    'description' => 'Submit for processing',
],
```

## Transition Properties

| Property | Type | Description |
|----------|------|-------------|
| `target` | string | The destination state |
| `actions` | string\|array | Actions to execute during transition |
| `guards` | string\|array | Conditions that must pass |
| `calculators` | string\|array | Context computations before guards |
| `description` | string | Human-readable description |

## Guarded Transitions

Guards control whether a transition can occur:

```php
'pending' => [
    'on' => [
        'PAY' => [
            'target' => 'paid',
            'guards' => 'hasValidPayment',
        ],
    ],
],
```

If the guard returns `false`, the transition doesn't happen.

### Multiple Guards

All guards must pass for the transition to proceed:

```php
'PAY' => [
    'target' => 'paid',
    'guards' => ['hasValidPayment', 'hasStock', 'notExpired'],
],
```

## Conditional Transitions (Multi-Branch)

Route to different states based on conditions:

```php
'pending' => [
    'on' => [
        'PAY' => [
            [
                'target' => 'paid',
                'guards' => 'isFullPayment',
            ],
            [
                'target' => 'partial',
                'guards' => 'isPartialPayment',
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

```php
'pending' => [
    'on' => [
        'PAY' => [
            'target' => 'paid',
            'actions' => 'processPayment',
        ],
    ],
],
```

### Multiple Actions

```php
'PAY' => [
    'target' => 'paid',
    'actions' => [
        'validatePayment',
        'deductBalance',
        'sendReceipt',
        'notifyWarehouse',
    ],
],
```

Actions execute in the order listed.

### Action Arguments

Pass arguments to actions using colon syntax:

```php
'actions' => 'notify:email,sms',  // Calls notify with ['email', 'sms']
```

## Calculators

Calculators run before guards to prepare context data:

```php
'CHECKOUT' => [
    'target' => 'processing',
    'calculators' => 'computeTotal',
    'guards' => 'hasSufficientFunds',
    'actions' => 'processCheckout',
],
```

Execution order:
1. **Calculators** - Prepare data
2. **Guards** - Check conditions
3. **Actions** - Execute side effects

If a calculator fails (throws an exception), the transition aborts.

## Self Transitions

Transition to the same state, triggering exit and entry actions:

```php
'active' => [
    'entry' => 'logEntry',
    'exit' => 'logExit',
    'on' => [
        'REFRESH' => [
            'target' => 'active',  // Same state
            'actions' => 'reloadData',
        ],
    ],
],
```

This triggers: exit actions -> transition actions -> entry actions.

## Internal Transitions

Stay in the same state without triggering entry/exit actions:

```php
'active' => [
    'entry' => 'logEntry',    // NOT called on HEARTBEAT
    'exit' => 'logExit',      // NOT called on HEARTBEAT
    'on' => [
        'HEARTBEAT' => [
            'actions' => 'updateTimestamp',  // No target = internal
        ],
    ],
],
```

::: info Internal vs Self Transitions
- **Internal**: No target, entry/exit actions skipped
- **Self**: Target equals current state, entry/exit actions run
:::

## Always Transitions

Transitions that evaluate immediately after entering a state:

```php
'processing' => [
    'entry' => 'processData',
    'on' => [
        '@always' => [
            [
                'target' => 'success',
                'guards' => 'isProcessingComplete',
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

```php
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

```php
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

```php
MachineDefinition::define(
    config: [
        'id' => 'order',
        'initial' => 'cart',
        'states' => [
            'cart' => [
                'on' => [
                    'ADD_ITEM' => [
                        'actions' => 'addToCart',
                    ],
                    'REMOVE_ITEM' => [
                        'actions' => 'removeFromCart',
                    ],
                    'CHECKOUT' => [
                        'target' => 'checkout',
                        'guards' => 'hasItems',
                    ],
                ],
            ],
            'checkout' => [
                'entry' => 'calculateTotals',
                'on' => [
                    'APPLY_COUPON' => [
                        'calculators' => 'validateCoupon',
                        'actions' => 'applyCoupon',
                    ],
                    'PAY' => [
                        [
                            'target' => 'paid',
                            'guards' => ['hasStock', 'paymentValid'],
                            'actions' => ['processPayment', 'reserveStock'],
                        ],
                        [
                            'target' => 'payment_failed',
                            'actions' => 'logFailure',
                        ],
                    ],
                    'BACK' => 'cart',
                ],
            ],
            'paid' => [
                'entry' => 'sendConfirmation',
                'on' => [
                    'SHIP' => [
                        'target' => 'shipped',
                        'actions' => 'createShipment',
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
                'result' => 'orderSummary',
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
