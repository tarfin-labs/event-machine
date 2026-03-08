# Parallel Dispatch

When a machine enters a parallel state, region entry actions normally run sequentially. **Parallel Dispatch** runs them as concurrent Laravel queue jobs, reducing total wall-clock time.

**Related pages:**
- [Parallel States Overview](./index) - Basic concepts and syntax
- [Event Handling](./event-handling) - Events, entry/exit actions, `@done`
- [Persistence](./persistence) - Database storage and restoration

## What It Does

Without parallel dispatch:
```
t=0s  Region A entry action (findeks API)... 5 seconds
t=5s  Region B entry action (turmob API)... 2 seconds
t=7s  Both done → total: 7 seconds
```

With parallel dispatch:
```
t=0s  Dispatch Job A (findeks) + Job B (turmob)
t=0s  Worker 1: findeks API... | Worker 2: turmob API...
t=2s  Worker 2: done → lock → merge context → unlock
t=5s  Worker 1: done → lock → merge context → unlock
t=5s  Total: 5 seconds (max of the two, not sum)
```

## How It Works

The lifecycle has three phases:

### Phase 1 — HTTP Request
```
Controller → Machine::create() → enters parallel state
→ enterParallelState() persists state (all regions at initial)
→ records pending dispatches for each region with entry actions
→ returns immediately → controller returns HTTP response
→ Machine::send() finally block: dispatchPendingParallelJobs()
```

