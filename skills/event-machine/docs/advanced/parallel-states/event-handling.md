# Event Handling in Parallel States

How events, actions, and transitions work within parallel state regions.

**Related pages:**
- [Parallel States Overview](./index) - Basic concepts and syntax
- [Persistence](./persistence) - Database storage and restoration
- [Parallel Dispatch](./parallel-dispatch) - Concurrent execution via queue jobs

## Event Handling

Events are broadcast to all active regions. Each region independently evaluates whether it can handle the event.

### Single Region Handling

When an event is only defined in one region, only that region transitions:

<!-- doctest-attr: bootstrap="laravel,editor-setup" -->
```php
$state = $definition->getInitialState();
// document: editing, format: normal

$state = $definition->transition(['type' => 'BOLD'], $state);
// document: editing (unchanged)
// format: bold (transitioned)
$state->matches('active.document.editing'); // => true // [!code hide]
$state->matches('active.format.bold');      // => true // [!code hide]
```

### Multiple Region Handling

The same event can trigger transitions in multiple regions simultaneously:

<!-- doctest-attr: bootstrap="laravel,multi-region-setup" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
MachineDefinition::define([
    'id' => 'editor',
    'initial' => 'active',
    'context' => ['value' => ''],
    'states' => [
        'active' => [
            'type' => 'parallel',
            'states' => [
                'editing' => [
                    'initial' => 'idle',
                    'states' => [
                        'idle' => [
                            'on' => [
                                'CHANGE' => [
                                    'target' => 'modified',
                                    'actions' => 'updateValueAction',
                                ],
                            ],
                        ],
                        'modified' => [],
                    ],
                ],
                'status' => [
                    'initial' => 'saved',
                    'states' => [
                        'saved' => [
                            'on' => ['CHANGE' => 'unsaved'],
                        ],
                        'unsaved' => [
                            'on' => ['SAVE' => 'saved'],
                        ],
                    ],
                ],
            ],
        ],
    ],
]);

// CHANGE event triggers transitions in BOTH regions
$state = $definition->transition(['type' => 'CHANGE'], $state);
$state->matches('active.editing.modified');  // true
$state->matches('active.status.unsaved');    // true
```

## Entry and Exit Actions

Entry and exit actions fire for each region during transitions. Understanding the execution order is important for proper state initialization and cleanup.

### Entry Action Execution Order

When entering a parallel state, entry actions fire in this specific order:

1. **Parallel state entry** - The parallel state's own entry action
2. **Region 1 initial state entry** - First region's initial state
3. **Region 2 initial state entry** - Second region's initial state
4. *(continues for all regions in definition order)*

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
MachineDefinition::define(
    config: [
        'id' => 'machine',
        'initial' => 'active',
        'states' => [
            'active' => [
                'type' => 'parallel',
                'entry' => 'logParallelEntryAction',  // 1. Fires first
                'states' => [
                    'region1' => [
                        'initial' => 'a',
                        'states' => [
                            'a' => [
                                'entry' => 'logRegion1EntryAction',  // 2. Fires second
                            ],
                        ],
                    ],
                    'region2' => [
                        'initial' => 'b',
                        'states' => [
                            'b' => [
                                'entry' => 'logRegion2EntryAction',  // 3. Fires third
                            ],
                        ],
                    ],
                    'region3' => [
                        'initial' => 'c',
                        'states' => [
                            'c' => [
                                'entry' => 'logRegion3EntryAction',  // 4. Fires fourth
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    behavior: [
        'actions' => [
            'logParallelEntryAction' => fn () => Log::info('1. Entering parallel state'),
            'logRegion1EntryAction' => fn () => Log::info('2. Entering region 1'),
            'logRegion2EntryAction' => fn () => Log::info('3. Entering region 2'),
            'logRegion3EntryAction' => fn () => Log::info('4. Entering region 3'),
        ],
    ]
);

// Log output:
// 1. Entering parallel state
// 2. Entering region 1
// 3. Entering region 2
// 4. Entering region 3
```

### Exit Action Execution Order

Exit actions fire for leaf states and the parallel state itself:

1. **Leaf state exits** - Exit actions for each active leaf state (in definition order)
2. **Parallel state exit** - The parallel state's own exit action (last)

