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
        'context' => [...],
        'states' => [...],
        'should_persist' => true,
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
| `context` | array\|class | `[]` | Initial context or ContextManager class |
| `states` | array | Required | State definitions |
| `on` | array | `null` | Root-level transitions |
| `should_persist` | bool | `true` | Enable database persistence |
| ~~`scenarios_enabled`~~ | ~~bool~~ | ~~`false`~~ | ~~Deprecated — use `config/machine.php` `scenarios.enabled` instead~~ |

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
        'outputs' => [
            'orderSummaryOutput' => OrderSummaryOutput::class,
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
| `outputs` | Final state output computation |
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
| `output` | string\|array | Output behavior for final states |
| `initial` | string | Initial child state |
| `states` | array | Child state definitions |
| `machine` | string (FQCN) | Child machine class for delegation |
| `input` | string\|array\|Closure | Data to pass to child machine. MachineInput FQCN, array, or closure. (renamed from `with` in v9) |
| `failure` | string (FQCN) | MachineFailure class for typed error data on child `@fail` |
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

Scenarios are configured in `config/machine.php`, not in the machine definition:

```php ignore
// config/machine.php
'scenarios' => [
    'enabled' => env('MACHINE_SCENARIOS_ENABLED', false),
],
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `scenarios.enabled` | `bool` | `false` | Enable scenario system. Set to `true` in staging only. |

When enabled, scenarios provide behavior overrides for QA and staging environments via `MachineScenario` classes. See [Scenarios](/advanced/scenarios) for full documentation.

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
                'output' => 'orderSummaryOutput',
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
        'outputs' => [
            'orderSummaryOutput' => OrderSummaryOutput::class,
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

## Syntax Shorthands

EventMachine accepts both verbose and shorthand forms for common configuration patterns. **Prefer the short form when no extra options are needed** — it's easier to read and reduces noise.

### Transitions

```php ignore
// ✅ Short — target only
'SUBMIT' => 'processing',

// Verbose equivalent
'SUBMIT' => ['target' => 'processing'],

// Use verbose only when adding guards, actions, or calculators
'SUBMIT' => [
    'target'  => 'processing',
    'guards'  => IsValidGuard::class,
    'actions' => LogAction::class,
],
```

### Entry / Exit Actions

```php ignore
// ✅ Short — single action
'entry' => InitAction::class,
'exit'  => CleanupAction::class,

// Multiple actions require array
'entry' => [InitAction::class, NotifyAction::class],
```

### Guards, Actions, Calculators on Transitions

```php ignore
// ✅ Short — single behavior
'guards'      => IsValidGuard::class,
'actions'     => LogAction::class,
'calculators' => TotalCalculator::class,

// Multiple behaviors require array
'guards' => [IsValidGuard::class, HasBalanceGuard::class],
```

### @done / @fail (Child Delegation)

```php ignore
// ✅ Short — target only
'@done' => 'completed',
'@fail' => 'failed',

// Verbose — with actions
'@done' => [
    'target'  => 'completed',
    'actions' => CaptureOutputAction::class,
],

// Per-final-state routing
'@done.approved' => 'processing',
'@done.rejected' => 'declined',
```

### Queue (Async Delegation)

```php ignore
// ✅ Short — default queue
'queue' => true,

// Named queue
'queue' => 'child-queue',

// Full config
'queue' => [
    'queue'      => 'child-queue',
    'connection' => 'redis',
    'retry'      => 3,
],
```

### Quick Reference

| Element | Short Form | Verbose Form |
|---------|-----------|-------------|
| Transition target | `'EVENT' => 'state'` | `'EVENT' => ['target' => 'state']` |
| Forbidden event | `'EVENT' => null` | — |
| Single entry action | `'entry' => Action::class` | `'entry' => [Action::class]` |
| Single exit action | `'exit' => Action::class` | `'exit' => [Action::class]` |
| Single guard | `'guards' => Guard::class` | `'guards' => [Guard::class]` |
| Single action | `'actions' => Action::class` | `'actions' => [Action::class]` |
| Single calculator | `'calculators' => Calc::class` | `'calculators' => [Calc::class]` |
| @done target | `'@done' => 'state'` | `'@done' => ['target' => 'state']` |
| @fail target | `'@fail' => 'state'` | `'@fail' => ['target' => 'state']` |
| Default queue | `'queue' => true` | `'queue' => ['queue' => null]` |
| Named queue | `'queue' => 'name'` | `'queue' => ['queue' => 'name']` |

::: tip Rule of Thumb
If you're only specifying a **target** or a **single value**, use the short form. Switch to verbose only when you need extra options (guards, actions, calculators, description).
:::
