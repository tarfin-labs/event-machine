# Configuration

This guide covers all configuration options for defining state machines.

## Machine Configuration

The `config` array defines your machine's structure:

```php ignore
MachineDefinition::define(
    config: [
        'id' => 'order',
        'version' => '1.0.0',
        'initial' => 'pending',
        'delimiter' => '.',
        'context' => OrderContext::class,
        'states' => [...],
        'should_persist' => true,
        'scenarios_enabled' => false,
    ],
);
```

### Root Configuration Keys

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `id` | string | `'machine'` | Unique identifier for the machine |
| `version` | string | `null` | Version string for tracking |
| `initial` | string | First state | Initial state name |
| `delimiter` | string | `'.'` | Path separator for state IDs |
| `context` | class-string | `null` | ContextManager subclass for typed context |
| `states` | array | Required | State definitions |
| `on` | array | `null` | Root-level transitions |
| `should_persist` | bool | `true` | Enable database persistence |
| `scenarios_enabled` | bool | `false` | Enable scenario branching |

### Machine ID

Identifies the machine for persistence and debugging:

```php ignore
'id' => 'checkout-flow',
```

State IDs are prefixed with the machine ID:
- `checkout-flow.cart`
- `checkout-flow.payment`

### Version

Track machine versions for migrations:

```php ignore
'version' => '2.0.0',
```

### Initial State

Specify which state the machine starts in:

```php ignore
'initial' => 'idle',
```

If not specified, the first state in the `states` array is used.

### Delimiter

Customize the path separator for nested state IDs:

```php ignore
'delimiter' => '/',  // Results in: order/checkout/payment
```

Default is `.` (dot): `order.checkout.payment`

## Behavior Configuration

The `behavior` array maps names to implementations:

```php ignore
MachineDefinition::define(
    config: [...],
    behavior: [
        'actions' => [
            'sendEmailAction' => SendEmailAction::class,
            'logEventAction' => fn (ContextManager $context) => logger()->info('Event'),
        ],
        'guards' => [
            'isAuthenticatedGuard' => IsAuthenticatedGuard::class,
            'hasPermissionGuard' => fn (ContextManager $context) => $context->get('role') === 'admin',
        ],
        'calculators' => [
            'computeTotalCalculator' => ComputeTotalCalculator::class,
        ],
        'events' => [
            'ADD_ITEM' => AddItemEvent::class,
        ],
        'results' => [
            'orderSummaryResult' => OrderSummaryResult::class,
        ],
        'context' => OrderContext::class,
    ],
);
```

### Behavior Types

| Type | Description |
|------|-------------|
| `actions` | Side effects during transitions |
| `guards` | Conditions controlling transitions |
| `calculators` | Context computations before guards |
| `events` | Custom event classes |
| `results` | Final state output computation |
| `context` | Custom ContextManager class |

### Inline vs Class Behaviors

Both approaches work:

```php ignore
'actions' => [
    // Class reference
    'sendEmailAction' => SendEmailAction::class,

    // Inline closure
    'logEventAction' => function (ContextManager $context): void {
        logger()->info('Event logged');
    },
],
```

## State Configuration

Each state supports these keys:

| Key | Type | Description |
|-----|------|-------------|
| `on` | array | Event-to-transition mappings |
| `entry` | string\|array | Actions on state entry |
| `exit` | string\|array | Actions on state exit |
| `type` | string | State type (`'final'`) |
| `result` | string | Result behavior for final states |
| `initial` | string | Initial child state |
| `states` | array | Child state definitions |
| `meta` | array | Custom metadata |
| `description` | string | Human-readable description |

```php ignore
'processing' => [
    'description' => 'Order is being processed',
    'entry' => 'startProcessingAction',
    'exit' => 'cleanupAction',
    'meta' => ['timeout' => 3600],
    'on' => [
        'COMPLETE' => 'done',
        'FAIL' => 'failed',
    ],
],
```

## Transition Configuration

Transitions can be simple strings or detailed arrays:

