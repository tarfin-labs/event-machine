# State Change Listeners

## Problem

Running an action on every state entry (broadcasting, logging, metrics) requires adding it to each state individually. In a 13-state machine, that's 13 copies of `broadcastStateAction` — boilerplate, error-prone, DRY violation.

## Solution: `listen` Config Key

```php
MachineDefinition::define(
    config: [
        'id'      => 'car_sales',
        'initial' => 'idle',
        'entry'   => 'initTrackingAction',       // once on start (lifecycle)
        'exit'    => 'finalCleanupAction',        // once on completion (lifecycle)
        'listen'  => [
            'entry'      => BroadcastStateAction::class,
            'exit'       => AuditLogAction::class,
            'transition' => FullAuditTrailAction::class,
        ],
        'states' => [...],
    ],
);
```

`listen` uses existing action primitives — no new concepts. Values are action class names or arrays, same format as state-level `entry`/`exit`.

**Why `listen`:**
- Fiil — EventMachine config'de fiiller kullanır (`send`, `raise`, `dispatch`)
- Kısa — `listeners`, `onEntry`, `afterStateChange`'den daha kısa
- Prefix gerektirmiyor — blok ismi zaten context veriyor: `listen.entry` = "entry'yi dinle"
- Doğal İngilizce — "listen to entry" / "listen to exit"
- `entry`/`exit` ile karışmıyor — biri root/state seviyesinde lifecycle action, diğeri `listen` bloğu içinde cross-cutting concern

## Listener Types

### `entry` — State Entry

Fires **after** entering a non-transient state (after state-level entry actions have run).

| Use Cases |
|-----------|
| Broadcasting state to frontend |
| Dashboard/metrics update |
| Notification dispatch |
| Context snapshot logging |

