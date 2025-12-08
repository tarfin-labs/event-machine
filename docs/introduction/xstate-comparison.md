# XState Comparison

EventMachine is heavily inspired by [XState](https://xstate.js.org/), the popular JavaScript state machine library. This guide helps developers familiar with XState understand EventMachine's syntax and concepts.

## Syntax Comparison

### Basic Machine Definition

::: code-group

```php [EventMachine]
MachineDefinition::define(
    config: [
        'id' => 'toggle',
        'initial' => 'inactive',
        'states' => [
            'inactive' => [
                'on' => ['TOGGLE' => 'active'],
            ],
            'active' => [
                'on' => ['TOGGLE' => 'inactive'],
            ],
        ],
    ],
);
```

```javascript [XState]
createMachine({
  id: 'toggle',
  initial: 'inactive',
  states: {
    inactive: {
      on: { TOGGLE: 'active' },
    },
    active: {
      on: { TOGGLE: 'inactive' },
    },
  },
});
```

:::

### Context

::: code-group

```php [EventMachine]
MachineDefinition::define(
    config: [
        'initial' => 'active',
        'context' => [
            'count' => 0,
        ],
        'states' => [
            'active' => [
                'on' => [
                    'INCREMENT' => ['actions' => 'increment'],
                ],
            ],
        ],
    ],
    behavior: [
        'actions' => [
            'increment' => fn($context) => $context->count++,
        ],
    ],
);
```

```javascript [XState]
createMachine({
  initial: 'active',
  context: {
    count: 0,
  },
  states: {
    active: {
      on: {
        INCREMENT: {
          actions: 'increment',
        },
      },
    },
  },
}, {
  actions: {
    increment: assign({ count: (ctx) => ctx.count + 1 }),
  },
});
```

:::

### Guards

::: code-group

```php [EventMachine]
MachineDefinition::define(
    config: [
        'initial' => 'idle',
        'context' => ['count' => 0],
        'states' => [
            'idle' => [
                'on' => [
                    'SUBMIT' => [
                        'target' => 'submitted',
                        'guards' => 'isValid',
                    ],
                ],
            ],
            'submitted' => [],
        ],
    ],
    behavior: [
        'guards' => [
            'isValid' => fn($context) => $context->count > 0,
        ],
    ],
);
```

```javascript [XState]
createMachine({
  initial: 'idle',
  context: { count: 0 },
  states: {
    idle: {
      on: {
        SUBMIT: {
          target: 'submitted',
          cond: 'isValid',
        },
      },
    },
    submitted: {},
  },
}, {
  guards: {
    isValid: (ctx) => ctx.count > 0,
  },
});
```

:::

### Guarded Transitions (Multiple Branches)

::: code-group

```php [EventMachine]
'on' => [
    'CHECK' => [
        ['target' => 'valid', 'guards' => 'isValid'],
        ['target' => 'invalid', 'guards' => 'isInvalid'],
        ['target' => 'unknown'], // fallback
    ],
],
```

```javascript [XState]
on: {
  CHECK: [
    { target: 'valid', cond: 'isValid' },
    { target: 'invalid', cond: 'isInvalid' },
    { target: 'unknown' }, // fallback
  ],
},
```

:::

### Entry and Exit Actions

::: code-group

```php [EventMachine]
'states' => [
    'loading' => [
        'entry' => ['startLoading', 'logEntry'],
        'exit' => 'stopLoading',
        'on' => ['LOADED' => 'success'],
    ],
],
```

```javascript [XState]
states: {
  loading: {
    entry: ['startLoading', 'logEntry'],
    exit: 'stopLoading',
    on: { LOADED: 'success' },
  },
},
```

:::

### Always Transitions (Eventless)

::: code-group

```php [EventMachine]
'states' => [
    'checking' => [
        'on' => [
            '@always' => [
                ['target' => 'valid', 'guards' => 'isValid'],
                ['target' => 'invalid'],
            ],
        ],
    ],
],
```

```javascript [XState]
states: {
  checking: {
    always: [
      { target: 'valid', cond: 'isValid' },
      { target: 'invalid' },
    ],
  },
},
```

:::

### Final States

::: code-group

```php [EventMachine]
'states' => [
    'completed' => [
        'type' => 'final',
        'result' => 'getFinalResult',
    ],
],
```

```javascript [XState]
states: {
  completed: {
    type: 'final',
    data: (ctx) => ctx.result,
  },
},
```

:::

## Key Differences

### 1. Behavior Registration

EventMachine separates configuration from behavior:

```php
MachineDefinition::define(
    config: [...],      // Structure
    behavior: [         // Implementation
        'actions' => [...],
        'guards' => [...],
    ],
);
```

XState uses a second argument to `createMachine`:

```javascript
createMachine(config, {
  actions: {...},
  guards: {...},
});
```

### 2. Class-Based Behaviors

EventMachine supports class-based behaviors with dependency injection:

```php
class IncrementAction extends ActionBehavior
{
    public function __construct(
        private readonly OrderService $orderService
    ) {}

    public function __invoke(ContextManager $context): void
    {
        $this->orderService->process($context->orderId);
        $context->count++;
    }
}

// In behavior registration
'actions' => [
    'increment' => IncrementAction::class,
],
```

### 3. Event Sourcing

EventMachine has built-in event sourcing:

```php
// All events are persisted automatically
$machine->send(['type' => 'SUBMIT']);

// Restore from any point
$machine = MyMachine::create(state: $rootEventId);
```

XState requires external solutions for event persistence.

### 4. Calculators (Unique to EventMachine)

Calculators run before guards and modify context:

```php
'on' => [
    'SUBMIT' => [
        'target' => 'processing',
        'calculators' => 'calculateTotal',
        'guards' => 'hasValidTotal',
        'actions' => 'createOrder',
    ],
],

'calculators' => [
    'calculateTotal' => fn($ctx) => $ctx->total = $ctx->items->sum('price'),
],
'guards' => [
    'hasValidTotal' => fn($ctx) => $ctx->total > 0,
],
```

### 5. Validation Guards

EventMachine provides special validation guards with error messages:

```php
class ValidateAmountGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = null;

    public function __invoke(ContextManager $context): bool
    {
        if ($context->amount < 0) {
            $this->errorMessage = 'Amount must be positive';
            return false;
        }
        return true;
    }
}
```

### 6. Raised Events

Use `$this->raise()` in actions to queue events:

```php
class ProcessAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->processed = true;
        $this->raise(['type' => 'PROCESSED']); // Queue event
    }
}
```

## Feature Comparison

| Feature | EventMachine | XState |
|---------|--------------|--------|
| Declarative Config | ✅ | ✅ |
| Guards | ✅ | ✅ |
| Actions | ✅ | ✅ |
| Entry/Exit Actions | ✅ | ✅ |
| Context | ✅ | ✅ |
| Hierarchical States | ✅ | ✅ |
| Final States | ✅ | ✅ |
| Always Transitions | ✅ (`@always`) | ✅ (`always`) |
| Parallel States | ❌ | ✅ |
| History States | ❌ | ✅ |
| Delayed Transitions | ❌ | ✅ |
| Invoke (Actors) | ❌ | ✅ |
| Event Sourcing | ✅ Built-in | ❌ |
| State Restoration | ✅ | ❌ |
| Calculators | ✅ | ❌ |
| Validation Guards | ✅ | ❌ |
| Class Behaviors + DI | ✅ | ❌ |
| Scenarios | ✅ | ❌ |

## Migration Tips

1. **Replace `cond` with `guards`**: XState's `cond` becomes `guards` in EventMachine
2. **Use behavior array**: Move action/guard implementations to the `behavior` parameter
3. **Consider class behaviors**: For complex logic, create dedicated behavior classes
4. **Leverage event sourcing**: Take advantage of built-in persistence
5. **Use calculators**: For pre-guard computations, use calculators instead of actions
