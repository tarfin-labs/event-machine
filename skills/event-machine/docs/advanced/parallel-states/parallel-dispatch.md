# Parallel Dispatch

When a machine enters a parallel state, region entry actions normally run sequentially. **Parallel Dispatch** runs them as concurrent Laravel queue jobs, reducing total wall-clock time.

**Related pages:**
- [Parallel States Overview](./index) - Basic concepts and syntax
- [Event Handling](./event-handling) - Events, entry/exit actions, `@done`
- [Persistence](./persistence) - Database storage and restoration

## What It Does

Without parallel dispatch:
```
t=0s  Region A entry action (Inventory API)... 5 seconds
t=5s  Region B entry action (Payment API)... 2 seconds
t=7s  Both done → total: 7 seconds
```

With parallel dispatch:
```
t=0s  Dispatch Job A (inventory) + Job B (payment)
t=0s  Worker 1: Inventory API... | Worker 2: Payment API...
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
        'enabled'        => env('MACHINE_PARALLEL_DISPATCH_ENABLED', false),
        'queue'          => env('MACHINE_PARALLEL_DISPATCH_QUEUE', null),
        'lock_timeout'   => env('MACHINE_PARALLEL_DISPATCH_LOCK_TIMEOUT', 30),
        'lock_ttl'       => env('MACHINE_PARALLEL_DISPATCH_LOCK_TTL', 60),
        'job_timeout'    => env('MACHINE_PARALLEL_DISPATCH_JOB_TIMEOUT', 300),
        'job_tries'      => env('MACHINE_PARALLEL_DISPATCH_JOB_TRIES', 3),
        'job_backoff'    => env('MACHINE_PARALLEL_DISPATCH_JOB_BACKOFF', 30),
        'region_timeout' => env('MACHINE_PARALLEL_DISPATCH_REGION_TIMEOUT', 0),
    ],
];
```

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `false` | Master toggle for parallel dispatch |
| `queue` | `null` | Queue name for jobs (null = default queue) |
| `lock_timeout` | `30` | Seconds to wait for blocking lock |
| `lock_ttl` | `60` | Lock time-to-live before stale cleanup |
| `job_timeout` | `300` | Laravel job execution timeout (seconds) |
| `job_tries` | `3` | Max retry attempts for failed jobs |
| `job_backoff` | `30` | Seconds between retry attempts |
| `region_timeout` | `0` | Seconds before a parallel state is considered stuck (0 = disabled) |

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
Parallel regions **should** write to different context keys. If two regions write to the same key, the last job to acquire the lock wins (LWW). A `PARALLEL_CONTEXT_CONFLICT` internal event is recorded when this happens, so the overwrite is observable in machine history. Design your regions to write to unique keys (e.g., `inventory_result` vs `payment_result`) to avoid conflicts entirely.
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

### Conditional @done and @fail in Async Context

