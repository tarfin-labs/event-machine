# States and Transitions

States and transitions are the fundamental building blocks of any state machine. Understanding how they work in EventMachine is crucial for building robust applications.

## States

A **state** represents a specific condition or mode that your system can be in. In EventMachine, states are more than just labels—they can contain rich behavior and configuration.

### Basic State Definition

```php
'states' => [
    'idle' => [
        // State configuration goes here
    ],
    'loading' => [
        'on' => [
            'SUCCESS' => 'success',
            'ERROR' => 'error'
        ]
    ],
    'success' => [],
    'error' => []
]
```

### State Properties

#### Entry and Exit Actions

Actions that run when entering or leaving a state:

```php
'states' => [
    'loading' => [
        'entry' => 'startLoading',    // Run when entering this state
        'exit' => 'cleanup',          // Run when leaving this state
        'on' => [
            'SUCCESS' => 'success'
        ]
    ]
]
```

#### Always Transitions

Transitions that fire immediately when entering a state:

```php
'states' => [
    'checkingAuth' => [
        'on' => [
            '@always' => [
                [
                    'target' => 'authenticated',
                    'guards' => 'isLoggedIn'
                ],
                [
                    'target' => 'unauthenticated'
                ]
            ]
        ]
    ]
]
```

The `@always` event is special—it fires automatically when the state is entered. Guards are evaluated in order, and the first one that passes (or the one without guards) is taken.

### Final States

States that represent the end of the machine's lifecycle:

```php
'states' => [
    'processing' => [
        'on' => [
            'COMPLETE' => 'done',
            'FAIL' => 'error'
        ]
    ],
    'done' => [
        'type' => 'final'  // This is a final state
    ],
    'error' => [
        'type' => 'final'
    ]
]
```

Final states cannot have outgoing transitions and represent terminal states of your workflow.

## Transitions

A **transition** defines how the machine moves from one state to another in response to an event.

### Simple Transitions

The most basic form - just specify the target state:

```php
'on' => [
    'NEXT' => 'nextState'
]
```

### Detailed Transitions

Transitions can have multiple properties:

```php
'on' => [
    'SUBMIT' => [
        'target' => 'submitted',     // Where to go
        'guards' => 'isValid',       // Condition to check
        'actions' => 'submitForm'    // What to do during transition
    ]
]
```

### Multiple Transitions for Same Event

You can have multiple possible transitions for the same event, evaluated in order:

```php
'on' => [
    'PROCESS' => [
        [
            'target' => 'premium',
            'guards' => 'isPremiumUser',
            'actions' => 'processPremium'
        ],
        [
            'target' => 'standard',
            'guards' => 'isStandardUser', 
            'actions' => 'processStandard'
        ],
        [
            'target' => 'error',
            'actions' => 'logError'
        ]
    ]
]
```

The first transition whose guards pass will be taken. If no guards are specified, the transition always passes.

### Self Transitions

Transitions that don't change state but execute actions:

```php
'active' => [
    'on' => [
        'UPDATE' => [
            'target' => 'active',  // Stay in same state
            'actions' => 'updateData'
        ]
    ]
]
```

Or use the shorter syntax:

```php
'active' => [
    'on' => [
        'UPDATE' => [
            'actions' => 'updateData'  // No target = stay in current state
        ]
    ]
]
```

## Hierarchical States

EventMachine supports nested states, allowing you to organize complex behavior hierarchically.

### Basic Hierarchy

```php
'states' => [
    'online' => [
        'initial' => 'idle',  // Default sub-state when entering 'online'
        'states' => [
            'idle' => [
                'on' => [
                    'START_WORK' => 'working'
                ]
            ],
            'working' => [
                'on' => [
                    'TAKE_BREAK' => 'idle'
                ]
            ]
        ],
        'on' => [
            'DISCONNECT' => 'offline'  // Available from any sub-state
        ]
    ],
    'offline' => [
        'on' => [
            'CONNECT' => 'online'
        ]
    ]
]
```

### State IDs in Hierarchy

States in hierarchies have dot-separated IDs:

- Root machine: `machine`
- Top-level state: `machine.online`
- Nested state: `machine.online.working`

### Accessing Parent Transitions