::: warning Region Exit Actions
Region (compound state) exit actions are **not** automatically invoked when leaving a parallel state. Only leaf states and the parallel state itself run exit actions.
:::

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
MachineDefinition::define(
    config: [
        'id' => 'machine',
        'initial' => 'active',
        'states' => [
            'active' => [
                'type' => 'parallel',
                'exit' => 'logParallelExitAction',  // 3. Fires last
                'states' => [
                    'region1' => [
                        'initial' => 'a',
                        'states' => [
                            'a' => [
                                'exit' => 'logStateAExitAction',  // 1. Fires first
                            ],
                        ],
                    ],
                    'region2' => [
                        'initial' => 'b',
                        'states' => [
                            'b' => [
                                'exit' => 'logStateBExitAction',  // 2. Fires second
                            ],
                        ],
                    ],
                ],
            ],
            'inactive' => [],
        ],
    ],
    behavior: [
        'actions' => [
            'logStateAExitAction' => fn () => Log::info('1. Exiting state a'),
            'logStateBExitAction' => fn () => Log::info('2. Exiting state b'),
            'logParallelExitAction' => fn () => Log::info('3. Exiting parallel state'),
        ],
    ]
);

// When transitioning from 'active' to 'inactive', log output:
// 1. Exiting state a
// 2. Exiting state b
// 3. Exiting parallel state
```

::: tip Action Order Summary
**Entry**: Outside → Inside (parallel → leaf states in each region)
**Exit**: Leaf states first → Parallel state last
:::

## Shared Context

All regions share the same `ContextManager`. Actions in any region can read and modify the context:

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
MachineDefinition::define(
    config: [
        'id' => 'counter',
        'initial' => 'active',
        'context' => ['count' => 0],
        'states' => [
            'active' => [
                'type' => 'parallel',
                'states' => [
                    'incrementer' => [
                        'initial' => 'ready',
                        'states' => [
                            'ready' => [
                                'on' => [
                                    'INCREMENT' => [
                                        'actions' => 'incrementAction',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'decrementer' => [
                        'initial' => 'ready',
                        'states' => [
                            'ready' => [
                                'on' => [
                                    'DECREMENT' => [
                                        'actions' => 'decrementAction',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    behavior: [
        'actions' => [
            'incrementAction' => fn (ContextManager $ctx) => $ctx->set('count', $ctx->get('count') + 1),
            'decrementAction' => fn (ContextManager $ctx) => $ctx->set('count', $ctx->get('count') - 1),
        ],
    ]
);
```

::: warning Context Conflicts
When multiple regions modify the same context key in response to the same event, the last region (in definition order) wins. With [Parallel Dispatch](./parallel-dispatch) enabled, a `PARALLEL_CONTEXT_CONFLICT` internal event is recorded when this happens, making the overwrite observable in machine history. Design your context structure to use separate keys per region to avoid conflicts entirely.
:::

## Final States and @done

When all regions of a parallel state reach their final states, the parallel state is considered complete. Use `@done` to transition when this happens:

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
$definition = // [!code hide]
MachineDefinition::define([
    'id' => 'checkout',
    'initial' => 'processing',
    'states' => [
        'processing' => [
            'type' => 'parallel',
            '@done' => 'complete',  // Transition when ALL regions are final
            'states' => [
                'payment' => [
                    'initial' => 'pending',
                    'states' => [
                        'pending' => [
                            'on' => ['PAYMENT_SUCCEEDED' => 'done'],
                        ],
                        'done' => ['type' => 'final'],
                    ],
                ],
                'shipping' => [
                    'initial' => 'preparing',
                    'states' => [
                        'preparing' => [
                            'on' => ['SHIPPED' => 'done'],
                        ],
                        'done' => ['type' => 'final'],
                    ],
                ],
            ],
        ],
        'complete' => ['type' => 'final'],
    ],
]);

$state = $definition->getInitialState();
// processing.payment.pending, processing.shipping.preparing

$state = $definition->transition(['type' => 'PAYMENT_SUCCEEDED'], $state);
// processing.payment.done, processing.shipping.preparing
// Still in processing - shipping not complete

