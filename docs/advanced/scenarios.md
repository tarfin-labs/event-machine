# Scenarios

Scenarios allow you to define alternative state machine configurations that can be activated at runtime. They're useful for A/B testing, feature flags, and environment-specific behavior.

## Basic Usage

### Enabling Scenarios

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
MachineDefinition::define(
    config: [
        'initial' => 'stateA',
        'scenarios_enabled' => true,  // Enable scenarios
        'states' => [
            'stateA' => [
                'on' => ['EVENT' => 'stateB'],
            ],
            'stateB' => [],
        ],
    ],
    scenarios: [
        'test' => [
            'stateA' => [
                'on' => [
                    'EVENT' => 'stateC',  // Different target in 'test' scenario
                ],
            ],
        ],
        'beta' => [
            'stateA' => [
                'on' => [
                    'EVENT' => [
                        'target' => 'stateB',
                        'actions' => 'betaAction',  // Additional action
                    ],
                ],
            ],
        ],
    ],
);
```

### Activating a Scenario

Include `scenarioType` in the event payload:

<!-- doctest-attr: ignore -->
```php
// Normal flow
$machine->send(['type' => 'EVENT']);
// Goes to stateB

// Test scenario
$machine->send([
    'type' => 'EVENT',
    'payload' => ['scenarioType' => 'test'],
]);
// Goes to stateC

// Beta scenario
$machine->send([
    'type' => 'EVENT',
    'payload' => ['scenarioType' => 'beta'],
]);
// Goes to stateB with betaAction
```

## Scenario Configuration

Scenarios can override:

### Transitions

<!-- doctest-attr: ignore -->
```php
'scenarios' => [
    'express' => [
        'pending' => [
            'on' => [
                'SUBMIT' => 'express_processing',  // Different target
            ],
        ],
    ],
],
```

### Actions

<!-- doctest-attr: ignore -->
```php
'scenarios' => [
    'debug' => [
        'processing' => [
            'on' => [
                'COMPLETE' => [
                    'target' => 'completed',
                    'actions' => ['logDebug', 'sendNotification'],
                ],
            ],
        ],
    ],
],
```

### Entry/Exit Actions

<!-- doctest-attr: ignore -->
```php
'scenarios' => [
    'monitoring' => [
        'active' => [
            'entry' => ['defaultEntry', 'recordMetrics'],
            'exit' => ['defaultExit', 'flushMetrics'],
        ],
    ],
],
```

### Guards

<!-- doctest-attr: ignore -->
```php
'scenarios' => [
    'lenient' => [
        'validating' => [
            'on' => [
                'SUBMIT' => [
                    'target' => 'submitted',
                    'guards' => 'lenientValidation',  // Less strict
                ],
            ],
        ],
    ],
],
```

## Practical Examples

### A/B Testing

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
MachineDefinition::define(
    config: [
        'id' => 'checkout',
        'initial' => 'cart',
        'scenarios_enabled' => true,
        'states' => [
            'cart' => [
                'on' => ['CHECKOUT' => 'shipping'],
            ],
            'shipping' => [
                'on' => ['CONTINUE' => 'payment'],
            ],
            'payment' => [
                'on' => ['PAY' => 'confirmation'],
            ],
            'confirmation' => ['type' => 'final'],
            'express_checkout' => [
                'on' => ['COMPLETE' => 'confirmation'],
            ],
        ],
    ],
    scenarios: [
        'express_flow' => [
            'cart' => [
                'on' => [
                    'CHECKOUT' => 'express_checkout',  // Skip shipping/payment
                ],
            ],
        ],
        'upsell_flow' => [
            'payment' => [
                'on' => [
                    'PAY' => [
                        'target' => 'confirmation',
                        'actions' => 'showUpsellOffer',
                    ],
                ],
            ],
        ],
    ],
);

// Usage based on user segment
$scenario = $user->isInTestGroup('express') ? 'express_flow' : null;

$machine->send([
    'type' => 'CHECKOUT',
    'payload' => [
        'scenarioType' => $scenario,
    ],
]);
```

### Feature Flags

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
MachineDefinition::define(
    config: [
        'scenarios_enabled' => true,
        'states' => [
            'processing' => [
                'on' => [
                    'COMPLETE' => 'legacy_completion',
                ],
            ],
            'legacy_completion' => ['type' => 'final'],
            'new_completion' => [
                'entry' => 'enhancedCompletionFlow',
                'type' => 'final',
            ],
        ],
    ],
    scenarios: [
        'new_completion_feature' => [
            'processing' => [
                'on' => [
                    'COMPLETE' => 'new_completion',
                ],
            ],
        ],
    ],
);

