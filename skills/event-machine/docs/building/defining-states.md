# Defining States

This guide shows you how to define states in your machine configuration.

## Basic State Definition

Every state is defined as a key in the `states` array:

```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]

MachineDefinition::define(
    config: [
        'initial' => 'pending',
        'states' => [
            'pending' => [],
            'processing' => [],
            'completed' => [],
        ],
    ],
);
```

## State Properties

Each state supports these properties:

| Property | Type | Description |
|----------|------|-------------|
| `on` | array | Event-to-transition mappings |
| `entry` | string\|array | Actions to run when entering |
| `exit` | string\|array | Actions to run when leaving |
| `type` | string | State type (`'final'` for terminal states) |
| `output` | string|array | Output behavior or key filter |
| `initial` | string | Initial child state (for compound states) |
| `states` | array | Child state definitions (for compound states) |
| `meta` | array | Custom metadata |
| `description` | string | Human-readable description |

## State Types

EventMachine supports three state types:

### Atomic States

Simple states with no children. Most states are atomic:

```php ignore
'pending' => [
    'on' => [
        'SUBMIT' => 'processing',
    ],
],
```

### Compound States

States containing nested child states:

```php ignore
'active' => [
    'initial' => 'idle',
    'states' => [
        'idle' => [
            'on' => ['START' => 'running'],
        ],
        'running' => [
            'on' => ['PAUSE' => 'paused'],
        ],
        'paused' => [
            'on' => ['RESUME' => 'running'],
        ],
    ],
],
```

When entering a compound state, the machine automatically enters its initial child state.

### Final States

Terminal states that end the machine's execution:

```php ignore
'completed' => [
    'type' => 'final',
    'output' => 'calculateOutput',  // Optional output behavior
],
```

Final states cannot have outgoing transitions:

```php ignore
// This will throw InvalidStateConfigException
'done' => [
    'type' => 'final',
    'on' => [
        'RESTART' => 'initial',  // Not allowed!
    ],
],
```

## Entry and Exit Actions

Execute code when entering or leaving a state:

```php ignore
'processing' => [
    'entry' => 'startProcessingAction',           // Single action
    'exit' => ['cleanupAction', 'logCompletionAction'], // Multiple actions
    'on' => [
        'COMPLETE' => 'done',
    ],
],
```

Actions execute in this order during transitions:

```
Source State Exit Actions
    ↓
Transition Actions
    ↓
Target State Entry Actions
```

## Machine-Level Entry and Exit

Define `entry`/`exit` at the root config level for actions that run once during the machine lifecycle:

```php ignore
MachineDefinition::define(
    config: [
        'id'      => 'order',
        'initial' => 'pending',
        'entry'   => 'initializeTrackingAction',  // Runs once — when machine starts
        'exit'    => 'finalCleanupAction',         // Runs once — when machine reaches a final state
        'states'  => [
            'pending' => [
                'entry' => 'sendNotificationAction',  // Runs each time 'pending' is entered
                'on'    => ['SUBMIT' => 'completed'],
            ],
            'completed' => ['type' => 'final'],
        ],
    ],
);
```

Execution order on initialization:

```
MACHINE_START
  → Root entry actions (once)
    → Initial state entry actions
```

Execution order when reaching a final state:

```
Current state exit actions
  → Root exit actions (once)
    → MACHINE_FINISH
```

::: info Root vs State entry/exit
- **Root `entry`**: Runs **once** when the machine starts — before any state entry
- **Root `exit`**: Runs **once** when the machine reaches a final state — after the last state's exit
- **State `entry`/`exit`**: Runs **every time** that specific state is entered or left