$state = $definition->transition(['type' => 'SHIPPED'], $state);
// Now both regions are final - automatically transitions to 'complete'
$state->matches('complete');  // => true
```

### @done with Actions

You can also specify actions to run when the parallel state completes:

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
$definition = // [!code hide]
MachineDefinition::define( // [!code hide]
    config: [ // [!code hide]
        'id' => 'checkout', // [!code hide]
        'initial' => 'processing', // [!code hide]
        'context' => ['confirmed' => false], // [!code hide]
        'states' => [ // [!code hide]
'processing' => [
    'type' => 'parallel',
    '@done' => [
        'target' => 'complete',
        'actions' => 'sendConfirmationAction',
    ],
    'states' => [ // [!code hide]
        'payment' => [ // [!code hide]
            'initial' => 'pending', // [!code hide]
            'states' => [ // [!code hide]
                'pending' => ['on' => ['PAY' => 'done']], // [!code hide]
                'done' => ['type' => 'final'], // [!code hide]
            ], // [!code hide]
        ], // [!code hide]
        'shipping' => [ // [!code hide]
            'initial' => 'preparing', // [!code hide]
            'states' => [ // [!code hide]
                'preparing' => ['on' => ['SHIP' => 'done']], // [!code hide]
                'done' => ['type' => 'final'], // [!code hide]
            ], // [!code hide]
        ], // [!code hide]
    ], // [!code hide]
],
'complete' => ['type' => 'final'], // [!code hide]
        ], // [!code hide]
    ], // [!code hide]
    behavior: [ // [!code hide]
        'actions' => [ // [!code hide]
            'sendConfirmationAction' => fn (ContextManager $ctx) => $ctx->set('confirmed', true), // [!code hide]
        ], // [!code hide]
    ] // [!code hide]
); // [!code hide]
$state = $definition->getInitialState(); // [!code hide]
$state = $definition->transition(['type' => 'PAY'], $state); // [!code hide]
$state = $definition->transition(['type' => 'SHIP'], $state); // [!code hide]
$state->matches('complete'); // => true // [!code hide]
$state->context->get('confirmed'); // => true // [!code hide]
```

### Conditional @done with Guards

> **Backward compatible:** Existing simple string `@done` (e.g., `'@done' => 'completed'`) and single-object `@done` (e.g., `'@done' => ['target' => 'completed', 'actions' => ...]`) continue to work unchanged. The conditional array format is additive.