| Key | Type | Description |
|-----|------|-------------|
| `target` | string | Destination state |
| `actions` | string\|array | Actions during transition |
| `guards` | string\|array | Conditions to check |
| `calculators` | string\|array | Pre-guard computations |
| `description` | string | Human-readable description |

```php ignore
'SUBMIT' => [
    'target' => 'submitted',
    'guards' => ['isValidGuard', 'canSubmitGuard'],
    'calculators' => 'prepareDataCalculator',
    'actions' => ['validateAction', 'saveAction', 'notifyAction'],
    'description' => 'Submit the form for review',
],
```

## Persistence Configuration

### Disabling Persistence

For ephemeral machines:

```php ignore
'should_persist' => false,
```

Useful for:
- Testing
- Short-lived operations
- Memory-only state machines

### Default Behavior

With `should_persist => true` (default):
- Every transition creates a `MachineEvent` record
- State can be restored from any point
- Full history is maintained

## Scenarios Configuration

Enable dynamic state branching:

```php no_run
MachineDefinition::define(
    config: [
        'id' => 'payment',
        'initial' => 'pending',
        'scenarios_enabled' => true,
        'states' => [
            'pending' => [
                'on' => ['PAY' => 'processing'],
            ],
            'processing' => [],
            'completed' => ['type' => 'final'],
        ],
    ],
    scenarios: [
        'card' => [
            'processing' => [
                'on' => [
                    'AUTHORIZE' => 'completed',
                    'DECLINE' => 'failed',
                ],
            ],
        ],
        'bank_transfer' => [
            'processing' => [
                'on' => [
                    'CONFIRM' => 'completed',
                    'TIMEOUT' => 'failed',
                ],
            ],
        ],
    ],
);
```

Select scenario via event payload:

```php no_run
$machine->send([
    'type' => 'PAY',
    'payload' => [
        'scenarioType' => 'card',
    ],
]);
```

## Configuration Validation

EventMachine validates your configuration at definition time:

### Invalid Keys

```php ignore
// Throws: Invalid root level configuration keys: invalid_key
'invalid_key' => 'value',
```

### Invalid State Type

```php ignore
// Throws: State 'foo' has invalid type: invalid
'foo' => ['type' => 'invalid'],
```

### Final State Constraints

```php ignore
// Throws: Final state 'done' cannot have transitions
'done' => [
    'type' => 'final',
    'on' => ['RESTART' => 'initial'],  // Not allowed
],
```

### Guarded Transition Order

```php ignore
// Throws: Default condition must be last
'PAY' => [
    ['target' => 'failed'],           // No guard - must be last!
    ['target' => 'paid', 'guards' => 'isValid'],
],
```

## Complete Configuration Example

