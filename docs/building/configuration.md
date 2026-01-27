# Configuration

This guide covers all configuration options for defining state machines.

## Machine Configuration

The `config` array defines your machine's structure:

```php
MachineDefinition::define(
    config: [
        'id' => 'order',
        'version' => '1.0.0',
        'initial' => 'pending',
        'delimiter' => '.',
        'context' => [...],
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
| `context` | array\|class | `[]` | Initial context or ContextManager class |
| `states` | array | Required | State definitions |
| `on` | array | `null` | Root-level transitions |
| `should_persist` | bool | `true` | Enable database persistence |
| `scenarios_enabled` | bool | `false` | Enable scenario branching |

### Machine ID

Identifies the machine for persistence and debugging:

```php
'id' => 'checkout-flow',
```

State IDs are prefixed with the machine ID:
- `checkout-flow.cart`
- `checkout-flow.payment`

### Version

Track machine versions for migrations:

```php
'version' => '2.0.0',
```

### Initial State

Specify which state the machine starts in:

```php
'initial' => 'idle',
```

If not specified, the first state in the `states` array is used.

### Delimiter

Customize the path separator for nested state IDs:

```php
'delimiter' => '/',  // Results in: order/checkout/payment
```

Default is `.` (dot): `order.checkout.payment`

## Behavior Configuration

The `behavior` array maps names to implementations:

```php
MachineDefinition::define(
    config: [...],
    behavior: [
        'actions' => [
            'sendEmail' => SendEmailAction::class,
            'logEvent' => fn (ContextManager $context) => logger()->info('Event'),
        ],
        'guards' => [
            'isAuthenticated' => IsAuthenticatedGuard::class,
            'hasPermission' => fn (ContextManager $context) => $context->get('role') === 'admin',
        ],
        'calculators' => [
            'computeTotal' => ComputeTotalCalculator::class,
        ],
        'events' => [
            'ADD_ITEM' => AddItemEvent::class,
        ],
        'results' => [
            'orderSummary' => OrderSummaryResult::class,
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

```php
'actions' => [
    // Class reference
    'sendEmail' => SendEmailAction::class,

    // Inline closure
    'logEvent' => function (ContextManager $context): void {
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

```php
'processing' => [
    'description' => 'Order is being processed',
    'entry' => 'startProcessing',
    'exit' => 'cleanup',
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

```php
'SUBMIT' => [
    'target' => 'submitted',
    'guards' => ['isValid', 'canSubmit'],
    'calculators' => 'prepareData',
    'actions' => ['validate', 'save', 'notify'],
    'description' => 'Submit the form for review',
],
```

## Persistence Configuration

### Disabling Persistence

For ephemeral machines:

```php
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

```php
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

```php
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

```php
// Throws: Invalid root level configuration keys: invalid_key
'invalid_key' => 'value',
```

### Invalid State Type

```php
// Throws: State 'foo' has invalid type: invalid
'foo' => ['type' => 'invalid'],
```

### Final State Constraints

```php
// Throws: Final state 'done' cannot have transitions
'done' => [
    'type' => 'final',
    'on' => ['RESTART' => 'initial'],  // Not allowed
],
```

### Guarded Transition Order

```php
// Throws: Default condition must be last
'PAY' => [
    ['target' => 'failed'],           // No guard - must be last!
    ['target' => 'paid', 'guards' => 'isValid'],
],
```

## Complete Configuration Example

```php
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
                    'ADD_ITEM' => ['actions' => 'addItem'],
                    'CHECKOUT' => [
                        'target' => 'checkout',
                        'guards' => 'hasItems',
                    ],
                ],
            ],
            'checkout' => [
                'initial' => 'shipping',
                'states' => [
                    'shipping' => [
                        'entry' => 'loadShippingOptions',
                        'on' => [
                            'SET_ADDRESS' => ['actions' => 'setAddress'],
                            'CONTINUE' => [
                                'target' => 'payment',
                                'guards' => 'hasAddress',
                            ],
                        ],
                    ],
                    'payment' => [
                        'entry' => 'loadPaymentMethods',
                        'on' => [
                            'PAY' => [
                                [
                                    'target' => 'confirmed',
                                    'guards' => 'paymentValid',
                                    'actions' => ['processPayment', 'reserveStock'],
                                ],
                                [
                                    'target' => 'payment',
                                    'actions' => 'showPaymentError',
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
                'entry' => 'startFulfillment',
                'on' => [
                    'SHIP' => 'shipped',
                ],
            ],
            'shipped' => [
                'entry' => 'sendTrackingEmail',
                'on' => [
                    'DELIVER' => 'delivered',
                ],
            ],
            'delivered' => [
                'type' => 'final',
                'entry' => 'sendDeliveryConfirmation',
                'result' => 'orderSummary',
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
            'addItem' => AddItemAction::class,
            'setAddress' => SetAddressAction::class,
            'processPayment' => ProcessPaymentAction::class,
            'reserveStock' => ReserveStockAction::class,
            'startFulfillment' => StartFulfillmentAction::class,
            'sendTrackingEmail' => SendTrackingEmailAction::class,
            'sendDeliveryConfirmation' => SendDeliveryConfirmationAction::class,
            'loadShippingOptions' => LoadShippingOptionsAction::class,
            'loadPaymentMethods' => LoadPaymentMethodsAction::class,
            'showPaymentError' => ShowPaymentErrorAction::class,
        ],
        'guards' => [
            'hasItems' => HasItemsGuard::class,
            'hasAddress' => HasAddressGuard::class,
            'paymentValid' => PaymentValidGuard::class,
        ],
        'results' => [
            'orderSummary' => OrderSummaryResult::class,
        ],
    ],
);
```