Both `@done` and `@fail` support [conditional branches with guards](./event-handling#conditional-done-with-guards). In the async dispatch context, region jobs and timeout jobs pass `null` as the `EventBehavior` parameter. The machine automatically creates a synthetic `EventDefinition` so that guards can evaluate normally:

```php ignore
'@fail' => [
    ['target' => 'retrying', 'guards' => CanRetryGuard::class, 'actions' => IncrementRetryAction::class],
    ['target' => 'failed',   'actions' => SendAlertAction::class],
],
```

This works identically whether triggered synchronously (from `transition()`) or asynchronously (from `ParallelRegionJob` / `ParallelRegionTimeoutJob`). Guards receive the current machine state and context — they do not depend on the originating event.

### Without `@fail`

The machine stays in the parallel state. A `PARALLEL_FAIL` internal event is recorded in history for debugging. The machine remains operable — you can send events manually or wait for retries.

### @fail Payload

The `@fail` event carries error details:

```php ignore
[
    'region_id' => 'order_workflow.processing.inventory',
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
'inventory' => [
    'entry' => CheckInventoryAction::class,  // writes inventory_result
],
'payment' => [
    'entry' => ValidatePaymentAction::class,   // writes payment_result
],
```

### 2. Keep Entry Actions Idempotent

Jobs may be retried. Entry actions should be safe to run multiple times:

```php ignore
class CheckInventoryAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // Idempotent: overwrites existing value
        $stock = InventoryApi::checkStock($context->get('orderId'));
        $context->set('inventory_result', $stock);
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
| `PARALLEL_REGION_TIMEOUT` | Parallel state did not complete within `region_timeout` | `parallel_state_id`, `timeout_seconds`, `stalled_regions` |
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

Always test your machines with dispatch both enabled and disabled. See [Testing Parallel Dispatch](#testing-parallel-dispatch) below.

::: tip Detailed Guide
For comprehensive design guidelines with Do/Don't examples, see [Parallel Patterns](/best-practices/parallel-patterns).
:::

## Stall Detection

When a region's entry action completes successfully but does **not** call `$this->raise()`, the region stays at its initial state. The job completes from Laravel's perspective (no retry), but the region never advances toward a final state.

This is detected automatically: if the region is still at its initial state after processing raised events (i.e., there were none), a `PARALLEL_REGION_STALLED` internal event is recorded.

::: info Stall Is Informational
The stall event is an **audit trail**, not an error. Some regions are intentionally designed to wait for external events (e.g., a webhook callback). The stall event makes this observable so operators can distinguish between "waiting by design" and "stuck by accident."
:::

### Stall Payload

```php ignore
[
    'region_id'        => 'order_workflow.processing.inventory',
    'initial_state_id' => 'order_workflow.processing.inventory.waiting',
    'context_changed'  => true,  // Entry action modified context but didn't raise events
]
```

The `context_changed` flag indicates whether the entry action had side effects. A stall with `context_changed: false` means the entry action was essentially a no-op.

## Region Timeout

Stall detection records an audit event but does not take corrective action. For production systems where a stuck parallel state is unacceptable, enable **region timeout** — a delayed check job that triggers `@fail` when the parallel state has not completed within the configured duration.

### Configuration

Set `region_timeout` to the maximum number of seconds a parallel state should remain active:

```php ignore
'parallel_dispatch' => [
    'region_timeout' => 120, // Trigger @fail after 2 minutes
],
```

When set to `0` (default), no timeout job is dispatched.

### How It Works

1. When `dispatchPendingParallelJobs()` dispatches region jobs, it also dispatches a single `ParallelRegionTimeoutJob` with a delay equal to `region_timeout` seconds.
2. When the delay expires, the timeout job checks whether the parallel state has completed (all regions final).
3. If the parallel state is **still active** with incomplete regions, it records a `PARALLEL_REGION_TIMEOUT` event and triggers `@fail` on the parallel state.
4. If the parallel state has already completed (or the machine has moved on), the timeout job is a no-op.

### Timeout Payload

```php ignore
[
    'parallel_state_id' => 'order_workflow.processing',
    'timeout_seconds'   => 120,
    'stalled_regions'   => [
        'order_workflow.processing.inventory',
        // Only regions that haven't reached final are listed
    ],
]
```

::: warning Requires @fail
The timeout job triggers `processParallelOnFail()`. Without a `@fail` target defined on the parallel state, the timeout event will be recorded but the machine will remain in the parallel state. Always define `@fail` alongside `region_timeout`:

```php ignore
'processing' => [
    'type'   => 'parallel',
    '@done'  => 'completed',
    '@fail'  => 'failed',    // Required for timeout recovery
    'states' => [...],
],
```
:::

::: tip Idempotent and Race-Safe
The timeout job is safe to fire multiple times. Once the machine transitions out of the parallel state (via `@fail` or `@done`), subsequent timeout checks are no-ops. If regions complete at the exact moment the timeout fires, the lock serializes both operations — only one of `@done` or `@fail` transitions the machine, never both.
:::

## Context Conflict Detection

When two regions write to the **same** context key, the second job to acquire the lock detects the conflict by comparing the current DB value against the **baseline snapshot** taken when the parallel state was entered (`contextAtDispatch`).

If the DB value differs from the baseline, a sibling region already modified that key. A `PARALLEL_CONTEXT_CONFLICT` internal event is recorded with the list of conflicted keys.

::: warning LWW Behavior Preserved
Context conflict detection is **observational only**. The second region's value still wins (last-writer-wins). The conflict event enables monitoring dashboards and alerts — it does not throw exceptions or block execution.
:::

### Conflict Payload

```php ignore
[
    'region_id'       => 'order_workflow.processing.payment',
    'conflicted_keys' => ['shared_total', 'shared_discount'],
]
```

### Avoiding Conflicts

**Each region should write to its own context keys.** This is the primary rule for safe parallel execution. The conflict event provides visibility when this rule is violated, but it does not prevent data loss.

```php ignore
// ✅ Good: each region writes to its own keys
'inventory' => ['entry' => CheckInventoryAction::class],    // writes inventory_result
'payment'   => ['entry' => ValidatePaymentAction::class],   // writes payment_result

// ❌ Bad: both regions write to the same key
'inventory' => ['entry' => CheckInventoryAction::class],    // writes shared_total
'payment'   => ['entry' => ValidatePaymentAction::class],   // also writes shared_total → LWW!
```

::: info Design Decision
This is an intentional design choice. The W3C SCXML specification and XState both use last-writer-wins for parallel region data conflicts (both are single-threaded, so document order determines the winner). Actor-model systems (Akka, Temporal, Restate) eliminate the problem entirely by forbidding shared mutable state between parallel units.

EventMachine takes the middle path: LWW for simplicity, audit events for observability. Config-level key partitioning was considered but rejected as contrary to EventMachine's "minimum config, maximum convention" philosophy.
:::

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
            'data' => $machine->state->context->get('data'),
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

## Testing Parallel Dispatch

<!-- doctest-attr: ignore -->
```php
it('works with parallel dispatch', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);
    // ... test with dispatched jobs
});

it('works without parallel dispatch', function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
    // ... test with sequential execution
});
```

::: tip Full Testing Guide
For comprehensive parallel dispatch testing patterns, see [Parallel Testing](/testing/parallel-testing).
:::

## Limitations

1. **No cancellation** — Once dispatched, jobs cannot be cancelled (they no-op if machine state changes)
2. **Queue dependency** — Requires a functioning queue system with workers
3. **Lock contention** — High-throughput machines may experience lock wait times
4. **Timeout requires `@fail`** — `region_timeout` records a timeout event and calls `processParallelOnFail()`, but without a `@fail` target the machine remains in the parallel state
