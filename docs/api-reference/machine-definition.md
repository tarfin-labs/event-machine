# MachineDefinition API

The blueprint class for defining state machines.

## Class Definition

```php
namespace Tarfinlabs\EventMachine\Definition;

class MachineDefinition
```

## Constants

| Constant | Type | Value | Description |
|----------|------|-------|-------------|
| `DEFAULT_ID` | string | `'machine'` | Default ID for root machine |
| `STATE_DELIMITER` | string | `'.'` | Delimiter for state paths |

## Properties

| Property | Type | Description |
|----------|------|-------------|
| `$root` | `StateDefinition` | Root state definition |
| `$idMap` | `array<StateDefinition>` | Map of state definitions by ID |
| `$stateDefinitions` | `?array<StateDefinition>` | Child state definitions |
| `$events` | `?array<string>` | Accepted event types |
| `$eventQueue` | `Collection` | Queue for raised events |
| `$initialStateDefinition` | `?StateDefinition` | Initial state definition |
| `$scenariosEnabled` | `bool` | Whether scenarios are enabled |
| `$shouldPersist` | `bool` | Whether to persist events |
| `$config` | `?array` | Raw configuration array |
| `$behavior` | `?array` | Behavior implementations |
| `$id` | `string` | Machine identifier |
| `$version` | `?string` | Machine version |
| `$scenarios` | `?array` | Scenario configurations |
| `$delimiter` | `string` | Path delimiter |

## Static Methods

### define()

Create a new machine definition.

```php
public static function define(
    ?array $config = null,
    ?array $behavior = null,
    ?array $scenarios = null
): self
```

**Parameters:**
- `$config` - Machine configuration array
- `$behavior` - Behavior implementations (actions, guards, etc.)
- `$scenarios` - Alternative state configurations

**Returns:** `MachineDefinition`

**Example:**
```php
$definition = MachineDefinition::define(
    config: [
        'id' => 'order',
        'initial' => 'pending',
        'context' => ['total' => 0],
        'states' => [
            'pending' => [
                'on' => ['SUBMIT' => 'processing'],
            ],
            'processing' => [],
        ],
    ],
    behavior: [
        'actions' => [
            'logSubmit' => fn($ctx) => logger()->info('Submitted'),
        ],
    ],
);
```

## Instance Methods

### getInitialState()

Get the initial state for the machine.

```php
public function getInitialState(
    EventBehavior|array|null $event = null
): ?State
```

**Parameters:**
- `$event` - Optional event to initialize with

**Returns:** `State|null`

### transition()

Transition the machine to a new state based on an event.

```php
public function transition(
    EventBehavior|array $event,
    ?State $state = null
): State
```

**Parameters:**
- `$event` - Event triggering the transition
- `$state` - Current state (uses initial if null)

**Returns:** `State`

**Example:**
```php
$state = $definition->getInitialState();
$newState = $definition->transition(['type' => 'SUBMIT'], $state);
```

### getInvokableBehavior()

Retrieve a behavior instance by name.

```php
public function getInvokableBehavior(
    string $behaviorDefinition,
    BehaviorType $behaviorType
): null|callable|InvokableBehavior
```

**Parameters:**
- `$behaviorDefinition` - Behavior class or registered name
- `$behaviorType` - Type (Action, Guard, Calculator, etc.)

**Returns:** Callable behavior or null

### getNearestStateDefinitionByString()

Find a state definition by string path.

```php
public function getNearestStateDefinitionByString(
    string $stateDefinitionId
): ?StateDefinition
```

**Parameters:**
- `$stateDefinitionId` - State path (e.g., `'pending'` or `'checkout.payment'`)

**Returns:** `StateDefinition|null`

### runAction()

Execute an action behavior.

```php
public function runAction(
    string $actionDefinition,
    State $state,
    ?EventBehavior $eventBehavior = null
): void
```

**Parameters:**
- `$actionDefinition` - Action class or registered name
- `$state` - Current state
- `$eventBehavior` - Triggering event

### initializeContextFromState()

Initialize context manager from state or config.

```php
public function initializeContextFromState(
    ?State $state = null
): ContextManager
```

**Parameters:**
- `$state` - State to get context from (optional)

**Returns:** `ContextManager`

### checkFinalStatesForTransitions()

Validate that final states have no outgoing transitions.

```php
public function checkFinalStatesForTransitions(): void
```

**Throws:** `InvalidFinalStateDefinitionException`

## Configuration Options

### Machine Config

```php
[
    'id' => 'machine_name',           // Machine identifier
    'version' => '1.0.0',             // Optional version
    'initial' => 'idle',              // Initial state key
    'delimiter' => '.',               // State path delimiter
    'should_persist' => true,         // Enable persistence
    'scenarios_enabled' => false,     // Enable scenarios
    'context' => [...],               // Initial context data
    'states' => [...],                // State definitions
]
```

### Behavior Config

```php
[
    'actions' => [
        'actionName' => ActionClass::class,
        'inlineAction' => fn($ctx) => $ctx->value = 1,
    ],
    'guards' => [
        'guardName' => GuardClass::class,
    ],
    'calculators' => [
        'calcName' => CalculatorClass::class,
    ],
    'events' => [
        'EVENT_TYPE' => EventClass::class,
    ],
    'results' => [
        'machine.final_state' => ResultClass::class,
    ],
    'context' => CustomContextClass::class,
]
```

## Usage Examples

### Basic Definition

```php
$definition = MachineDefinition::define(
    config: [
        'initial' => 'idle',
        'states' => [
            'idle' => [
                'on' => ['START' => 'running'],
            ],
            'running' => [
                'on' => ['STOP' => 'idle'],
            ],
        ],
    ],
);

$state = $definition->getInitialState();
$state = $definition->transition(['type' => 'START'], $state);
```

### With Behaviors

```php
$definition = MachineDefinition::define(
    config: [
        'initial' => 'idle',
        'context' => ['count' => 0],
        'states' => [
            'idle' => [
                'on' => [
                    'INCREMENT' => [
                        'guards' => 'canIncrement',
                        'actions' => 'incrementCount',
                    ],
                ],
            ],
        ],
    ],
    behavior: [
        'guards' => [
            'canIncrement' => fn($ctx) => $ctx->count < 10,
        ],
        'actions' => [
            'incrementCount' => fn($ctx) => $ctx->count++,
        ],
    ],
);
```

## Related

- [Machine](/api-reference/machine) - Runtime machine instance
- [State](/api-reference/state) - State representation
- [StateDefinition](/api-reference/state-definition) - State configuration