### Phase 2 — Parallel Execution (Queue Workers)
Each `ParallelRegionJob` independently:
1. Reconstructs machine from database
2. Runs entry action (the expensive API call — **no lock held**)
3. Acquires blocking database lock
4. Reloads fresh state (sees other jobs' changes)
5. Applies context diff (merge, not overwrite)
6. Processes raised events
7. Checks `areAllRegionsFinal()` → fires `@done` if ready
8. Persists and releases lock

### Phase 3 — Continuation
The **last job to complete** naturally becomes the orchestrator. Its `areAllRegionsFinal()` returns true → `@done` fires → machine transitions to the next state.

## Configuration

Enable parallel dispatch in `config/machine.php`:

```php ignore
return [
    'parallel_dispatch' => [
        'enabled'      => env('MACHINE_PARALLEL_DISPATCH', false),
        'queue'        => env('MACHINE_PARALLEL_QUEUE', null),
        'lock_timeout' => env('MACHINE_PARALLEL_LOCK_TIMEOUT', 30),
        'lock_ttl'     => env('MACHINE_PARALLEL_LOCK_TTL', 60),
    ],
];
```

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `false` | Master toggle for parallel dispatch |
| `queue` | `null` | Queue name for jobs (null = default queue) |
| `lock_timeout` | `30` | Seconds to wait for blocking lock |
| `lock_ttl` | `60` | Lock time-to-live before stale cleanup |

## Requirements

Parallel dispatch requires:
1. **`should_persist` must be `true`** — jobs reconstruct state from database
2. **Machine must extend `Machine` class** — not inline `MachineDefinition::define()`
3. **At least 2 regions with entry actions** — otherwise sequential is faster
4. **A queue driver** — `database`, `redis`, `sqs`, etc.

When any requirement is not met, entry actions run sequentially (existing behavior).

## How Region Jobs Work

### Context Merge Strategy

Each job snapshots context **before** running entry actions, then computes a diff **after**:

```php ignore
// Inside ParallelRegionJob::handle()
$contextBefore = $machine->state->context->data;
$regionInitial->runEntryActions($machine->state);  // The expensive part
$contextAfter  = $machine->state->context->data;
$contextDiff   = $this->computeContextDiff($contextBefore, $contextAfter);
```

Under lock, the diff is applied to the **fresh** state (not the stale snapshot):

```php ignore
// Under lock — fresh state from DB
$freshMachine = $this->machineClass::create(state: $this->rootEventId);
foreach ($contextDiff as $key => $value) {
    $freshMachine->state->context->set($key, $value);
}
```

::: warning Context Key Isolation
Parallel regions **should** write to different context keys. If two regions write to the same key, the last job to acquire the lock wins (LWW). A `PARALLEL_CONTEXT_CONFLICT` internal event is recorded when this happens, so the overwrite is observable in machine history. Design your regions to write to unique keys (e.g., `findeks_report` vs `turmob_report`) to avoid conflicts entirely.
:::

### Double-Guard Pattern

Jobs check preconditions twice — once before running actions (without lock) and once under lock (with fresh state):

1. **Pre-lock guard**: `isInParallelState()`, region exists, region at initial state
2. **Under-lock guard**: Same checks repeated with fresh state from database

This ensures idempotent execution even with retries or race conditions.

If the under-lock guard detects the machine has moved on (either left parallel state entirely, or the region already advanced), a `PARALLEL_REGION_GUARD_ABORT` internal event is recorded. This event captures:
- The **reason** for the abort (`machine left parallel state` or `region already advanced`)
- **Discarded context keys** that were computed but not applied
- **Discarded event count** from raised events that were not processed
- Whether any **work was actually discarded** (`work_was_discarded` flag)

This makes discarded work observable in the machine's event history.

### Raised Events

If an entry action calls `$this->raise()`, the raised events are captured and processed **under lock** in the same lock scope:

```php ignore
// Events raised during entry action
$raisedEvents = [];
while ($definition->eventQueue->isNotEmpty()) {
    $raisedEvents[] = $definition->eventQueue->shift();
}

// Under lock: process each raised event
foreach ($raisedEvents as $event) {
    $freshMachine->state = $freshMachine->definition->transition($event, $freshMachine->state);
}
```

Raised events are scoped to the job that produced them — no cross-contamination between jobs.

## @fail Handling

When a job exhausts all retries, Laravel calls the `failed()` method. The job:

1. Acquires the database lock
2. Reconstructs the machine
3. Creates a `@fail` event with error details
4. Calls `processParallelOnFail()` on the parallel parent

### With `@fail` Configured

```php ignore
'processing' => [
    'type'   => 'parallel',
    '@done' => 'completed',
    '@fail' => 'failed',      // ← Target state on failure
    'states' => [...],
],
'failed' => ['type' => 'final'],
```

The machine exits the parallel state and transitions to the `@fail` target. Sibling jobs that haven't started will no-op (pre-lock guard). Sibling jobs that completed already have their context preserved.

### Without `@fail`

The machine stays in the parallel state. A `PARALLEL_FAIL` internal event is recorded in history for debugging. The machine remains operable — you can send events manually or wait for retries.

### @fail Payload

The `@fail` event carries error details:

```php ignore
[
    'region_id' => 'order_workflow.processing.findeks',
    'error'     => 'Connection timeout',
    'exception' => 'RuntimeException',
    'attempts'  => 3,
]
```

## Best Practices

### 1. Design for Independent Regions

Each region should write to its own context keys and not depend on other regions' entry action results:

```php ignore
// Good: independent keys
'findeks' => [
    'entry' => FindeksApiAction::class,  // writes findeks_report
],
'turmob' => [
    'entry' => TurmobApiAction::class,   // writes turmob_report
],
```

### 2. Keep Entry Actions Idempotent

Jobs may be retried. Entry actions should be safe to run multiple times:

```php ignore
class FindeksApiAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // Idempotent: overwrites existing value
        $report = FindeksApi::fetchReport($context->get('customer_id'));
        $context->set('findeks_report', $report);
    }
}
```

### 3. Use `@fail` for Error Handling

Always define `@fail` on parallel states that use dispatch. This provides a clean error state instead of leaving the machine stuck in parallel:

```php ignore
'processing' => [
    'type'   => 'parallel',
    '@done' => 'completed',
    '@fail' => 'failed',
    'states' => [...],
],
```

### 4. Monitor with Internal Events

The dispatch mechanism records internal events in machine history for full observability:

| Event | When | Payload |
|-------|------|---------|
| `PARALLEL_REGION_ENTER` | Job completes and persists context | — |
| `PARALLEL_REGION_GUARD_ABORT` | Under-lock guard discards work | `reason`, `discarded_context`, `discarded_events`, `work_was_discarded` |
| `PARALLEL_CONTEXT_CONFLICT` | Second region overwrites key set by first | `region_id`, `conflicted_keys` |
| `PARALLEL_REGION_STALLED` | Entry action completes but region does not advance | `region_id`, `initial_state_id`, `context_changed` |
| `PARALLEL_DONE` | All regions reach final, `@done` fires | — |
| `PARALLEL_FAIL` | Job failed after all retries | `region_id`, `error`, `exception`, `attempts` |

Use these events for monitoring and debugging. All events are persisted in `machine_events` as durable audit trail records — they are never lost, unlike log entries.

::: tip Querying Parallel Events
```php no_run
// Find all context conflicts for a machine
MachineEvent::where('root_event_id', $rootEventId)
    ->where('type', 'like', '%context.conflict%')
    ->get();

// Find stalled regions
MachineEvent::where('root_event_id', $rootEventId)
    ->where('type', 'like', '%region.stalled')
    ->get();

// Find guard aborts (discarded work)
MachineEvent::where('root_event_id', $rootEventId)
    ->where('type', 'like', '%guard_abort')
    ->get();
```
:::

### 5. Test Both Modes

Always test your machines with dispatch both enabled and disabled:

```php no_run
it('works with parallel dispatch', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);
    // ... test with dispatched jobs
});

it('works without parallel dispatch', function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
    // ... test with sequential execution
});
```

## Stall Detection

When a region's entry action completes successfully but does **not** call `$this->raise()`, the region stays at its initial state. The job completes from Laravel's perspective (no retry), but the region never advances toward a final state.

This is detected automatically: if the region is still at its initial state after processing raised events (i.e., there were none), a `PARALLEL_REGION_STALLED` internal event is recorded.

::: info Stall Is Informational
The stall event is an **audit trail**, not an error. Some regions are intentionally designed to wait for external events (e.g., a webhook callback). The stall event makes this observable so operators can distinguish between "waiting by design" and "stuck by accident."
:::

### Stall Payload

```php ignore
[
    'region_id'        => 'order_workflow.processing.findeks',
    'initial_state_id' => 'order_workflow.processing.findeks.waiting',
    'context_changed'  => true,  // Entry action modified context but didn't raise events
]
```

The `context_changed` flag indicates whether the entry action had side effects. A stall with `context_changed: false` means the entry action was essentially a no-op.

## Context Conflict Detection

When two regions write to the **same** context key, the second job to acquire the lock detects the conflict by comparing the current DB value against the **baseline snapshot** taken when the parallel state was entered (`contextAtDispatch`).

If the DB value differs from the baseline, a sibling region already modified that key. A `PARALLEL_CONTEXT_CONFLICT` internal event is recorded with the list of conflicted keys.

::: warning LWW Behavior Preserved
Context conflict detection is **observational only**. The second region's value still wins (last-writer-wins). The conflict event enables monitoring dashboards and alerts — it does not throw exceptions or block execution.
:::

### Conflict Payload

```php ignore
[
    'region_id'       => 'order_workflow.processing.turmob',
    'conflicted_keys' => ['shared_score', 'shared_rating'],
]
```

### Avoiding Conflicts

The best practice remains: each region should write to its own context keys. But when shared keys are unavoidable, the conflict event provides visibility.

## Controller Integration

When using parallel dispatch from a controller, the machine's `dispatched` property tells you whether region jobs were sent to the queue:

```php no_run
class OrderController extends Controller
{
    public function store(Request $request)
    {
        $machine = OrderMachine::create();
        $machine->persist();
        $machine->dispatchPendingParallelJobs();

        if ($machine->dispatched) {
            // Regions are running in queue workers — return early
            return response()->json([
                'status'  => 'processing',
                'message' => 'Order is being processed in the background.',
            ], 202);
        }

        // Sequential mode or no pending dispatches — machine already finished
        return response()->json([
            'status' => 'completed',
            'result' => $machine->state->context->get('result'),
        ]);
    }
}
```

The `dispatched` flag is:
- **`true`** after `dispatchPendingParallelJobs()` actually dispatches jobs
- **`false`** when dispatch is disabled, no pending dispatches exist, or the machine was restored from DB

::: tip Lifecycle Scope
The `dispatched` flag is a runtime property — it is not persisted to the database. Each `Machine::create()` or restore starts with `dispatched = false`. Only the explicit `dispatchPendingParallelJobs()` call can set it to `true`.
:::

## Limitations

1. **No cancellation** — Once dispatched, jobs cannot be cancelled (they no-op if machine state changes)
2. **Queue dependency** — Requires a functioning queue system with workers
3. **Lock contention** — High-throughput machines may experience lock wait times
