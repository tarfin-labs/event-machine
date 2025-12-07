# StateDefinition API

Represents a state configuration within a machine.

## Class Definition

```php
namespace Tarfinlabs\EventMachine\Definition;

class StateDefinition
```

## Properties

| Property | Type | Description |
|----------|------|-------------|
| `$machine` | `MachineDefinition` | Parent machine definition |
| `$parent` | `?StateDefinition` | Parent state (for nested states) |
| `$key` | `?string` | State key (local identifier) |
| `$id` | `string` | Full state ID (e.g., `machine.parent.state`) |
| `$path` | `array<string>` | Path segments from root |
| `$route` | `string` | Route string (without machine ID) |
| `$description` | `?string` | Human-readable description |
| `$order` | `int` | Order in ID map |
| `$stateDefinitions` | `?array<StateDefinition>` | Child states |
| `$type` | `StateDefinitionType` | State type enum |
| `$transitionDefinitions` | `?array<TransitionDefinition>` | Transitions |
| `$events` | `?array<string>` | Accepted event types |
| `$initialStateDefinition` | `?StateDefinition` | Initial child state |
| `$entry` | `?array` | Entry action definitions |
| `$exit` | `?array` | Exit action definitions |
| `$meta` | `?array` | Metadata |
| `$config` | `?array` | Raw configuration |

## Constructor

```php
public function __construct(
    ?array $config,
    ?array $options = null
)
```

**Parameters:**
- `$config` - State configuration array
- `$options` - Parent, machine, and key options

## Methods

### getStateDefinitionType()

Get the type of this state.

```php
public function getStateDefinitionType(): StateDefinitionType
```

**Returns:** `StateDefinitionType` enum (ATOMIC, COMPOUND, or FINAL)

### findInitialStateDefinition()

Find the initial child state.

```php
public function findInitialStateDefinition(): ?StateDefinition
```

**Returns:** `StateDefinition|null`

### initializeTransitions()

Initialize transitions for this state and children.

```php
public function initializeTransitions(): void
```

### collectUniqueEvents()

Get all unique event types accepted by this state.

```php
public function collectUniqueEvents(): ?array
```

**Returns:** Array of event type strings or null

### runEntryActions()

Execute entry actions for this state.

```php
public function runEntryActions(
    State $state,
    ?EventBehavior $eventBehavior = null
): void
```

**Parameters:**
- `$state` - Current state
- `$eventBehavior` - Triggering event

### runExitActions()

Execute exit actions for this state.

```php
public function runExitActions(State $state): void
```

**Parameters:**
- `$state` - Current state

## State Types

```php
enum StateDefinitionType: string
{
    case ATOMIC = 'atomic';      // Leaf state (no children)
    case COMPOUND = 'compound';  // Has child states
    case FINAL = 'final';        // Terminal state
}
```

## Configuration Options

### Basic State

```php
'pending' => [
    'description' => 'Order is pending',
    'meta' => ['priority' => 'normal'],
    'on' => [
        'SUBMIT' => 'processing',
    ],
]
```

### State with Actions

```php
'processing' => [
    'entry' => 'logEntry',                    // Single action
    'exit' => ['cleanup', 'notifyExit'],      // Multiple actions
    'on' => [
        'COMPLETE' => 'completed',
    ],
]
```

### Final State

```php
'completed' => [
    'type' => 'final',
    'result' => 'calculateResult',  // Result behavior
]
```

### Compound State

```php
'checkout' => [
    'initial' => 'shipping',
    'states' => [
        'shipping' => [
            'on' => ['CONTINUE' => 'payment'],
        ],
        'payment' => [
            'on' => ['CONTINUE' => 'review'],
        ],
        'review' => [
            'on' => ['CONFIRM' => '#checkout.confirmed'],
        ],
    ],
]
```

## Usage Examples

### Accessing State Properties

```php
$machine = OrderMachine::create();
$def = $machine->state->currentStateDefinition;

// Basic info
echo $def->id;          // 'order.pending'
echo $def->key;         // 'pending'
echo $def->description; // 'Order is pending'

// Type checking
if ($def->type === StateDefinitionType::FINAL) {
    // Handle final state
}

// Path info
print_r($def->path);    // ['pending']
echo $def->route;       // 'pending'

// Metadata
if ($def->meta) {
    $priority = $def->meta['priority'] ?? 'normal';
}
```

### Checking Transitions

```php
$def = $machine->state->currentStateDefinition;

// Get available transitions
if ($def->transitionDefinitions) {
    foreach ($def->transitionDefinitions as $eventType => $transition) {
        echo "Can handle: {$eventType}";
    }
}

// Check specific event
$canSubmit = isset($def->transitionDefinitions['SUBMIT']);
```

### Navigating Hierarchy

```php
$def = $machine->state->currentStateDefinition;

// Get parent state
if ($def->parent) {
    echo "Parent: {$def->parent->key}";
}

// Get child states (compound states only)
if ($def->stateDefinitions) {
    foreach ($def->stateDefinitions as $key => $child) {
        echo "Child: {$key}";
    }
}

// Get initial child state
if ($def->initialStateDefinition) {
    echo "Initial: {$def->initialStateDefinition->key}";
}
```

### Finding States in Machine

```php
$definition = OrderMachine::definition();

// Get state by ID
$pendingState = $definition->idMap['order.pending'];

// Get nested state
$paymentState = $definition->idMap['order.checkout.payment'];

// Find by string path
$state = $definition->getNearestStateDefinitionByString('checkout.payment');
```

## State Configuration Examples

### Simple State Machine

```php
'states' => [
    'idle' => [
        'on' => ['START' => 'running'],
    ],
    'running' => [
        'on' => ['STOP' => 'idle'],
    ],
]
```

### With Entry/Exit Actions

```php
'states' => [
    'loading' => [
        'entry' => 'startLoader',
        'exit' => 'stopLoader',
        'on' => [
            'SUCCESS' => 'success',
            'FAILURE' => 'error',
        ],
    ],
]
```

### Nested States

```php
'states' => [
    'checkout' => [
        'initial' => 'shipping',
        'states' => [
            'shipping' => [
                'on' => ['NEXT' => 'payment'],
            ],
            'payment' => [
                'entry' => 'initPayment',
                'on' => ['NEXT' => 'review'],
            ],
            'review' => [
                'on' => ['CONFIRM' => 'confirmed'],
            ],
            'confirmed' => [
                'type' => 'final',
            ],
        ],
    ],
]
```

### With @always Transitions

```php
'states' => [
    'checking' => [
        'on' => [
            '@always' => [
                ['target' => 'valid', 'guards' => 'isValid'],
                ['target' => 'invalid'],
            ],
        ],
    ],
]
```

## Related

- [MachineDefinition](/api-reference/machine-definition) - Machine blueprint
- [TransitionDefinition](/api-reference/transition-definition) - Transition rules
- [State](/api-reference/state) - Runtime state
