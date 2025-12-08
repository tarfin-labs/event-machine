# TransitionDefinition API

Represents a transition between states.

## Class Definition

```php
namespace Tarfinlabs\EventMachine\Definition;

class TransitionDefinition
```

## Properties

| Property | Type | Description |
|----------|------|-------------|
| `$transitionConfig` | `null\|string\|array` | Raw transition configuration |
| `$source` | `StateDefinition` | Source state |
| `$event` | `string` | Event type that triggers this transition |
| `$branches` | `?array` | Transition branches |
| `$description` | `?string` | Transition description |
| `$isGuarded` | `bool` | Whether transition has guards |
| `$isAlways` | `bool` | Whether this is an @always transition |

## Constructor

```php
public function __construct(
    null|string|array $transitionConfig,
    StateDefinition $source,
    string $event
)
```

**Parameters:**
- `$transitionConfig` - Transition configuration
- `$source` - Source state definition
- `$event` - Event type triggering this transition

## Methods

### getFirstValidTransitionBranch()

Get the first branch where all guards pass.

```php
public function getFirstValidTransitionBranch(
    EventBehavior $eventBehavior,
    State $state
): ?TransitionBranch
```

**Parameters:**
- `$eventBehavior` - Event being processed
- `$state` - Current state

**Returns:** `TransitionBranch|null`

### runCalculators()

Execute calculator behaviors for a branch.

```php
public function runCalculators(
    State $state,
    EventBehavior $eventBehavior,
    TransitionBranch $branch
): bool
```

**Parameters:**
- `$state` - Current state
- `$eventBehavior` - Event being processed
- `$branch` - Transition branch

**Returns:** `bool` - True if all calculators succeed

## TransitionBranch Class

Each transition has one or more branches.

### Properties

| Property | Type | Description |
|----------|------|-------------|
| `$target` | `?StateDefinition` | Target state |
| `$guards` | `?array` | Guard definitions |
| `$actions` | `?array` | Action definitions |
| `$calculators` | `?array` | Calculator definitions |
| `$transitionDefinition` | `TransitionDefinition` | Parent transition |

### Methods

#### runActions()

Execute actions for this branch.

```php
public function runActions(
    State $state,
    EventBehavior $eventBehavior
): void
```

## Configuration Formats

### Simple Target

```php
'on' => [
    'SUBMIT' => 'processing',
]
```

### With Actions

```php
'on' => [
    'SUBMIT' => [
        'target' => 'processing',
        'actions' => 'logSubmit',
    ],
]
```

### With Guards

```php
'on' => [
    'SUBMIT' => [
        'target' => 'processing',
        'guards' => 'hasItems',
        'actions' => 'processSubmit',
    ],
]
```

### With Calculators

```php
'on' => [
    'SUBMIT' => [
        'target' => 'processing',
        'calculators' => 'calculateTotal',
        'guards' => 'isValidTotal',
        'actions' => 'processSubmit',
    ],
]
```

### Multiple Actions/Guards

```php
'on' => [
    'SUBMIT' => [
        'target' => 'processing',
        'guards' => ['hasItems', 'hasPaymentMethod'],
        'actions' => ['logSubmit', 'sendNotification'],
    ],
]
```

### Multi-Path (Guarded)

```php
'on' => [
    'PROCESS' => [
        [
            'target' => 'express',
            'guards' => 'isExpress',
            'actions' => 'expressProcess',
        ],
        [
            'target' => 'standard',
            'guards' => 'isStandard',
        ],
        [
            'target' => 'pending',  // Fallback
        ],
    ],
]
```

### Self-Transition (No Target)

```php
'on' => [
    'INCREMENT' => [
        'actions' => 'incrementCount',
    ],
]
```

### @always Transition

```php
'on' => [
    '@always' => [
        [
            'target' => 'ready',
            'guards' => 'isReady',
        ],
        [
            'target' => 'loading',
        ],
    ],
]
```

## Execution Order

When a transition is triggered:

1. **Calculators** - Modify context before guard evaluation
2. **Guards** - Determine if transition should proceed
3. **Exit Actions** - Run on source state
4. **Transition Actions** - Run during transition
5. **Entry Actions** - Run on target state

```
Event Received
     │
     ▼
┌─────────────┐
│ Calculators │ ← Modify context
└─────────────┘
     │
     ▼
┌─────────────┐
│   Guards    │ ← Check conditions
└─────────────┘
     │ Pass
     ▼
┌─────────────┐
│ Exit Actions│ ← Source state exit
└─────────────┘
     │
     ▼
┌─────────────┐
│  Actions    │ ← Transition actions
└─────────────┘
     │
     ▼
┌─────────────┐
│Entry Actions│ ← Target state entry
└─────────────┘
     │
     ▼
  New State
```

## Usage Examples

### Accessing Transitions

```php
$definition = OrderMachine::definition();
$pendingState = $definition->idMap['order.pending'];

// Get all transitions
if ($pendingState->transitionDefinitions) {
    foreach ($pendingState->transitionDefinitions as $event => $transition) {
        echo "Event: {$event}";
        echo "Is guarded: " . ($transition->isGuarded ? 'Yes' : 'No');
        echo "Is always: " . ($transition->isAlways ? 'Yes' : 'No');

        foreach ($transition->branches as $branch) {
            if ($branch->target) {
                echo "Target: {$branch->target->key}";
            }
        }
    }
}
```

### Checking Available Events

```php
$def = $machine->state->currentStateDefinition;

$availableEvents = array_keys($def->transitionDefinitions ?? []);
// ['SUBMIT', 'CANCEL']
```

### Multi-Path Resolution

```php
// Given this configuration:
'PROCESS' => [
    ['target' => 'a', 'guards' => 'guardA'],
    ['target' => 'b', 'guards' => 'guardB'],
    ['target' => 'c'],  // Fallback
],

// When event is sent:
// 1. Check guardA - if passes, go to 'a'
// 2. If fails, check guardB - if passes, go to 'b'
// 3. If fails, go to 'c' (no guard = always passes)
```

## Guard Evaluation

Guards are evaluated for each branch in order:

```php
public function getFirstValidTransitionBranch(...)
{
    foreach ($this->branches as $branch) {
        // Run calculators first
        if (!$this->runCalculators($state, $event, $branch)) {
            return null;
        }

        // If no guards, this branch matches
        if (!isset($branch->guards)) {
            return $branch;
        }

        // Check all guards
        $allGuardsPassed = true;
        foreach ($branch->guards as $guard) {
            if (!$guardBehavior($state, $event)) {
                $allGuardsPassed = false;
                break;
            }
        }

        if ($allGuardsPassed) {
            return $branch;
        }
    }

    return null;
}
```

## Related

- [StateDefinition](/api-reference/state-definition) - State configuration
- [Guards](/behaviors/guards) - Guard behaviors
- [Actions](/behaviors/actions) - Action behaviors
- [Calculators](/behaviors/calculators) - Calculator behaviors