Instead of a single target, `@done` can be an array of branches — each with a `target`, optional `guards`, and optional `actions`. The first branch whose guard passes wins. A branch without a guard acts as the default fallback:

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
$definition = MachineDefinition::define( // [!code hide]
    config: [ // [!code hide]
        'id' => 'order', // [!code hide]
        'initial' => 'processing', // [!code hide]
        'context' => ['inventory_result' => 'success', 'payment_result' => 'success'], // [!code hide]
        'states' => [ // [!code hide]
'processing' => [
    'type'   => 'parallel',
    '@done'  => [
        ['target' => 'approved',      'guards' => 'isAllSucceededGuard', 'actions' => 'logApprovalAction'],
        ['target' => 'manual_review', 'actions' => 'notifyReviewerAction'],  // fallback (no guard)
    ],
    'states' => [
        'inventory' => [ // [!code hide]
            'initial' => 'checking', // [!code hide]
            'states' => [ // [!code hide]
                'checking' => ['on' => ['INV_DONE' => 'done']], // [!code hide]
                'done' => ['type' => 'final'], // [!code hide]
            ], // [!code hide]
        ], // [!code hide]
        'payment'   => [ // [!code hide]
            'initial' => 'validating', // [!code hide]
            'states' => [ // [!code hide]
                'validating' => ['on' => ['PAY_DONE' => 'done']], // [!code hide]
                'done' => ['type' => 'final'], // [!code hide]
            ], // [!code hide]
        ], // [!code hide]
    ],
],
'approved'      => ['type' => 'final'],
'manual_review' => ['type' => 'final'],
        ], // [!code hide]
    ], // [!code hide]
    behavior: [ // [!code hide]
        'guards' => [ // [!code hide]
            'isAllSucceededGuard' => fn (ContextManager $ctx) // [!code hide]
                => $ctx->get('inventory_result') === 'success' && $ctx->get('payment_result') === 'success', // [!code hide]
        ], // [!code hide]
        'actions' => [ // [!code hide]
            'logApprovalAction' => fn () => null, // [!code hide]
            'notifyReviewerAction' => fn () => null, // [!code hide]
        ], // [!code hide]
    ] // [!code hide]
); // [!code hide]
$state = $definition->getInitialState(); // [!code hide]
$state = $definition->transition(['type' => 'INV_DONE'], $state); // [!code hide]
$state = $definition->transition(['type' => 'PAY_DONE'], $state); // [!code hide]
$state->matches('approved'); // => true // [!code hide]
```

**Evaluation rules:**
- Branches are evaluated top-to-bottom — the first passing guard wins
- Only the winning branch's actions run; losing branch actions are skipped
- If all guards fail and no guardless fallback exists, the machine stays in the parallel state
- Guards receive the current `State` and `ContextManager`, so they can inspect region results
- Each branch also supports `calculators` and `description` keys, same as regular transitions

::: tip Compound States Too
Conditional `@done` also works on compound (non-parallel) states. When a compound state's child reaches a `final` state, the same guard evaluation applies.
:::

### @fail — Error Handling

When using [Parallel Dispatch](/advanced/parallel-states/parallel-dispatch), region entry actions run as queue jobs. If a job exhausts all retries, you can handle the failure with `@fail`:

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
$definition = MachineDefinition::define([ // [!code hide]
    'id' => 'order', // [!code hide]
    'initial' => 'processing', // [!code hide]
    'states' => [ // [!code hide]
'processing' => [
    'type'   => 'parallel',
    '@done' => 'completed',
    '@fail' => 'failed',       // Transition here when a region job fails
    'states' => [
        'inventory' => [ // [!code hide]
            'initial' => 'checking', // [!code hide]
            'states' => [ // [!code hide]
                'checking' => ['on' => ['INV_DONE' => 'done']], // [!code hide]
                'done' => ['type' => 'final'], // [!code hide]
            ], // [!code hide]
        ], // [!code hide]
        'payment'   => [ // [!code hide]
            'initial' => 'validating', // [!code hide]
            'states' => [ // [!code hide]
                'validating' => ['on' => ['PAY_DONE' => 'done']], // [!code hide]
                'done' => ['type' => 'final'], // [!code hide]
            ], // [!code hide]
        ], // [!code hide]
    ],
],
'completed' => ['type' => 'final'], // [!code hide]
'failed' => ['type' => 'final'],
    ], // [!code hide]
]); // [!code hide]
$state = $definition->getInitialState(); // [!code hide]
$state = $definition->transition(['type' => 'INV_DONE'], $state); // [!code hide]
$state = $definition->transition(['type' => 'PAY_DONE'], $state); // [!code hide]
$state->matches('completed'); // => true // [!code hide]
```

When `@fail` is triggered:
- The machine exits the parallel state
- Sibling jobs that haven't started will no-op
- Context from completed siblings is preserved
- A `PARALLEL_FAIL` internal event is recorded in history

Without `@fail`, the machine stays in the parallel state and records the failure event for debugging.

### Conditional @fail with Guards

