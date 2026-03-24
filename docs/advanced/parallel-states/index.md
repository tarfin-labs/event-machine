# Parallel States

Parallel states (also known as orthogonal states) allow multiple independent state regions to be active simultaneously. Each region processes events independently while sharing the same context.

**Related pages:**
- [Event Handling](./event-handling) - Events, entry/exit actions, `@done`, escape transitions
- [Persistence](./persistence) - Database storage and restoration
- [Parallel Dispatch](./parallel-dispatch) - Concurrent execution via queue jobs

## When to Use Parallel States

Parallel states are useful when:

- Multiple independent processes run concurrently (e.g., document editing + autosave status)
- You need to track multiple toggles or modes (e.g., bold, italic, underline formatting)
- A workflow has parallel tracks that must all complete (e.g., payment + shipping + documents)
- Different aspects of a system operate independently but belong to the same machine

## Basic Syntax

Define a parallel state by setting `type` to `'parallel'`:

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

MachineDefinition::define([
    'id' => 'editor',
    'initial' => 'active',
    'states' => [
        'active' => [
            'type' => 'parallel',  // Mark as parallel
            'states' => [
                'document' => [
                    'initial' => 'editing',
                    'states' => [
                        'editing' => [
                            'on' => ['SAVE' => 'saving'],
                        ],
                        'saving' => [
                            'on' => ['SAVED' => 'editing'],
                        ],
                    ],
                ],
                'format' => [
                    'initial' => 'normal',
                    'states' => [
                        'normal' => [
                            'on' => ['BOLD' => 'bold'],
                        ],
                        'bold' => [
                            'on' => ['NORMAL' => 'normal'],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);
```

```mermaid
stateDiagram-v2
    state active {
        state document {
            [*] --> editing
            editing --> saving : SAVE
            saving --> editing : SAVED
        }
        --
        state format {
            [*] --> normal
            normal --> bold : BOLD
            bold --> normal : NORMAL
        }
    }
    [*] --> active
```

## State Value Representation

In parallel states, `$state->value` is a flat array containing all active leaf state IDs:

<!-- doctest-attr: ignore -->
```php
$state = $definition->getInitialState();

// State value contains both active regions
echo json_encode($state->value); // [!code hide]
$state->value;
// [
//     'editor.active.document.editing',
//     'editor.active.format.normal',
// ]
```
<!-- doctest-json: ["editor.active.document.editing","editor.active.format.normal"] -->

### Checking Active States

Use the `matches()` method to check if a specific state is active:

<!-- doctest-attr: ignore -->
```php
// Check individual states
$state->matches('active.document.editing');  // => true
$state->matches('active.format.normal');     // => true

// Check multiple states at once with matchesAll()
$result = $state->matchesAll([ // [!code hide]
$state->matchesAll([  // [!code --]
    'active.document.editing',
    'active.format.bold',
]);  // false - format is in 'normal', not 'bold'
echo $result ? 'true' : 'false'; // [!code hide]

// Check if currently in a parallel state with isInParallelState()
$state->isInParallelState();  // => true
```
<!-- doctest-contains: false -->

::: warning Full Path Required
The `matches()` method requires the full path to the leaf state. Partial paths (like `active.document` without the leaf state) will return false. Always specify the complete path to the active leaf state.
:::

## Practical Example: Word Processor

A word processor with independent formatting toggles:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

$definition = MachineDefinition::define([
    'id' => 'word',
    'initial' => 'editing',
    'states' => [
        'editing' => [
            'type' => 'parallel',
            'states' => [
                'bold' => [
                    'initial' => 'off',
                    'states' => [
                        'off' => ['on' => ['TOGGLE_BOLD' => 'on']],
                        'on' => ['on' => ['TOGGLE_BOLD' => 'off']],
                    ],
                ],
                'italic' => [
                    'initial' => 'off',
                    'states' => [
                        'off' => ['on' => ['TOGGLE_ITALIC' => 'on']],
                        'on' => ['on' => ['TOGGLE_ITALIC' => 'off']],
                    ],
                ],
                'underline' => [
                    'initial' => 'off',
                    'states' => [
                        'off' => ['on' => ['TOGGLE_UNDERLINE' => 'on']],
                        'on' => ['on' => ['TOGGLE_UNDERLINE' => 'off']],
                    ],
                ],
                'list' => [
                    'initial' => 'none',
                    'states' => [
                        'none' => [
                            'on' => [
                                'BULLETS' => 'bulleted',
                                'NUMBERS' => 'numbered',
                            ],
                        ],
                        'bulleted' => [
                            'on' => [
                                'NONE' => 'none',
                                'NUMBERS' => 'numbered',
                            ],
                        ],
                        'numbered' => [
                            'on' => [
                                'BULLETS' => 'bulleted',
                                'NONE' => 'none',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);

$state = $definition->getInitialState();
// All formatting off, no list

$state = $definition->transition(['type' => 'TOGGLE_BOLD'], $state);
$state = $definition->transition(['type' => 'TOGGLE_ITALIC'], $state);
$state = $definition->transition(['type' => 'BULLETS'], $state);

$state->matches('editing.bold.on');       // => true
$state->matches('editing.italic.on');     // => true
$state->matches('editing.underline.off'); // => true
$state->matches('editing.list.bulleted'); // => true
```

## Validation Rules

Parallel states have specific validation rules:

1. **Must have child states**: A parallel state requires at least one region
2. **Cannot have `initial` property**: Unlike compound states, parallel states cannot specify an initial state (all regions enter simultaneously)
3. **Regions must have `initial`**: Each region (child of parallel) must be a compound state with its own `initial` property

```php ignore
// Invalid - parallel with no children
'invalid' => [
    'type' => 'parallel',
    'states' => [],  // Error: requires at least one region
],

// Invalid - parallel with initial
'invalid' => [
    'type' => 'parallel',
    'initial' => 'region1',  // Error: parallel cannot have initial
    'states' => [...],
],
```

## Best Practices

### 1. Keep Regions Independent

Design regions to be as independent as possible. If regions frequently need to know about each other's state, consider whether parallel states are the right choice.

### 2. Prefer `@done` for Synchronization

Use `@done` instead of complex guards when you need to wait for all regions to complete. For conditional routing based on region results, use [conditional `@done` with guards](./event-handling#conditional-done-with-guards):

```php ignore
'@done' => [
    ['target' => 'approved',      'guards' => IsAllSucceededGuard::class],
    ['target' => 'manual_review'],  // fallback when guard fails
],
```

### 3. Consider Parallel Dispatch for Slow Entry Actions

When region entry actions contain expensive operations (API calls, file processing), enable [Parallel Dispatch](./parallel-dispatch) to run them concurrently via Laravel queue jobs. Each region should write to its own context keys to avoid conflicts.

See the linked guide for more patterns.

::: tip Detailed Guide
For comprehensive design guidelines with Do/Don't examples, see [Parallel Patterns](/best-practices/parallel-patterns).
:::

::: tip Testing
For parallel state testing patterns, see [Parallel Testing](/testing/parallel-testing).
:::