Child states inherit transitions from their parents. In the example above, both `idle` and `working` can handle the `DISCONNECT` event.

## Parallel States

EventMachine supports parallel states where multiple state machines can run simultaneously:

```php
'states' => [
    'active' => [
        'type' => 'parallel',
        'states' => [
            'upload' => [
                'initial' => 'idle',
                'states' => [
                    'idle' => [
                        'on' => ['START_UPLOAD' => 'uploading']
                    ],
                    'uploading' => [
                        'on' => ['UPLOAD_COMPLETE' => 'idle']
                    ]
                ]
            ],
            'download' => [
                'initial' => 'idle', 
                'states' => [
                    'idle' => [
                        'on' => ['START_DOWNLOAD' => 'downloading']
                    ],
                    'downloading' => [
                        'on' => ['DOWNLOAD_COMPLETE' => 'idle']
                    ]
                ]
            ]
        ]
    ]
]
```

Both upload and download state machines run simultaneously within the `active` state.

## History States

History states remember the last active sub-state when re-entering a parent state:

```php
'states' => [
    'player' => [
        'initial' => 'playing',
        'states' => [
            'playing' => [
                'on' => ['PAUSE' => 'paused']
            ],
            'paused' => [
                'on' => ['PLAY' => 'playing']
            ],
            'hist' => [
                'type' => 'history'  // Remembers last active state
            ]
        ],
        'on' => [
            'STOP' => 'stopped'
        ]
    ],
    'stopped' => [
        'on' => [
            'PLAY' => 'player.hist'  // Resume from where we left off
        ]
    ]
]
```

## State Matching

EventMachine provides powerful state matching capabilities:

### Exact Match

```php
$machine->state->matches('loading')  // true if current state is exactly 'loading'
```

### Hierarchical Match

```php
$machine->state->matches('online')    // true if in 'online' or any sub-state
$machine->state->matches('online.working')  // true only if in 'working' sub-state
```

### Multiple States

```php
$machine->state->matches(['loading', 'saving'])  // true if in either state
```

## State Events

States can react to special events:

### Entry Events

Fired when entering a state:

```php
'states' => [
    'loading' => [
        'entry' => [
            'startSpinner',
            'logEntry'
        ]
    ]
]
```

### Exit Events

Fired when leaving a state:

```php
'states' => [
    'editing' => [
        'exit' => 'saveChanges'
    ]
]
```

## Common Patterns

### State Guards

Use guards to conditionally enter states:

```php
'on' => [
    'SUBMIT' => [
        [
            'target' => 'premium_processing',
            'guards' => 'isPremiumUser'
        ],
        [
            'target' => 'standard_processing'
        ]
    ]
]
```

### Timeout Transitions

Implement timeouts using delayed events:

```php
'states' => [
    'waiting' => [
        'entry' => 'startTimer',
        'on' => [
            'TIMEOUT' => 'expired',
            'RESPONSE' => 'success'
        ]
    ]
]

// In your action
'actions' => [
    'startTimer' => function() {
        dispatch(new TimeoutJob())->delay(30); // 30 seconds
    }
]
```

### Loading States

A common pattern for async operations:

```php
'states' => [
    'idle' => [
        'on' => ['FETCH' => 'loading']
    ],
    'loading' => [
        'entry' => 'fetchData',
        'on' => [
            'SUCCESS' => 'success',
            'ERROR' => 'error'
        ]
    ],
    'success' => [
        'entry' => 'handleSuccess',
        'on' => ['RETRY' => 'loading']
    ],
    'error' => [
        'entry' => 'handleError',
        'on' => ['RETRY' => 'loading']
    ]
]
```

## Best Practices

### 1. **Keep States Focused**
Each state should represent a single, well-defined condition.

### 2. **Use Hierarchical States for Complexity**
When you have shared behavior, use parent states.

### 3. **Explicit Transitions**
Always be explicit about possible transitions. Avoid catch-all states.

### 4. **Consistent Naming**
Use consistent naming conventions for states and events.

### 5. **Document Complex Logic**
Use comments to explain complex transition logic.

## Next Steps

- [Events and Actions](./events-and-actions.md) - Learn about triggering transitions
- [Context Management](./context.md) - Manage data across state changes
- [Guards and Conditions](./guards.md) - Control when transitions can occur