Like `@done`, `@fail` supports conditional branches with guards. This enables retry-or-escalate patterns:

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
$definition = MachineDefinition::define([ // [!code hide]
    'id' => 'order', // [!code hide]
    'initial' => 'processing', // [!code hide]
    'context' => ['retryCount' => 0], // [!code hide]
    'states' => [ // [!code hide]
'processing' => [
    'type'   => 'parallel',
    '@done'  => 'completed',
    '@fail'  => [
        ['target' => 'retrying', 'guards' => 'canRetryGuard', 'actions' => 'incrementRetryAction'],
        ['target' => 'failed',   'actions' => 'sendAlertAction'],  // fallback
    ],
    'states' => [ // [!code hide]
        'region_a' => [ // [!code hide]
            'initial' => 'working', // [!code hide]
            'states' => [ // [!code hide]
                'working' => ['on' => ['DONE_A' => 'done']], // [!code hide]
                'done' => ['type' => 'final'], // [!code hide]
            ], // [!code hide]
        ], // [!code hide]
    ], // [!code hide]
],
'completed' => ['type' => 'final'], // [!code hide]
'retrying'  => ['type' => 'final'],
'failed'    => ['type' => 'final'],
    ], // [!code hide]
]); // [!code hide]
```

::: warning Action Timing Asymmetry: @done vs @fail
**`@done` actions** run **after** exit — the parallel state's child states have already exited when actions execute. This matches XState semantics where completion actions run as part of entering the target state.

**`@fail` actions** run **before** exit — the parallel state is still active when actions execute. This allows error-handling actions to inspect region state values and context (e.g., which region failed, error details) before the machine transitions out.

This asymmetry is intentional. If you need to read parallel state context during `@done`, use a guard (guards always run before exit) or capture the values in context before the transition.
:::

## Escape Transitions

Root-level or parallel-state-level `on` events can exit the entire parallel state — regardless of which regions are active. This is useful for timeouts, cancellations, or any event that should abort all parallel work.

### Root-Level Escape

A root-level `on` event fires from any state in the machine, including parallel:

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
$definition = MachineDefinition::define([ // [!code hide]
'id' => 'order',
'initial' => 'processing',
'on' => [
    'EXPIRED' => 'expired',  // Exits parallel from any state
],
'states' => [
    'processing' => [
        'type' => 'parallel',
        '@done' => 'done',
        'states' => [
            'payment'  => [ // [!code hide]
                'initial' => 'pending', // [!code hide]
                'states' => [ // [!code hide]
                    'pending' => ['on' => ['PAY' => 'done']], // [!code hide]
                    'done' => ['type' => 'final'], // [!code hide]
                ], // [!code hide]
            ], // [!code hide]
            'shipping' => [ // [!code hide]
                'initial' => 'preparing', // [!code hide]
                'states' => [ // [!code hide]
                    'preparing' => ['on' => ['SHIP' => 'done']], // [!code hide]
                    'done' => ['type' => 'final'], // [!code hide]
                ], // [!code hide]
            ], // [!code hide]
        ],
    ],
    'done'    => ['type' => 'final'],
    'expired' => ['type' => 'final'],
],
]); // [!code hide]
$state = $definition->getInitialState(); // [!code hide]
$state = $definition->transition(['type' => 'PAY'], $state); // [!code hide]
$state = $definition->transition(['type' => 'EXPIRED'], $state); // [!code hide]
$state->matches('expired'); // => true // [!code hide]
```

### Parallel-Level Escape

An `on` event on the parallel state itself exits all regions:

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
$definition = MachineDefinition::define([ // [!code hide]
    'id' => 'order', // [!code hide]
    'initial' => 'processing', // [!code hide]
    'states' => [ // [!code hide]
'processing' => [
    'type' => 'parallel',
    '@done' => 'done',
    'on' => [
        'CANCEL' => 'cancelled',  // Exits parallel state
    ],
    'states' => [
        'payment'  => [ // [!code hide]
            'initial' => 'pending', // [!code hide]
            'states' => [ // [!code hide]
                'pending' => ['on' => ['PAY' => 'done']], // [!code hide]
                'done' => ['type' => 'final'], // [!code hide]
            ], // [!code hide]
        ], // [!code hide]
        'shipping' => [ // [!code hide]
            'initial' => 'preparing', // [!code hide]
            'states' => [ // [!code hide]
                'preparing' => ['on' => ['SHIP' => 'done']], // [!code hide]
                'done' => ['type' => 'final'], // [!code hide]
            ], // [!code hide]
        ], // [!code hide]
    ],
],
'done' => ['type' => 'final'], // [!code hide]
'cancelled' => ['type' => 'final'],
    ], // [!code hide]
]); // [!code hide]
$state = $definition->getInitialState(); // [!code hide]
$state = $definition->transition(['type' => 'CANCEL'], $state); // [!code hide]
$state->matches('cancelled'); // => true // [!code hide]
```

### Escape Behavior

When an escape transition fires:

1. **Exit actions** fire on all active leaf states and the parallel state itself
2. **Transition actions** fire exactly once (not per region)
3. **Guards** are evaluated once — if the guard fails, the machine stays in the parallel state
4. The machine transitions to the target state

::: tip Deduplication
Escape transitions are automatically deduplicated. Even though multiple regions could each find the ancestor-level transition, the action runs only once.
:::

### Escape to Compound Target

Escape transitions can target compound states — the machine resolves to the target's initial child:

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
$definition = MachineDefinition::define([ // [!code hide]
    'id' => 'order', // [!code hide]
    'initial' => 'processing', // [!code hide]
'on' => [
    'CANCEL' => 'review',  // 'review' is a compound state
],
    'states' => [ // [!code hide]
        'processing' => [ // [!code hide]
            'type' => 'parallel', // [!code hide]
            'states' => [ // [!code hide]
                'region_a' => [ // [!code hide]
                    'initial' => 'working', // [!code hide]
                    'states' => ['working' => []], // [!code hide]
                ], // [!code hide]
            ], // [!code hide]
        ], // [!code hide]
'review' => [
    'initial' => 'pending',
    'states' => [
        'pending'  => ['on' => ['APPROVE' => 'approved']],
        'approved' => ['type' => 'final'],
    ],
],
    ], // [!code hide]
]); // [!code hide]
$state = $definition->getInitialState(); // [!code hide]
$state = $definition->transition(['type' => 'CANCEL'], $state); // [!code hide]
$state->matches('review.pending'); // => true // [!code hide]
```