```php no_run
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

MachineDefinition::define(
    config: [
        'id' => 'order',
        'version' => '1.0.0',
        'initial' => 'cart',
        'delimiter' => '.',
        'context' => OrderContext::class,
        'should_persist' => true,
        'scenarios_enabled' => false,
        'states' => [
            'cart' => [
                'description' => 'Shopping cart',
                'meta' => ['icon' => 'shopping-cart'],
                'on' => [
                    'ADD_ITEM' => ['actions' => 'addItemAction'],
                    'CHECKOUT' => [
                        'target' => 'checkout',
                        'guards' => 'hasItemsGuard',
                    ],
                ],
            ],
            'checkout' => [
                'initial' => 'shipping',
                'states' => [
                    'shipping' => [
                        'entry' => 'loadShippingOptionsAction',
                        'on' => [
                            'SET_ADDRESS' => ['actions' => 'setAddressAction'],
                            'CONTINUE' => [
                                'target' => 'payment',
                                'guards' => 'hasAddressGuard',
                            ],
                        ],
                    ],
                    'payment' => [
                        'entry' => 'loadPaymentMethodsAction',
                        'on' => [
                            'PAY' => [
                                [
                                    'target' => 'confirmed',
                                    'guards' => 'paymentValidGuard',
                                    'actions' => ['processPaymentAction', 'reserveStockAction'],
                                ],
                                [
                                    'target' => 'payment',
                                    'actions' => 'showPaymentErrorAction',
                                ],
                            ],
                            'BACK' => 'shipping',
                        ],
                    ],
                    'confirmed' => [],
                ],
                'on' => [
                    'CANCEL' => 'cart',
                ],
            ],
            'processing' => [
                'entry' => 'startFulfillmentAction',
                'on' => [
                    'SHIP' => 'shipped',
                ],
            ],
            'shipped' => [
                'entry' => 'sendTrackingEmailAction',
                'on' => [
                    'DELIVER' => 'delivered',
                ],
            ],
            'delivered' => [
                'type' => 'final',
                'entry' => 'sendDeliveryConfirmationAction',
                'result' => 'orderSummaryResult',
                'meta' => ['completed' => true],
            ],
        ],
    ],
    behavior: [
        'context' => OrderContext::class,
        'events' => [
            'ADD_ITEM' => AddItemEvent::class,
            'SET_ADDRESS' => SetAddressEvent::class,
            'PAY' => PaymentEvent::class,
        ],
        'actions' => [
            'addItemAction' => AddItemAction::class,
            'setAddressAction' => SetAddressAction::class,
            'processPaymentAction' => ProcessPaymentAction::class,
            'reserveStockAction' => ReserveStockAction::class,
            'startFulfillmentAction' => StartFulfillmentAction::class,
            'sendTrackingEmailAction' => SendTrackingEmailAction::class,
            'sendDeliveryConfirmationAction' => SendDeliveryConfirmationAction::class,
            'loadShippingOptionsAction' => LoadShippingOptionsAction::class,
            'loadPaymentMethodsAction' => LoadPaymentMethodsAction::class,
            'showPaymentErrorAction' => ShowPaymentErrorAction::class,
        ],
        'guards' => [
            'hasItemsGuard' => HasItemsGuard::class,
            'hasAddressGuard' => HasAddressGuard::class,
            'paymentValidGuard' => PaymentValidGuard::class,
        ],
        'results' => [
            'orderSummaryResult' => OrderSummaryResult::class,
        ],
    ],
);
```

## Parallel Dispatch Configuration

Parallel dispatch runs region entry actions as concurrent queue jobs. Configure in `config/machine.php`:

```php ignore
return [
    'parallel_dispatch' => [
        'enabled'        => env('MACHINE_PARALLEL_DISPATCH_ENABLED', false),
        'queue'          => env('MACHINE_PARALLEL_DISPATCH_QUEUE', null),
        'lock_timeout'   => env('MACHINE_PARALLEL_DISPATCH_LOCK_TIMEOUT', 30),
        'lock_ttl'       => env('MACHINE_PARALLEL_DISPATCH_LOCK_TTL', 60),
        'job_timeout'    => env('MACHINE_PARALLEL_DISPATCH_JOB_TIMEOUT', 300),
        'job_tries'      => env('MACHINE_PARALLEL_DISPATCH_JOB_TRIES', 3),
        'job_backoff'    => env('MACHINE_PARALLEL_DISPATCH_JOB_BACKOFF', 30),
        'region_timeout' => env('MACHINE_PARALLEL_DISPATCH_REGION_TIMEOUT', 0),
    ],
];
```

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `false` | Enable concurrent dispatch of region entry actions |
| `queue` | `null` | Queue name for region jobs (null = default) |
| `lock_timeout` | `30` | Max seconds for blocking lock acquisition |
| `lock_ttl` | `60` | Lock auto-expiry for stale lock cleanup |
| `job_timeout` | `300` | Laravel job execution timeout (seconds) |
| `job_tries` | `3` | Max retry attempts for failed region jobs |
| `job_backoff` | `30` | Seconds between retry attempts |
| `region_timeout` | `0` | Seconds before stuck parallel state triggers `@fail` (0 = disabled) |

For details, see [Parallel Dispatch](/advanced/parallel-states/parallel-dispatch).

::: tip Testing
For testing configuration options like `should_persist`, see [Testing Overview](/testing/overview).
:::