Root entry does NOT run on every state change. For that, use [Listeners](#listeners).
:::

## Listeners

Listeners are **observers**, not behaviors. Actions describe *what the machine does* on entry/exit/transition — they mutate context, raise events, and run as part of the transition itself. Listeners describe *what watches the machine* after the transition is recorded — broadcasts, audit logs, analytics, dashboards. Both hook into the same lifecycle, but they answer different questions, and that difference is what makes one async-safe and the other not.

| | Action | Listener |
|---|---|---|
| Role | **Behavior** — part of the transition | **Observer** — runs after the transition is committed |
| Mutates context | Yes, that's the point | Allowed, but lossy when queued (last-writer-wins) |
| Sequence-sensitive | Yes — later actions read earlier actions' writes | No — each listener is independent |
| Failure | Throws can abort the transition | Throws don't undo the transition (failed jobs land in `failed_jobs`) |
| Async-safe | **No** — context, ordering, and rollback all break | **Yes** — transition is already committed |
| Typical use | "the user balance must reflect this transition" | "fire a webhook when the order ships" |

::: info Why this distinction matters for `@queue`
`@queue` exists only on listeners. It is **not** missing on actions by oversight — it is structurally impossible to make safe there. If `entry` action #2 in a list of three were dispatched async, action #3 would race with action #2's context writes, the worker would restore the *current* persisted state (not the dispatch-time state — possibly several transitions later), and any throw on the worker could not abort a transition that has already been recorded. Listeners avoid all three problems because they observe a *committed* transition, so they don't need rollback, don't need ordering with respect to peers, and see the same state on the worker that the dispatcher saw.

For fire-and-forget Job dispatch from inside an entry action, the idiomatic pattern is a thin wrapper Action that calls `dispatch()` — see [Async Work in Entry Actions](#async-work-in-entry-actions).
:::

Listener actions use the same resolution mechanism as all other behaviors — both inline keys and FQCN references work interchangeably (see [Behavior Resolution](/behaviors/introduction#behavior-resolution)). Instead of adding `broadcastAction` to 13 states individually, define it once:

```php ignore
'listen' => [
    'entry' => BroadcastStateAction::class,  // every non-transient state entry
],
```

| Feature | `entry` / `exit` (state) | `entry` / `exit` (root) | `listen` |
|---------|--------------------------|-------------------------|----------|
| Scope | One state | Machine lifecycle | Every state |
| Runs | Each time state is entered/left | Once on start/completion | Each non-transient entry/exit/transition |
| Purpose | State-specific behavior | Machine init/cleanup | Cross-cutting **observers** |
### Listener Types

Three listener keys are available:

| Key | Fires When | Use Cases |
|-----|-----------|-----------|
| `entry` | After entering a non-transient state (after state entry actions) | Broadcasting, dashboard, metrics |
| `exit` | Before leaving a non-transient state (before state exit actions) | Audit logging, time tracking |
| `transition` | After every successful transition (including targetless) | Full audit trail, analytics |

`entry` answers: "which state did the machine enter?"
`transition` answers: "which event was processed?"

| Scenario | `entry` | `exit` | `transition` |
|----------|:---:|:---:|:---:|
| Normal (A→B) | Target B | Source A | Yes |
| Targetless (context update) | No | No | **Yes** |
| Self-transition (A→A) | A (re-enter) | A (leave) | Yes |
| Guard-blocked | No | No | No |
| Transient (@always) | No | No | No |
| Init (no @always) | Initial state | No | No |

### Sync and Queued Actions

Use the `@queue` key in a tuple to dispatch listener actions to the queue. The `@` prefix marks it as framework metadata — it never reaches `__invoke`:

```php ignore
'listen' => [
    'entry' => [
        BroadcastAction::class,                                // sync (default)
        [HeavyAuditAction::class, '@queue' => true],          // queued (default queue)
        [AnalyticsAction::class, '@queue' => 'analytics'],    // queued (specific queue)
    ],
],
```

**`@queue` type:** `bool|string` — `true` = default queue, `'name'` = specific queue, `false`/omitted = sync.

Listeners also support named parameters alongside `@queue`:

```php ignore
'listen' => [
    'entry' => [
        [AuditAction::class, 'verbose' => true, '@queue' => true],
    ],
],
```

| Use Case | Sync or Queued | Why |
|----------|---------------|-----|
| `broadcast()` | Sync | Laravel already queues broadcast internally |
| Quick context log | Sync | Fast, no external I/O |
| External audit API | Queued | Slow, shouldn't block transition |
| Heavy analytics | Queued | CPU-intensive, offload to worker |

::: info How Queued Listeners Work
Queued listeners restore the machine from `rootEventId` on the worker — same as child delegation and sendTo. The worker sees the machine's latest persisted state. This is EventMachine's standard behavior for all queued operations.
:::

::: tip Point-in-Time Data
If you need data from the exact moment of the transition, use a sync listener that dispatches its own job with the captured data.
:::

::: warning `@queue` only works in `listen` — not in state actions
`@queue` is a framework-reserved key that is **only honored inside `listen.entry`, `listen.exit`, and `listen.transition`**. Putting it in a state's `entry`/`exit` action list, in transition `actions`, in `guards`, or in `calculators` is a misuse — and since 9.11.0 EventMachine throws `InvalidBehaviorDefinitionException` at definition time so it cannot fail silently:

```php ignore
// ❌ Rejected — @queue is silently dropped here, so it is now an error
'states' => [
    'approved' => [
        'entry' => [
            ApproveAction::class,
            [CreateInstallmentPromissoryNoteAction::class, '@queue' => true], // throws
            SendApprovalNotificationAction::class,
        ],
    ],
],
```

If you need an entry action to run async, choose one of three options below.
:::

### Async Work in Entry Actions

State `entry` actions always run **synchronously, in array order**, before the machine is persisted. To do work off the request thread you have three options — pick by what your machine has to *do* with the result:

| Option | When to use | Cost |
|--------|-------------|------|
| **Job actor** (state's `job` key) | The state should transition based on the outcome — you need `@done` / `@fail` routing. | Requires its own state. The framework has to wait somewhere to observe the job's result. |
| **Queued listener** (`listen.entry` + `@queue`) | Work runs after entry but doesn't drive a transition. The worker may write back to context (the listener restores the machine from `rootEventId`). | One layer of indirection (ListenerJob → restore → run). Last-writer-wins on context if multiple listeners queue concurrently. |
| **Wrapper Action** (regular action that calls `dispatch()`) | True fire-and-forget — no machine state depends on the result, you don't need machine context on the worker. | One thin class. The cheapest and most common option. |

::: tip Wrapper Action — when you don't want a separate state
You don't need to add a state just to fire-and-forget a job. Write a one-line action that dispatches the job and place it in `entry` like any other action:

```php ignore
final class DispatchPromissoryNoteAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        dispatch(new CreateInstallmentPromissoryNoteJob(
            applicationId: $context->applicationId,
        ));
    }
}

'approved' => [
    'entry' => [
        TerminateOtherApplicationsAction::class,
        ApproveAction::class,
        CompleteApplicationAction::class,
        DispatchPromissoryNoteAction::class,  // ← fire-and-forget Job
        SendApprovalNotificationAction::class,
    ],
],
```

This is also why EventMachine doesn't expose a `'jobs'` slot alongside `'actions'`: a job actor needs its own state because the framework has to wait for `@done` / `@fail`; a fire-and-forget job needs nothing — a wrapper Action expresses it more clearly than a new config key would, and stays inside the existing test/fake infrastructure (`Machine::fakingAllActions(except: [...])`, `Bus::fake()`).
:::

The same scenario, written as the other two options:

```php ignore
// Job actor — promissory note success/failure decides the next state
'approved' => [
    'entry' => [
        TerminateOtherApplicationsAction::class,
        ApproveAction::class,
        CompleteApplicationAction::class,
        SendApprovalNotificationAction::class,
    ],
    'job' => CreateInstallmentPromissoryNoteJob::class,
    'on'  => [
        '@done' => 'note_created',
        '@fail' => 'note_failed',
    ],
],

// Queued listener — note creation runs async on a worker that restores the machine
'approved' => [
    'entry'  => [
        TerminateOtherApplicationsAction::class,
        ApproveAction::class,
        CompleteApplicationAction::class,
        SendApprovalNotificationAction::class,
    ],
    'listen' => [
        'entry' => [[CreateInstallmentPromissoryNoteAction::class, '@queue' => true]],
    ],
],
```

### Execution Order

```
1. Calculators
2. Guards (if fail → no transition, no listeners)
3. listen.exit (source, if non-transient)
4. Source state exit actions
5. Transition actions
6. Target state entry actions
7. listen.entry (target, if non-transient)
8. listen.transition (always — targetless included)
9. @always check → repeat from 1 (listeners skip transient states)
```

### Transient State Skipping

States with `@always` transitions are transient — all listeners skip them automatically:

```
START → @always routing → @always eligibility → awaiting_consent
                                                  ↑
                                      Listeners fire here (once)
```

::: tip Event Preservation (v8+)
Although listeners skip transient states, entry actions and `@always` behaviors in those states still receive the original triggering event. See [@always Transitions — Event Preservation](/advanced/always-transitions#event-preservation).
:::

For business-level filtering, add conditions in the action itself.

### Listeners and Child Machines

Each machine has its own `listen` config — listeners are **not inherited** by child machines and **do not fire** on child machine state changes.

```
Parent: idle → delegating → completed
                    │
                    ├── Child: step_1 → step_2 → done
                    │   (parent listen does NOT fire here)
                    │
                    └── @done → completed
                        (parent listen.entry fires here)
```

- **Parent `listen.entry`** fires when the parent enters `delegating` and when it enters `completed` (after `@done` routing) — but NOT when the child transitions between `step_1`, `step_2`, and `done`.
- **Child machines** can define their own `listen` config independently. Child listeners fire during child execution and do not affect parent context.
- This applies to both **sync** and **async** (queued) child delegation.

### Internal Events

| Event | When |
|-------|------|
| `{machine}.listen.entry.start/finish` | Sync entry listeners |
| `{machine}.listen.exit.start/finish` | Sync exit listeners |
| `{machine}.listen.transition.start/finish` | Sync transition listeners |
| `{machine}.listen.queue.{action}.dispatched` | Queued listener dispatched |
| `{machine}.listen.queue.{action}.started` | Worker picked up job |
| `{machine}.listen.queue.{action}.completed` | Worker finished |

## State Metadata

Attach custom data to states:

```php ignore
'pending_approval' => [
    'meta' => [
        'description' => 'Waiting for manager approval',
        'timeout' => 86400,  // 24 hours
        'notify' => ['manager@example.com'],
    ],
    'on' => [
        'APPROVE' => 'approved',
        'REJECT' => 'rejected',
    ],
],
```

Access metadata from state:

```php no_run
$state->currentStateDefinition->meta['timeout']; // 86400
```

## State Descriptions

Add human-readable descriptions:

```php ignore
'awaiting_payment' => [
    'description' => 'Order is waiting for customer payment',
    'on' => [
        'PAY' => 'paid',
        'CANCEL' => 'cancelled',
    ],
],
```

Access via:

```php no_run
$state->currentStateDefinition->description;
```

## State Hierarchy and IDs

States are identified by their path from the root:

```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]

MachineDefinition::define(
    config: [
        'id' => 'order',
        'initial' => 'checkout',
        'states' => [
            'checkout' => [
                'initial' => 'cart',
                'states' => [
                    'cart' => [],
                    'shipping' => [],
                    'payment' => [],
                ],
            ],
        ],
    ],
);
```

State IDs follow the pattern `{machine_id}.{path}`:

| State | Full ID |
|-------|---------|
| checkout | `order.checkout` |
| cart | `order.checkout.cart` |
| shipping | `order.checkout.shipping` |

You can customize the delimiter:

```php ignore
'delimiter' => '/',  // Results in: order/checkout/cart
```

## Complete Example

```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

MachineDefinition::define(
    config: [
        'id' => 'document',
        'initial' => 'draft',
        'states' => [
            'draft' => [
                'description' => 'Document is being edited',
                'entry' => 'initializeDraftAction',
                'on' => [
                    'SUBMIT' => 'review',
                    'DELETE' => 'deleted',
                ],
            ],
            'review' => [
                'description' => 'Document under review',
                'initial' => 'pending',
                'states' => [
                    'pending' => [
                        'entry' => 'notifyReviewersAction',
                        'on' => [
                            'APPROVE' => 'approved',
                            'REJECT' => 'rejected',
                        ],
                    ],
                    'approved' => [
                        'exit' => 'logApprovalAction',
                    ],
                    'rejected' => [
                        'exit' => 'logRejectionAction',
                    ],
                ],
                'on' => [
                    'PUBLISH' => [
                        'target' => 'published',
                        'guards' => 'isApprovedGuard',
                    ],
                    'REVISE' => 'draft',
                ],
            ],
            'published' => [
                'type' => 'final',
                'entry' => 'notifyPublishedAction',
                'output' => 'getPublishedDocumentOutput',
                'meta' => [
                    'public' => true,
                ],
            ],
            'deleted' => [
                'type' => 'final',
            ],
        ],
    ],
    behavior: [
        'actions' => [
            'initializeDraftAction' => InitializeDraftAction::class,
            'notifyReviewersAction' => NotifyReviewersAction::class,
            'notifyPublishedAction' => NotifyPublishedAction::class,
            'logApprovalAction' => LogApprovalAction::class,
            'logRejectionAction' => LogRejectionAction::class,
        ],
        'guards' => [
            'isApprovedGuard' => IsApprovedGuard::class,
        ],
        'outputs' => [
            'getPublishedDocumentOutput' => GetPublishedDocumentOutput::class,
        ],
    ],
);
```

## State Definition Reference

```php ignore
'stateName' => [
    // Transitions (see Writing Transitions)
    'on' => [
        'EVENT' => 'targetState',
    ],

    // Lifecycle actions
    'entry' => 'actionNameAction',              // or ['action1Action', 'action2Action']
    'exit' => 'actionNameAction',               // or ['action1Action', 'action2Action']

    // State type
    'type' => 'final',                    // Only for terminal states

    // Final state output
    'output' => 'outputBehaviorNameOutput',

    // Hierarchy
    'initial' => 'childStateName',        // Initial child state
    'states' => [                         // Child states
        'childState' => [...],
    ],

    // Metadata
    'meta' => [
        'key' => 'value',
    ],
    'description' => 'Human readable text',
],
```

## Configuration Validation

EventMachine validates your machine configuration at definition time via `StateConfigValidator`. Any structural errors — invalid keys, wrong state types, conflicting options — throw `InvalidStateConfigException` with a descriptive message pointing to the exact problem.

Common validation errors include:

| Error | Cause |
|-------|-------|
| Invalid root-level keys | Typo in a top-level config key |
| Invalid state keys | Unknown key inside a state definition |
| Invalid state type | `type` is not `'final'` or `'parallel'` |
| Final state with transitions | Final state has `on` key |
| Final state with children | Final state has `states` key |
| Parallel state without regions | `type: 'parallel'` but `states` is empty |

You can also run validation via artisan:

```bash
php artisan machine:validate
```

This scans all Machine classes and reports config errors without running the application.

::: tip MachineDefinitionNotFoundException
If a Machine subclass does not implement the `definition()` method, `MachineDefinitionNotFoundException` is thrown when the machine is instantiated or discovered by artisan commands.
:::

### Listener Validation

Listener config must use the current array format. The removed class-as-key format (e.g., `[MyAction::class => ['queue' => true]]`) throws `InvalidListenerDefinitionException`. Use the tuple format instead:

```php ignore
// Correct
'listen' => [
    'entry' => [[MyAction::class, '@queue' => true]],
],

// Rejected — throws InvalidListenerDefinitionException
'listen' => [
    'entry' => [MyAction::class => ['queue' => true]],
],
```

## Testing State Definitions

<!-- doctest-attr: ignore -->
```php
OrderMachine::test()
    ->assertState('idle')
    ->send('SUBMIT')
    ->assertState('submitted')
    ->send('PAY')
    ->assertState('paid')
    ->assertFinished();  // verify final state
```

::: tip Full Testing Guide
See [Transitions and Paths](/testing/transitions-and-paths) for more examples.
:::