After `CANCEL`, the machine is at `review.pending`.

## Nested Parallel States

Parallel states can be nested within compound states, and compound states can be nested within parallel regions. You can even nest parallel states within parallel states.

### Parallel Inside Compound

<!-- doctest-attr: ignore -->
```php
'active' => [
    'initial' => 'loading',
    'states' => [
        'loading' => [
            'on' => ['LOADED' => 'ready'],
        ],
        'ready' => [
            'type' => 'parallel',  // Parallel state inside compound
            'states' => [
                'audio' => [...],
                'video' => [...],
            ],
        ],
    ],
],
```

### Compound Inside Parallel Region

<!-- doctest-attr: ignore -->
```php
'player' => [
    'type' => 'parallel',
    'states' => [
        'track' => [
            'initial' => 'stopped',  // Compound inside parallel region
            'states' => [
                'stopped' => [...],
                'playing' => [...],
                'paused' => [...],
            ],
        ],
        'volume' => [
            'initial' => 'unmuted',
            'states' => [
                'unmuted' => [...],
                'muted' => [...],
            ],
        ],
    ],
],
```

### Deep Nesting (3+ Levels)

You can create complex hierarchies with multiple levels of nesting. EventMachine recursively resolves all leaf states regardless of nesting depth.

**Structure: Parallel → Compound → Parallel → Leaf**