// Activate based on feature flag
$machine->send([
    'type' => 'COMPLETE',
    'payload' => [
        'scenarioType' => feature('new_completion') ? 'new_completion_feature' : null,
    ],
]);
```

### Environment-Specific Behavior

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
MachineDefinition::define(
    config: [
        'scenarios_enabled' => true,
        'states' => [
            'sending' => [
                'entry' => 'sendEmail',
                'on' => ['SENT' => 'completed'],
            ],
        ],
    ],
    scenarios: [
        'testing' => [
            'sending' => [
                'entry' => 'mockSendEmail',  // Don't actually send
            ],
        ],
        'staging' => [
            'sending' => [
                'entry' => ['sendEmail', 'logToSlack'],  // Extra logging
            ],
        ],
    ],
);

// Activate based on environment
$scenario = match (app()->environment()) {
    'testing' => 'testing',
    'staging' => 'staging',
    default => null,
};

$machine->send([
    'type' => 'SEND',
    'payload' => ['scenarioType' => $scenario],
]);
```

### Multi-Tenant Customization

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
MachineDefinition::define(
    config: [
        'id' => 'approval',
        'scenarios_enabled' => true,
        'states' => [
            'pending' => [
                'on' => ['APPROVE' => 'approved'],
            ],
            'approved' => ['type' => 'final'],
            'dual_approved' => ['type' => 'final'],
        ],
    ],
    scenarios: [
        'tenant_enterprise' => [
            'pending' => [
                'on' => [
                    'APPROVE' => [
                        'target' => 'awaiting_second_approval',
                        'guards' => 'isFirstApproval',
                    ],
                ],
            ],
            'awaiting_second_approval' => [
                'on' => [
                    'APPROVE' => 'dual_approved',
                ],
            ],
        ],
    ],
);

// Activate based on tenant
$scenario = $tenant->requiresDualApproval() ? 'tenant_enterprise' : null;
```

## Complete Example

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Actor\Machine; // [!code hide]
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
class OrderMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id' => 'order',
                'initial' => 'pending',
                'scenarios_enabled' => true,
                'context' => ['count' => 1],
                'states' => [
                    'pending' => [
                        'on' => [
                            'SUBMIT' => [
                                'target' => 'processing',
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'processing' => [
                        'on' => ['COMPLETE' => 'completed'],
                    ],
                    'completed' => ['type' => 'final'],
                    'fast_completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'incrementAction' => fn($ctx) => $ctx->count++,
                    'decrementAction' => fn($ctx) => $ctx->count--,
                ],
            ],
            scenarios: [
                'test' => [
                    'pending' => [
                        'on' => [
                            'SUBMIT' => [
                                'target' => 'fast_completed',  // Skip processing
                                'actions' => 'decrementAction', // Different action
                            ],
                        ],
                        'exit' => ['decrementAction'],  // Additional exit action
                    ],
                ],
            ],
        );
    }
}

// Usage
$machine = OrderMachine::create();

// Normal flow
$machine->send(['type' => 'SUBMIT']);
// count = 2, state = processing

// Test scenario
$testMachine = OrderMachine::create();
$testMachine->send([
    'type' => 'SUBMIT',
    'payload' => ['scenarioType' => 'test'],
]);
// count = -1 (decremented twice: exit + action), state = fast_completed
```

## Testing with Scenarios

<!-- doctest-attr: ignore -->
```php
it('uses test scenario when specified', function () {
    $machine = OrderMachine::create();

    $machine->send([
        'type' => 'SUBMIT',
        'payload' => ['scenarioType' => 'test'],
    ]);

    expect($machine->state->matches('fast_completed'))->toBeTrue()
        ->and($machine->state->context->count)->toBe(-1);
});

it('uses default flow without scenario', function () {
    $machine = OrderMachine::create();

    $machine->send(['type' => 'SUBMIT']);

    expect($machine->state->matches('processing'))->toBeTrue()
        ->and($machine->state->context->count)->toBe(2);
});
```

## Best Practices

### 1. Use Descriptive Scenario Names

<!-- doctest-attr: ignore -->
```php
'scenarios' => [
    'ab_test_checkout_v2' => [...],
    'enterprise_tier' => [...],
    'staging_debug' => [...],
],
```

### 2. Document Scenario Differences

<!-- doctest-attr: ignore -->
```php
'scenarios' => [
    // Skips validation for testing
    'skip_validation' => [
        'validating' => [
            'on' => ['@always' => 'approved'],
        ],
    ],
],
```

### 3. Keep Scenarios Minimal

Override only what's necessary:

<!-- doctest-attr: ignore -->
```php
// Good - minimal override
'scenarios' => [
    'test' => [
        'pending' => [
            'on' => ['SUBMIT' => 'fast_track'],
        ],
    ],
],

// Avoid - duplicating entire configuration
```

### 4. Use Scenarios for Testing

<!-- doctest-attr: ignore -->
```php
// In tests
$machine->send([
    'type' => 'SUBMIT',
    'payload' => ['scenarioType' => 'test'],
]);
```