Does NOT fire on: transient states (`@always`), targetless transitions (state didn't change), guard-blocked transitions.

### `exit` — State Exit

Fires **before** leaving a non-transient state (before state-level exit actions run).

| Use Cases |
|-----------|
| Audit logging ("was in state X for Y seconds") |
| Time-in-state tracking |
| Resource cleanup signaling |

Does NOT fire on: transient states, guard-blocked transitions. Does NOT fire when the machine is already in a final state (there's no "leaving" a final state — root `exit` handles machine completion). Does fire on the **source** state when transitioning **to** a final state.

### `transition` — Transition Complete

Fires **after** every successful transition completes — including targetless transitions. This is the most general listener: if an event caused a transition (even without state change), `transition` fires.

| Use Cases |
|-----------|
| Full audit trail (captures event type + source → target) |
| Analytics (every event processed) |
| External system sync |
| Targetless transition tracking (context updates without state change) |

`entry` answers: "which state did the machine enter?"
`transition` answers: "which event was processed?"

### Master Comparison Table

| Scenario | `listen.entry` | `listen.exit` | `listen.transition` |
|----------|:-:|:-:|:-:|
| Normal transition (A→B) | Target B | Source A | Yes |
| Targetless (context update) | No | No | **Yes** |
| Self-transition (A→A) | A (re-enter) | A (leave) | Yes |
| Guard-blocked | No | No | No |
| Transient (@always) | No | No | No |
| Init (no @always) | Initial state | No | No |
| Init (with @always chain) | Resting state | No | Yes (via transition()) |
| Final state entry | Final state | Source state | Yes |
| Final state (already in) | N/A | No (can't leave) | N/A |
| Parallel state entry | Parallel + each region | N/A | Yes |

### Out of Scope: `guardFail` / `onError`

These are NOT included because they require a **different action interface**:

- **`guardFail`**: The action needs to know WHICH guard failed, which event was rejected, and for `ValidationGuardBehavior` the error message. This changes the `__invoke` signature beyond standard `ActionBehavior`.
- **`onError`**: The action needs the `\Throwable` exception, the failing action name, and the error context. This is a fundamentally different callback contract.

Both `entry`/`exit`/`transition` work with the standard `ActionBehavior` interface — same `__invoke(ContextManager, State, EventBehavior)`. Adding `guardFail`/`onError` would either break this uniformity or require a new base class. If there's demand, they should be designed as a separate feature with their own interface.

## Listener Value Formats

Same as `entry`/`exit` — string, array, FQCN, with optional `['queue' => true]` modifier:

```php
'listen' => [
    // Single sync action
    'entry' => BroadcastStateAction::class,

    // Multiple actions — sync and queued mixed
    'entry' => [
        BroadcastAction::class,                            // sync (default)
        MetricsAction::class,                              // sync
        HeavyAuditAction::class => ['queue' => true],     // queued
    ],

    // Exit with queue modifier
    'exit' => [
        LogExitAction::class,                              // sync
        CleanupAction::class => ['queue' => true],         // queued
    ],

    // Transition — full audit trail
    'transition' => FullAuditTrailAction::class,
],
```

**The `queue` modifier lives on the action, not on the key** — consistent with how EventMachine handles `queue` on states in machine delegation:

```php
// Machine delegation: queue is on the entity
'processing' => [
    'machine' => PaymentMachine::class,
    'queue'   => true,
],

// Listener: queue is on the action
'listen' => [
    'entry' => [
        HeavyAuditAction::class => ['queue' => true],
    ],
],
```

## Execution Order

### Full transition flow:

```
1. Calculators
2. Guards (if fail → no transition, no listeners)
3. listen.exit (source state, if non-transient)
4. Source state exit actions
5. Transition actions
6. Target state entry actions
7. listen.entry (target state, if non-transient)
8. listen.transition (always — targetless included)
9. @always check → if triggered, repeat from 1 (listeners skip transient states)
```

**Why this order:**
- `listen.exit` runs BEFORE state exit actions — the listener sees the state as it was during its lifetime, before cleanup/teardown
- `listen.entry` runs AFTER state entry actions — the listener (e.g., broadcast) sees the fully initialized state, including context changes from entry actions
- `listen.transition` runs LAST (before @always) — the entire transition is complete, state is settled
- Within each listener group: sync actions run first, then queued actions are dispatched

### Initialization:

```
{machine}.start
  → Root entry actions (once)
  → Initial state entry actions
  → listen.entry (initial state, if non-transient)
```

Note: `listen.transition` does NOT fire on direct initialization (no `@always`). If the initial state triggers an `@always` chain, those transitions go through `transition()` where `listen.transition` IS wired — so it fires on the final resting state.

### Final state:

```
listen.exit (source state)
  → Source state exit actions
  → Target (final) state entry actions
  → listen.entry (final state)
  → listen.transition
  → Root exit actions (once)
  → {machine}.finish
```

Note: `listen.exit` does NOT fire on final states — there's no "leaving" a final state.

### Targetless transition:

```
1. Calculators
2. Guards
3. (no exit — state doesn't change)
4. Transition actions
5. (no entry — state doesn't change)
6. listen.transition ← fires here even though state didn't change
```

`entry` and `exit` listeners do NOT fire on targetless transitions — state didn't change.

### Self-transition (A→A):

```
1. Calculators
2. Guards
3. listen.exit (A — non-transient)
4. A exit actions
5. Transition actions
6. A entry actions
7. listen.entry (A — non-transient)
8. listen.transition
```

All three listener types fire — the state exits and re-enters.

## Transient State Handling

**Automatic, framework-level.** States with `@always` transitions are transient — all listeners (entry, exit, transition) are skipped:

```
START → @always routing → @always eligibility → awaiting_consent
                                                  ↑
                                      listen.entry + listen.transition fire here (once)
```

3 transient states + 1 resting state = 1 set of listener calls.

**Business-level filtering** (e.g., "only broadcast for customer-facing states") stays in the action:

```php
class BroadcastStateAction extends ActionBehavior
{
    private const INTERNAL_STATES = ['routing', 'eligibility_check'];

    public function __invoke(ContextManager $context, State $state): void
    {
        if (in_array($state->currentStateDefinition->key, self::INTERNAL_STATES, true)) {
            return;
        }

        broadcast(new StateChanged($context->machineId(), $state->value));
    }
}
```

Two layers: framework handles structural filtering (transient), action handles business filtering.

## Sync vs Queued Listeners

### When to Use Which

| Use Case | Sync or Queued | Why |
|----------|---------------|-----|
| `broadcast()` | Sync | Laravel already queues broadcast internally |
| Quick context log | Sync | Fast, no external I/O |
| Counter increment | Sync | In-memory, instant |
| External audit API | Queued | Slow, shouldn't block transition |
| Heavy analytics | Queued | CPU-intensive, offload to worker |
| Email assembly | Queued | External service call |

### Sync Behavior

Sync listeners run inline during the transition. Action receives full in-memory `ContextManager` and `State`.

### Queued Behavior

Framework dispatches a `ListenerJob` to the queue:

```
Transition happens
  → Sync listener actions run inline
  → For each queued action: dispatch ListenerJob
  → Transition continues (no blocking)

Worker picks up ListenerJob
  → Restores machine from rootEventId
  → Runs the action with full ContextManager + State
  → Records started/completed internal events
  → Persists
```

The action class is identical for sync and queued — same `ActionBehavior`, same `__invoke` signature. The only difference is WHEN and WHERE it runs.

### How Queued Listeners Restore State

Queued listeners restore the machine from `rootEventId` when the worker picks up the job — same pattern as `ChildMachineCompletionJob`, `SendToMachineJob`, and all other queued EventMachine operations. The machine is always restored to its **latest persisted state**, not the state at the moment of dispatch.

This is EventMachine's fundamental design: the machine is an event-sourced actor — it rebuilds its state from the full event history. There is no point-in-time snapshot mechanism. All queued operations (child delegation, sendTo, timers) work the same way.

For listener use cases this is correct behavior:
- **Broadcasting:** You want the frontend to see the current state — latest is correct
- **Audit logging:** The machine has progressed — the latest state is the most complete record
- **Analytics:** Aggregate metrics from the event history, not from snapshots

If an action needs data from the exact moment of the transition (e.g., "which event caused this entry?"), capture it in a sync listener and pass it to a dispatched job:

```php
class TransitionAuditAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, State $state, EventBehavior $event): void
    {
        dispatch(new AuditJob(
            machineId: $context->machineId(),
            state: $state->value,
            eventType: $event->type,
            timestamp: now(),
        ));
    }
}
```

This is not a workaround — it's the correct pattern when you need point-in-time data combined with async processing.

## Design Decisions

### Queued Listeners Require Persistence

`ListenerJob` restores the machine via `create(state: $rootEventId)`. Without persistence (`shouldPersist = false`), there's no DB record to restore from. Queued listeners are silently skipped when persistence is off — `dispatchListenerJob()` returns early if `rootEventId` is null. The validator should warn if `listen` config contains queued actions on a machine with `shouldPersist = false`.

### Listener Exception Handling

Listener actions follow standard `ActionBehavior` exception behavior: if a sync listener throws, the exception propagates and the transition fails — same as any other action. Listeners are NOT wrapped in try-catch.

This is intentional: listeners use the same primitive as all other actions, and special-casing them would add hidden behavior. If a listener needs to be fault-tolerant, it should handle exceptions internally:

```php
class SafeBroadcastAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, State $state): void
    {
        try {
            broadcast(new StateChanged($context->machineId(), $state->value));
        } catch (\Throwable $e) {
            logger()->warning('Broadcast failed', ['error' => $e->getMessage()]);
        }
    }
}
```

### Interaction with Async & Parallel Features

Listeners must work correctly with all EventMachine async features. The principle: **listeners fire wherever entry/exit actions fire** — same call sites, same conditions.

#### Parallel States (Sequential Mode)

When `shouldDispatchParallel()` is false, regions run inline. `enterParallelState()` calls `runEntryActions()` on the parallel state AND each region's initial state sequentially.

Listener behavior: `runEntryListeners()` is called at the same points — after parallel state entry actions, and after each region's initial state entry actions.

For 3 regions: `listen.entry` fires 4 times (1 parallel state + 3 regions). This matches entry action behavior.

If too many broadcasts, filter in the action:

```php
if ($state->currentStateDefinition->type !== StateDefinitionType::PARALLEL) {
    broadcast(new StateChanged(...));
}
```

#### Parallel Dispatch (Async Mode)

When `shouldDispatchParallel()` is true, regions with entry actions are dispatched as `ParallelRegionJob`. Entry actions run on workers — NOT inline.

**Critical implementation detail:** `ParallelRegionJob::handle()` (line 79) calls `$regionInitial->runEntryActions($machine->state)` on the worker. Listener calls must ALSO be added to `ParallelRegionJob` — otherwise listeners don't fire for dispatched regions.

Implementation: Add `$machine->definition->runEntryListeners($machine->state)` call in `ParallelRegionJob::handle()` after `$regionInitial->runEntryActions($machine->state)` (line 79). The listener runs on the worker, BEFORE the lock is acquired (same as entry actions). Any context changes from sync listeners are captured by `computeContextDiff()` and applied under lock — same as entry action context changes. If the listener is queued (`['queue' => true]`), a `ListenerJob` is dispatched from within the `ParallelRegionJob` — nested queue dispatch, which Laravel supports.

Inline regions (those without entry actions) are processed in `enterParallelState()` — listeners fire inline for these.

#### Machine Delegation (Sync)

Parent enters delegating state → child runs inline → child reaches final → `routeChildDoneEvent()` routes parent to new state.

Listener behavior: fires naturally — `routeChildDoneEvent()` is already in the call sites table. `listen.entry` fires on the delegating state (when parent enters it) and on the target state after @done routing. Child machine has its own separate `listen` config.

No special handling needed.

#### Machine Delegation (Async)

Parent enters delegating state → `ChildMachineJob` dispatched → parent stays in delegating state. Later: child completes → `ChildMachineCompletionJob` restores parent → `routeChildDoneEvent()` → parent transitions.

Listener behavior: `listen.entry` fires on the delegating state (inline, during original transition). After child completes, `listen.entry` fires on the @done target state — this happens inside `ChildMachineCompletionJob` on the worker, because `routeChildDoneEvent()` is called there. Listener calls in `routeChildDoneEvent()` run on the worker — correct behavior.

If a listener is queued, a `ListenerJob` is dispatched from inside `ChildMachineCompletionJob` — nested queue dispatch, which Laravel supports.

No special handling needed — call sites in `routeChildDoneEvent()`/`routeChildFailEvent()` cover this.

#### Job Actors

Same flow as async machine delegation — `ChildJobJob` dispatched, `ChildMachineCompletionJob` routes @done/@fail. Listener calls in `routeChildDoneEvent()`/`routeChildFailEvent()` fire on the worker.

No special handling needed.

#### Fire-and-Forget Delegation

Parent enters state with `machine` + `target` (no @done). Child dispatched, parent immediately transitions to target state.

Listener behavior: `listen.entry` fires on the delegating state, then `listen.exit` fires when immediately leaving it, then `listen.entry` fires on the target. The delegating state is fleeting (entered and left in the same operation) but NOT transient (no @always) — so listeners DO fire.

This means a broadcast listener would briefly broadcast the delegating state, then immediately broadcast the target state. This is technically correct but potentially noisy. If undesirable, filter fire-and-forget states in the action using `hasMachineInvoke()`:

```php
// Skip broadcasting for delegation states
$sd = $state->currentStateDefinition;
if ($sd->hasMachineInvoke() && $sd->getMachineInvokeDefinition()->fireAndForget) {
    return;
}
```

#### Timers (after/every)

Timer sweep sends events via `$machine->send()` → goes through `transition()` → listeners fire at the standard call sites. Both DB-based (`ProcessTimersCommand`) and in-memory (`advanceTimers()`) paths go through `transition()`.

No special handling needed.

#### Scheduled Events

`ProcessScheduledCommand` sends events to matching machines via `send()` → `transition()` → listeners fire.

No special handling needed.

#### sendTo / dispatchTo (Cross-Machine Communication)

`sendTo()`: synchronous send to another machine. That machine processes via `transition()` → listeners on the TARGET machine fire (if configured).

`dispatchTo()`: `SendToMachineJob` dispatched → worker restores target machine → `send()` → `transition()` → listeners on the target machine fire on the worker.

Each machine has its own `listen` config. Cross-machine communication doesn't require special listener handling.

#### raise()

`raise()` queues an internal event processed in the same transition cycle via `transition()`. Listeners fire as normal for the resulting state change.

No special handling needed.

### Summary: Where Listener Wiring Is Needed

| Component | Listener Fires | Wiring Location |
|-----------|---------------|-----------------|
| `MachineDefinition::transition()` | All transitions | Already in call sites table |
| `MachineDefinition::getInitialState()` | Machine init | Already in call sites table |
| `MachineDefinition::enterParallelState()` (sequential) | Parallel regions inline | Already in call sites table |
| `MachineDefinition::routeChildDoneEvent()` | @done routing | Already in call sites table |
| `MachineDefinition::routeChildFailEvent()` | @fail routing | Already in call sites table |
| `MachineDefinition::routeChildTimeoutEvent()` | @timeout routing | Already in call sites table |
| **`ParallelRegionJob::handle()`** | **Dispatched regions** | **NEW — must add** |

The only NEW wiring location is `ParallelRegionJob::handle()` — all other async features route through methods already in the call sites table.

## Internal Events

### Sync Listener Events

Recorded during the transition, inline:

```php
case LISTEN_ENTRY_START      = '{machine}.listen.entry.start';
case LISTEN_ENTRY_FINISH     = '{machine}.listen.entry.finish';
case LISTEN_EXIT_START       = '{machine}.listen.exit.start';
case LISTEN_EXIT_FINISH      = '{machine}.listen.exit.finish';
case LISTEN_TRANSITION_START  = '{machine}.listen.transition.start';
case LISTEN_TRANSITION_FINISH = '{machine}.listen.transition.finish';
```

Individual sync actions record standard `ACTION_START`/`ACTION_FINISH` events between the start/finish markers.

### Queued Listener Events

**Dispatch side** (recorded during transition):

```php
case LISTEN_QUEUE_DISPATCHED = '{machine}.listen.queue.{placeholder}.dispatched';
```

**Worker side** (recorded when ListenerJob runs):

```php
case LISTEN_QUEUE_STARTED   = '{machine}.listen.queue.{placeholder}.started';
case LISTEN_QUEUE_COMPLETED = '{machine}.listen.queue.{placeholder}.completed';
```

### Full Event History Example

```
// During transition (inline):
{machine}.listen.entry.start
  {machine}.action.broadcastAction.start         ← sync runs
  {machine}.action.broadcastAction.finish
  {machine}.listen.queue.heavyAuditAction.dispatched  ← queued dispatched
{machine}.listen.entry.finish
{machine}.listen.transition.start
  {machine}.action.fullAuditAction.start         ← sync runs
  {machine}.action.fullAuditAction.finish
{machine}.listen.transition.finish

// Later, on worker:
{machine}.listen.queue.heavyAuditAction.started   ← worker picked up
  {machine}.action.heavyAuditAction.start
  {machine}.action.heavyAuditAction.finish
{machine}.listen.queue.heavyAuditAction.completed ← worker done
```

### Observability from Event History

| See in history | Meaning |
|----------------|---------|
| `dispatched` only | Queue problem — job never picked up |
| `dispatched` + `started` only | Action failed on worker |
| `dispatched` + `started` + `completed` | Success |
| Time between `dispatched` and `started` | Queue latency |
| Time between `started` and `completed` | Action execution time |

## Before / After Example

### Before (13 states × broadcastStateAction)

```php
'states' => [
    'idle'              => ['entry' => 'broadcastAction', ...],
    'awaiting_consent'  => ['entry' => ['broadcastAction', 'sendConsentAction'], ...],
    'verification'      => ['entry' => 'broadcastAction', ...],
    'documents'         => ['entry' => 'broadcastAction', ...],
    'approved'          => ['entry' => ['broadcastAction', 'notifyAction'], ...],
    'rejected'          => ['entry' => ['broadcastAction', 'notifyAction'], 'type' => 'final'],
    // ... 7 more, each with broadcastAction
],
```

### After (1 listen declaration)

```php
'listen' => [
    'entry' => [
        BroadcastStateAction::class,                           // sync
        HeavyAuditAction::class => ['queue' => true],          // queued
    ],
    'transition' => [
        FullAuditTrailAction::class,                               // sync — every transition
        ExternalAnalyticsAction::class => ['queue' => true],       // queued — heavy analytics
    ],
],
'states' => [
    'idle'              => [...],
    'awaiting_consent'  => ['entry' => 'sendConsentAction', ...],
    'verification'      => [...],
    'documents'         => [...],
    'approved'          => ['entry' => 'notifyAction', ...],
    'rejected'          => ['entry' => 'notifyAction', 'type' => 'final'],
],
```

## Implementation

### Config Parsing

Parse `listen` from config. Each key's value is normalized to an array of listener entries. Each entry is either a plain string (sync) or `ClassName => ['queue' => true]` (queued).

```php
/** Parsed listener definitions */
public array $listen = [
    'entry'      => [],  // [{action: string, queue: bool}, ...]
    'exit'       => [],
    'transition' => [],
];
```

Parsing logic:

```php
if (isset($config['listen'])) {
    foreach (['entry', 'exit', 'transition'] as $key) {
        if (!isset($config['listen'][$key])) {
            continue;
        }

        $raw = is_array($config['listen'][$key])
            ? $config['listen'][$key]
            : [$config['listen'][$key]];

        foreach ($raw as $k => $v) {
            if (is_int($k)) {
                // 'BroadcastAction::class' or 'broadcastAction'
                $this->listen[$key][] = ['action' => $v, 'queue' => false];
            } else {
                // 'HeavyAuditAction::class' => ['queue' => true]
                $this->listen[$key][] = ['action' => $k, 'queue' => $v['queue'] ?? false];
            }
        }
    }
}
```

### Transient Detection

```php
private function isTransientState(StateDefinition $state): bool
{
    if ($state->transitionDefinitions === null) {
        return false;
    }

    return isset($state->transitionDefinitions[TransitionProperty::Always->value]);
}
```

### Runner Methods

Three dedicated methods — one per listener type. Each follows the same pattern:

```php
protected function runEntryListeners(State $state, ?EventBehavior $eventBehavior = null): void
{
    if ($this->listen['entry'] === []) {
        return;
    }

    if ($this->isTransientState($state->currentStateDefinition)) {
        return;
    }

    $state->setInternalEventBehavior(type: InternalEvent::LISTEN_ENTRY_START);

    foreach ($this->listen['entry'] as $listener) {
        if ($listener['queue']) {
            $this->dispatchListenerJob($listener['action'], $state);
        } else {
            $this->runAction(
                actionDefinition: $listener['action'],
                state: $state,
                eventBehavior: $eventBehavior,
            );
        }
    }

    $state->setInternalEventBehavior(type: InternalEvent::LISTEN_ENTRY_FINISH);
}
```

`runExitListeners()` and `runTransitionListeners()` follow the same pattern with their respective enum cases (`LISTEN_EXIT_START/FINISH`, `LISTEN_TRANSITION_START/FINISH`).

```php
protected function dispatchListenerJob(string $action, State $state): void
{
    $rootEventId = $state->history->first()?->root_event_id;

    if ($rootEventId === null) {
        return;
    }

    $state->setInternalEventBehavior(
        type: InternalEvent::LISTEN_QUEUE_DISPATCHED,
        placeholder: $action,
    );

    dispatch(new ListenerJob(
        machineClass: $this->machineClass,
        rootEventId: $rootEventId,
        actionClass: $action,
    ));
}
```

### ListenerJob (New)

```php
class ListenerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $machineClass,
        public readonly string $rootEventId,
        public readonly string $actionClass,
    ) {}

    public function handle(): void
    {
        // Restore machine from DB
        $machine = $this->machineClass::create(state: $this->rootEventId);

        // Record started event
        $machine->state->setInternalEventBehavior(
            type: InternalEvent::LISTEN_QUEUE_STARTED,
            placeholder: $this->actionClass,
        );

        // Run the listener action with full state
        $machine->definition->runAction(
            actionDefinition: $this->actionClass,
            state: $machine->state,
        );

        // Record completed event
        $machine->state->setInternalEventBehavior(
            type: InternalEvent::LISTEN_QUEUE_COMPLETED,
            placeholder: $this->actionClass,
        );

        // Persist the new internal events
        $machine->persist();
    }
}
```

### Call Sites

**Entry listeners** — after every `runEntryActions()`:

| Location | After | Add |
|----------|-------|-----|
| `getInitialState()` | Initial state `runEntryActions()` | `runEntryListeners()` |
| `routeChildDoneEvent()` | Target `runEntryActions()` | `runEntryListeners()` |
| `routeChildFailEvent()` | Target `runEntryActions()` | `runEntryListeners()` |
| `routeChildTimeoutEvent()` | Target `runEntryActions()` | `runEntryListeners()` |
| `transition()` (multiple) | Target `runEntryActions()` | `runEntryListeners()` |
| `enterParallelState()` (sequential) | Region `runEntryActions()` | `runEntryListeners()` |
| `ParallelRegionJob::handle()` (dispatch) | Region `runEntryActions()` (line 79) | `runEntryListeners()` — runs before lock, context effects included in diff |

**Exit listeners** — before every `runExitActions()`:

| Location | Before | Add |
|----------|--------|-----|
| `transition()` (multiple) | Source `runExitActions()` | `runExitListeners()` |
| `exitParallelState*()` | State `runExitActions()` | `runExitListeners()` |

**Transition listeners** — after entry listeners (or after transition actions for targetless):

| Location | After | Add |
|----------|-------|-----|
| All entry listener call sites | `runEntryListeners()` | `runTransitionListeners()` |
| Targetless transition path | Transition actions | `runTransitionListeners()` |

### Validator Updates

Add `'listen'` to `ALLOWED_ROOT_KEYS`.

Validate:
- Must be array if present
- Only `'entry'`, `'exit'`, and `'transition'` keys allowed
- Values must be string, FQCN, or array of strings/FQCNs with optional `['queue' => true]` modifier

### Inline Behavior Registration

Listener action FQCNs need registration in `machine->behavior` — same as transition/entry/exit actions. Parse step should call `initializeInlineBehaviors()` equivalent for listener action classes.

## Test Scenarios

### Sync Listeners — Entry/Exit

1. **Basic entry listener**: fires on state entry, modifies context
2. **Basic exit listener**: fires before state exit
3. **Multiple listeners**: `['action1', 'action2']`, both run in order
4. **Transient skip**: state with `@always`, listener NOT fired
5. **Listener sees post-entry context**: entry action sets value, listener reads updated value
6. **Execution order**: state entry actions → entry listener (verified via context log)
7. **Initial state**: listener fires on machine start
8. **Final state entry**: entry listener fires on final state entry
9. **Final state exit**: exit listener does NOT fire on final states
10. **Guard-blocked transition**: no listener fires
11. **No listeners defined**: no overhead, no errors
12. **entry + exit together**: both fire during transition, correct order
13. **Multiple transitions**: listener fires on each non-transient state
14. **TestMachine::define()**: works without persistence
15. **FQCN action class**: class-based behavior resolved correctly

### Sync Listeners — Transition

16. **Basic transition listener**: fires after successful transition
17. **Targetless transition**: transition listener fires, entry/exit do NOT
18. **Self-transition**: all three (exit, entry, transition) fire
19. **Transient skip**: transition listener does NOT fire on @always states
20. **Transition + entry together**: entry fires first, then transition
21. **Transition sees event type**: action can read `$event->type`
22. **Transition does NOT fire on init**: only entry listener fires on machine start

### Edge Cases — Parallel States & Child Delegation

23. **Parallel state entry**: listeners fire on the parallel state entry, and on each region's initial state entry
24. **Parallel state exit**: listeners fire when leaving parallel state (exit listeners on each active region state, then on parallel state itself)
25. **Child delegation entry**: listener fires when entering a state with `machine` key (delegation state)
26. **Child @done routing**: after child completes, parent routes to new state — entry listener fires on the new target state
27. **Parallel dispatch (Queue::fake)**: with `shouldDispatchParallel`, verify ParallelRegionJob dispatched AND that it would call `runEntryListeners()`
28. **Fire-and-forget**: listener fires on both the fleeting delegation state and the target state

### Queued Listeners

29. **Queued entry dispatches ListenerJob**: Queue::fake(), verify dispatched with correct params
30. **Queued exit dispatches ListenerJob**: same pattern
31. **Queued transition dispatches ListenerJob**: same pattern
32. **Mixed sync + queued in same key**: sync runs inline (context modified), queued dispatched
33. **Queued transient skip**: queued listener NOT dispatched for transient states
34. **ListenerJob restores and runs**: integration test — job runs action with restored state
35. **Dispatched internal event recorded**: `listen.queue.{action}.dispatched` in event history
36. **Worker internal events recorded**: `started` + `completed` in event history after ListenerJob runs

### LocalQA Tests (Real Infrastructure)

Queued listener tests with `Queue::fake()` verify dispatch but not execution. LocalQA tests verify the full pipeline: dispatch → Horizon picks up → worker runs action → internal events persisted.

**Requires:** MySQL + Redis + Horizon running (see `tests/LocalQA/README.md` for setup).

**Test stub needed:** A machine with `listen` config including queued actions, and a simple action that writes a marker to context or a separate tracking table.

37. **Queued entry listener runs via Horizon**: Machine transitions, `ListenerJob` dispatched, Horizon processes it, `listen.queue.{action}.started` + `listen.queue.{action}.completed` events appear in `machine_events` table
38. **Queued exit listener runs via Horizon**: Same pattern for exit listener
39. **Queued transition listener runs via Horizon**: Same pattern for transition listener
40. **Full lifecycle observability**: Verify `dispatched` → `started` → `completed` chain exists in `machine_events` with correct timestamps (started > dispatched)
41. **Mixed sync + queued end-to-end**: Sync listener modifies context immediately, queued listener's effects appear after Horizon processes the job
42. **Multiple queued listeners for same event**: Two queued actions dispatched, both processed by Horizon, both record their internal events
43. **Queued listener after machine progresses**: Machine transitions A→B, queued entry listener dispatched for B. Then machine transitions B→C before worker runs. Worker restores machine at C (latest state), action runs with C's context — verify this is the expected behavior
44. **Parallel dispatch listeners via Horizon**: Machine enters parallel state with dispatch enabled, ParallelRegionJob runs on Horizon, listener fires on worker — verify listener internal events appear in machine_events

**Test file:** `tests/LocalQA/ListenerQueuedTest.php`

**Stub machine:** `tests/Stubs/Machines/ListenerMachines/ListenerQueuedMachine.php` — machine with sync + queued listeners, multiple states, at least one targetless transition.

**Stub action for queued testing:** `tests/Stubs/Machines/ListenerMachines/Actions/QueuedMarkerAction.php` — writes `listener_ran: true` or similar marker into context that can be verified after Horizon processes the job. Since the action runs on the worker (machine restored from DB), the marker is persisted when `ListenerJob` calls `$machine->persist()`.

## Files to Modify / Create

| File | Change |
|------|--------|
| `src/Definition/MachineDefinition.php` | `$listen` property, config parsing, `runEntryListeners()`/`runExitListeners()`/`runTransitionListeners()`, `dispatchListenerJob()`, `isTransientState()`, call at every entry/exit/transition site |
| `src/Enums/InternalEvent.php` | 9 new cases: `LISTEN_ENTRY_START/FINISH`, `LISTEN_EXIT_START/FINISH`, `LISTEN_TRANSITION_START/FINISH`, `LISTEN_QUEUE_DISPATCHED/STARTED/COMPLETED` |
| `src/StateConfigValidator.php` | `'listen'` in `ALLOWED_ROOT_KEYS`, validate structure |
| `src/Jobs/ListenerJob.php` (new) | Queue job — restore machine, run action, record events, persist |
| `src/Jobs/ParallelRegionJob.php` | Add `runEntryListeners()` call after `runEntryActions()` on line 79 |
| `tests/Features/ListenTest.php` (new) | Scenarios 1-28 (sync entry/exit/transition + edge cases) |
| `tests/Features/ListenQueuedTest.php` (new) | Scenarios 29-36 (queued with Queue::fake) |
| `tests/LocalQA/ListenerQueuedTest.php` (new) | Scenarios 37-44 (queued with real Horizon) |
| `tests/Stubs/Machines/ListenerMachines/` (new) | Stub machine + actions for listener testing |
| `docs/building/defining-states.md` | Add "Listeners" section |
| `docs/understanding/machine-lifecycle.md` | Update execution order + internal events table |
| `docs/testing/test-machine.md` | Listener testing subsection |
| `docs/testing/recipes.md` | Broadcasting recipe + queued listener observability recipe |

## Documentation Plan

### 1. `docs/building/defining-states.md` — New "Listeners" Section

Place after "Machine-Level Entry and Exit", before "State Metadata".

**Structure:**

#### Concept Introduction
- One paragraph: "Cross-cutting actions that run on every state change without per-state boilerplate."
- Comparison table:

| Feature | `entry` / `exit` (state) | `entry` / `exit` (root) | `listen` |
|---------|--------------------------|-------------------------|----------|
| Scope | One state | Machine lifecycle | Every state |
| Runs | Each time state is entered/left | Once on start/completion | Each non-transient entry/exit/transition |
| Purpose | State-specific logic | Machine init/cleanup | Cross-cutting concerns |

#### Before/After Example
- Show the 13-state boilerplate problem
- Show the 1-line `listen` solution

#### Listener Types
- `entry`: when, use cases, what it skips
- `exit`: when, use cases, what it skips
- `transition`: when, use cases, difference from `entry`, targetless coverage
- Table comparing all three across scenarios (normal, targetless, self, guard-blocked, transient)

#### Sync and Queued Actions
- Explain the `['queue' => true]` modifier on individual actions
- When to use sync vs queued (table with use cases)
- Code examples with mixed sync/queued in the same key
- Info box: "How Queued Listeners Work" — machine is restored from `rootEventId` on the worker, same as child delegation and sendTo. The worker sees the machine's latest persisted state. This is EventMachine's standard behavior for all queued operations.
- Tip box: "If you need data from the exact moment of the transition, use a sync listener that dispatches its own job with the captured data."

#### Execution Order
- ASCII diagram showing full transition flow with listener positions
- Note: sync actions first, then queued dispatches within each listener group
- Initialization flow, final state flow, targetless flow

#### Transient State Skipping
- Explain: states with `@always` are transient — all listeners skip them
- Diagram: 3 transient → 1 resting = 1 set of listener calls
- Tip: "For business-level filtering, add conditions in the action itself"

#### Interaction with Async Features
- Table: how listeners behave with each async feature (parallel dispatch, machine delegation, job actors, fire-and-forget, timers, sendTo)
- Highlight: `ParallelRegionJob` runs listeners on the worker
- Highlight: `ChildMachineCompletionJob` routes @done/@fail where listeners fire
- Tip: fire-and-forget delegation states are fleeting — filter in action if broadcast is noisy

#### Internal Events & Observability
- Table of all 9 listener internal events (sync + queued)
- Full event history example showing sync + queued flow
- Observability troubleshooting table (dispatched only = queue problem, etc.)

### 2. `docs/understanding/machine-lifecycle.md` — Update Execution Order

**Update "Transition Execution Order" ASCII diagram** to include listener steps:

```
│  3. LISTEN EXIT                                              │
│     └─► Run listen.exit on source (if non-transient)         │
│                                                               │
│  4. EXIT ACTIONS                                              │
│     └─► Run current state's exit actions                      │
│                                                               │
│  ...                                                          │
│                                                               │
│  7. ENTRY ACTIONS                                             │
│     └─► Run new state's entry actions                         │
│                                                               │
│  8. LISTEN ENTRY                                              │
│     └─► Run listen.entry on target (if non-transient)         │
│                                                               │
│  9. LISTEN TRANSITION                                         │
│     └─► Run listen.transition (always, unless transient)      │
```

**Update "2. Start / Initial State"** to mention listeners after initial state entry.

**Update Internal Events table** to include all 9 new listener events.

### 3. `docs/testing/test-machine.md` — Listener Testing

Brief subsection:
- `TestMachine::define()` with `listen` config
- Verify sync listener fired via context assertions
- `Queue::fake()` for queued listener testing
- Note: listeners work with all TestMachine construction methods

### 4. `docs/testing/recipes.md` — Listener Recipes

**Recipe: Broadcasting State Changes**
- Full working example: `BroadcastStateAction`, machine config with `listen`, test
- Shows the pattern from problem (13 states) to solution (1 line)

**Recipe: Queued Listener with Observability**
- Full working example: queued audit action, `Queue::fake()` test, internal event verification
- Shows the `dispatched` → `started` → `completed` chain

### 5. `tests/LocalQA/README.md` — Update with Listener Section

Add a "Testing Queued Listeners" section:
- Explain: `Queue::fake()` verifies dispatch, LocalQA verifies full pipeline
- Show how to run listener-specific LocalQA tests: `vendor/bin/pest tests/LocalQA/ListenerQueuedTest.php`
- Reminder: Horizon must be running with the listener queue configured

### Files to Modify (Documentation)

| File | Change |
|------|--------|
| `docs/building/defining-states.md` | New "Listeners" section (main documentation) |
| `docs/understanding/machine-lifecycle.md` | Update execution order diagram + internal events table |
| `docs/testing/test-machine.md` | Listener testing subsection |
| `docs/testing/recipes.md` | Broadcasting recipe + queued listener observability recipe |
| `tests/LocalQA/README.md` | Add queued listener testing section |