```
deep (machine)
└── root (PARALLEL)
    ├── branch1 (compound)
    │   └── leaf (PARALLEL)
    │       ├── subleaf1 (compound)
    │       │   ├── a ← active leaf
    │       │   └── b
    │       └── subleaf2 (compound)
    │           ├── x ← active leaf
    │           └── y
    └── branch2 (compound)
        ├── waiting ← active leaf
        └── finished
```

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
MachineDefinition::define([
    'id' => 'deep',
    'initial' => 'root',
    'states' => [
        'root' => [
            'type' => 'parallel',  // Level 1: Outer parallel
            'states' => [
                'branch1' => [
                    'initial' => 'leaf',  // Level 2: Compound region
                    'states' => [
                        'leaf' => [
                            'type' => 'parallel',  // Level 3: Nested parallel
                            'states' => [
                                'subleaf1' => [
                                    'initial' => 'a',  // Level 4: Inner compound
                                    'states' => [
                                        'a' => ['on' => ['GO1' => 'b']],  // Level 5: Leaf
                                        'b' => [],
                                    ],
                                ],
                                'subleaf2' => [
                                    'initial' => 'x',
                                    'states' => [
                                        'x' => ['on' => ['GO2' => 'y']],
                                        'y' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'branch2' => [
                    'initial' => 'waiting',
                    'states' => [
                        'waiting' => ['on' => ['DONE' => 'finished']],
                        'finished' => [],
                    ],
                ],
            ],
        ],
    ],
]);
```

### State Value in Deep Nesting

The `$state->value` array always contains the fully-qualified IDs of all active **leaf** states:

<!-- doctest-attr: bootstrap="laravel,deep-nesting-setup" -->
```php
$state = $definition->getInitialState();

// State value includes ALL leaf states from ALL nesting levels:
echo json_encode($state->value, JSON_PRETTY_PRINT); // [!code hide]
$state->value;
// [
//     'deep.root.branch1.leaf.subleaf1.a',  // From nested parallel, region 1
//     'deep.root.branch1.leaf.subleaf2.x',  // From nested parallel, region 2
//     'deep.root.branch2.waiting',          // From outer parallel, region 2
// ]

// Note: 3 active states because:
// - Outer parallel (root) has 2 regions: branch1, branch2
// - branch1's initial (leaf) is itself parallel with 2 regions: subleaf1, subleaf2
// - Total: 2 (from nested) + 1 (from outer) = 3 leaf states
```
<!-- doctest-json: ["deep.root.branch1.leaf.subleaf1.a","deep.root.branch1.leaf.subleaf2.x","deep.root.branch2.waiting"] -->

### Transitions in Deep Nesting

Each region independently handles events at its own level:

<!-- doctest-attr: bootstrap="laravel,deep-nesting-setup" -->
```php
$state = $definition->getInitialState();
// branch1.leaf.subleaf1.a, branch1.leaf.subleaf2.x, branch2.waiting

// Event handled by nested parallel region subleaf1
$state = $definition->transition(['type' => 'GO1'], $state);
$state->matches('root.branch1.leaf.subleaf1.b'); // true
$state->matches('root.branch1.leaf.subleaf2.x'); // true
$state->matches('root.branch2.waiting');          // true

// Event handled by nested parallel region subleaf2
$state = $definition->transition(['type' => 'GO2'], $state);
$state->matches('root.branch1.leaf.subleaf1.b'); // true
$state->matches('root.branch1.leaf.subleaf2.y'); // true
$state->matches('root.branch2.waiting');          // true

// Event handled by outer parallel region branch2
$state = $definition->transition(['type' => 'DONE'], $state);
$state->matches('root.branch1.leaf.subleaf1.b'); // => true
$state->matches('root.branch1.leaf.subleaf2.y'); // => true
$state->matches('root.branch2.finished');         // => true
```

### Using `matches()` with Deep Nesting

The `matches()` method checks for exact matches against active leaf states. You must provide the full path from the machine's initial state:

<!-- doctest-attr: bootstrap="laravel,deep-nesting-setup" -->
```php
$state = $definition->getInitialState(); // [!code hide]
// Check specific leaf states with matches() - must be full path
$state->matches('root.branch1.leaf.subleaf1.a');  // => true
$state->matches('root.branch1.leaf.subleaf2.x');  // => true
$state->matches('root.branch2.waiting');          // => true

// Intermediate paths do NOT match
$state->matches('root.branch1.leaf');  // => false
$state->matches('root.branch1');       // => false
$state->matches('root');               // => false

// Partial paths (without machine id prefix) also don't work
$state->matches('branch2.waiting');    // => false
$state->matches('subleaf1.a');         // => false
```

::: warning Full Paths Required
Always use the complete path from the initial state to the leaf when calling `matches()`. For example, use `root.branch1.leaf.subleaf1.a` instead of just `subleaf1.a`.
:::

::: tip Deep Nesting Best Practices
- Keep nesting to 3 levels or fewer when possible for maintainability
- Use meaningful names that indicate the hierarchy level (e.g., `region`, `subregion`)
- Consider breaking very deep structures into separate machines that communicate via events
:::

## Transitioning Into Parallel States

When a transition targets a parallel state, all of its regions are automatically entered.

### From Non-Parallel to Parallel

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
$definition = // [!code hide]
MachineDefinition::define([
    'id' => 'app',
    'initial' => 'idle',
    'states' => [
        'idle' => [
            'on' => ['START' => 'processing'],
        ],
        'processing' => [
            'type' => 'parallel',
            'states' => [
                'task1' => [
                    'initial' => 'pending',
                    'states' => [
                        'pending' => [],
                        'complete' => [],
                    ],
                ],
                'task2' => [
                    'initial' => 'pending',
                    'states' => [
                        'pending' => [],
                        'complete' => [],
                    ],
                ],
            ],
        ],
    ],
]);

$state = $definition->getInitialState();
$state->matches('idle');  // true

$state = $definition->transition(['type' => 'START'], $state);
// Both regions are automatically entered
$state->matches('processing.task1.pending');  // => true
$state->matches('processing.task2.pending');  // => true
```

### Transitioning Into Nested Parallel (Within Parallel Region)

When you're already in a parallel state and a region transitions to a state that is itself parallel, all nested regions are properly initialized:

<!-- doctest-attr: bootstrap="laravel" -->
```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
$definition = // [!code hide]
MachineDefinition::define([
    'id' => 'nested',
    'initial' => 'active',
    'states' => [
        'active' => [
            'type' => 'parallel',
            'states' => [
                'outer1' => [
                    'initial' => 'off',
                    'states' => [
                        'off' => [
                            'on' => ['ACTIVATE' => 'on'],
                        ],
                        'on' => [
                            'type' => 'parallel',  // Target is parallel!
                            'states' => [
                                'inner1' => [
                                    'initial' => 'idle',
                                    'states' => [
                                        'idle' => ['on' => ['WORK1' => 'working']],
                                        'working' => [],
                                    ],
                                ],
                                'inner2' => [
                                    'initial' => 'idle',
                                    'states' => [
                                        'idle' => ['on' => ['WORK2' => 'working']],
                                        'working' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'outer2' => [
                    'initial' => 'waiting',
                    'states' => [
                        'waiting' => ['on' => ['PROCEED' => 'done']],
                        'done' => [],
                    ],
                ],
            ],
        ],
    ],
]);

$state = $definition->getInitialState();
// Initial: outer1.off, outer2.waiting
$state->value;
// ['nested.active.outer1.off', 'nested.active.outer2.waiting']

// Transition to 'on' which is a parallel state
$state = $definition->transition(['type' => 'ACTIVATE'], $state);

// The nested parallel is fully expanded - both inner regions entered!
$state->value;
// [
//     'nested.active.outer1.on.inner1.idle',  // Nested region 1
//     'nested.active.outer1.on.inner2.idle',  // Nested region 2
//     'nested.active.outer2.waiting',         // Outer region unchanged
// ]

$state->matches('active.outer1.on.inner1.idle');  // => true
$state->matches('active.outer1.on.inner2.idle');  // => true
$state->matches('active.outer2.waiting');         // => true
```

::: info Entry Actions When Entering Nested Parallel
When transitioning into a nested parallel state, entry actions fire in order:
1. The parallel state's entry action (`on`)
2. Each nested region's initial state entry action (`inner1.idle`, `inner2.idle`)
:::

::: tip Parallel Dispatch Timing
With [Parallel Dispatch](./parallel-dispatch) enabled, entry actions for each region run as concurrent queue jobs instead of sequentially. This changes the execution model: entry actions no longer share an in-memory context during execution. Each job snapshots context before running, computes a diff after, and merges under a database lock. Regions should write to **separate context keys** — if two regions write to the same key, a `PARALLEL_CONTEXT_CONFLICT` event is recorded and the last writer wins. If a region's entry action does not call `$this->raise()`, a `PARALLEL_REGION_STALLED` event is recorded as an audit trail.
:::

## Guards in Parallel States

### Regular Guards

When a regular `GuardBehavior` fails in a parallel state, the machine stays in its current state — identical to non-parallel behavior. A `GUARD_FAIL` and `TRANSITION_FAIL` event are recorded in history. This applies to both region-level transitions and escape transitions (parallel-level or root-level `on:` handlers reached via bubbling).

### Validation Guards

When a `ValidationGuardBehavior` fails in any region, the entire parallel transition is blocked — no region transitions. A `MachineValidationException` is thrown (422 via endpoints). This atomic rejection prevents partial state updates across regions.

See [Validation Guards → Parallel States](/behaviors/validation-guards#parallel-states) for details and examples.

::: tip Testing
For testing event handling in parallel states, see [Parallel Testing](/testing/parallel-testing).
:::
