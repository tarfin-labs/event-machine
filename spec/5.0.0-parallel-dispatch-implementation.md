# Parallel Dispatch Implementation Plan

## Problem

When a machine enters a parallel state, all regions' entry actions run **sequentially** in the same HTTP request. If entry actions contain slow API calls (e.g., Findeks 5s + Turmob 2s), total time = 7s (sum) instead of 5s (max).

## Solution

Dispatch each region's entry action as a separate Laravel queue job. The expensive work runs truly in parallel across queue workers. State updates are serialized through database-level locks (dedicated `machine_locks` table).

## Scope

**Machine-driven parallelism only.** When a parallel state's regions have entry actions that run expensive operations (API calls, file processing), the package dispatches these as queue jobs instead of running them sequentially.

**Actor-driven parallelism** (different users sending events at different times) already works and is NOT affected by this change.

## User-Facing Change: ZERO

Same action classes, same guards, same events, same config. The user only needs:
1. `type: 'parallel'` in state config (already exists)
2. `parallel_dispatch.enabled: true` in `config/machine.php`

---

## Architecture

### Lifecycle

```
PHASE 1 - HTTP REQUEST (Controller thread)
├── Controller → $machine->send(EVENT)
├── Machine::send() acquires lock (immediate mode — fail if locked)
├── transition() → enterParallelState()
│   ├── Sets all regions to initial states
│   ├── Runs parallel state's own entry actions (if any)
│   ├── Skips region entry actions
│   └── Marks regions as "pending dispatch" on MachineDefinition
├── Machine::send() persists state (all regions at initial)
├── Machine::send() releases lock (finally block)
├── Machine::send() dispatches ParallelRegionJob per region (after lock release!)
└── Controller returns HTTP response

PHASE 2 - PARALLEL EXECUTION (Queue workers)
├── Worker A: ParallelRegionJob (region_findeks)
│   ├── Reconstruct machine from MRE (rootEventId)
│   ├── Guard: isInParallelState()? region still at initial?
│   ├── Run region's entry action (FindeksAPI call) ← NO LOCK
│   ├── Capture side effects (context diff + raised events)
│   ├── Acquire lock (blocking mode — wait up to 30s)
│   ├── Reload FRESH state from DB
│   ├── Guard (under lock): still in parallel state?
│   ├── Apply context diff (merge, not overwrite)
│   ├── Process raised events → region transitions
│   ├── Check areAllRegionsFinal() → not yet
│   ├── Persist updated state
│   └── Release lock
│
├── Worker B: ParallelRegionJob (region_turmob) [runs simultaneously]
│   ├── (same steps as Worker A)
│   ├── Check areAllRegionsFinal() → YES (last job)
│   ├── processParallelOnDone() → exit all regions → exit parallel → enter target
│   ├── Persist
│   ├── Release lock
│   └── Dispatch any new pending parallel jobs (if onDone target is parallel)

PHASE 3 - CONTINUATION
└── Last job's areAllRegionsFinal() returns true
    → processParallelOnDone() fires → machine transitions → persists
    → If onDone target is another parallel state → new dispatch cycle
    → Machine continues from within queue worker process
```

### Timing Example
```
t=0s  Controller: persist → dispatch(findeks_job, turmob_job) → return 200
t=0s  Worker A: FindeksAPI started... | Worker B: TurmobAPI started...
t=2s  Worker B: done → lock → fresh load → update turmob → persist → unlock
t=5s  Worker A: done → lock → fresh load (sees turmob.done) → update findeks
      → all final! → onDone → persist → unlock
Total: 5 seconds (max), not 7 seconds (sum)
```

---

## Implementation Steps

### Step 0: Early Validation — Fail Fast at Definition Time

Parallel dispatch has prerequisites. We validate these at machine creation time, not at runtime when it's too late to recover.

**0a. StateConfigValidator — Allow `onFail` key + Config-level validation**

Add `'onFail'` to the allowed state keys list (line 21 in `StateConfigValidator.php`):

```php
// Before:
'id', 'on', 'states', 'initial', 'type', 'meta', 'entry', 'exit', 'description', 'result', 'onDone',

// After:
'id', 'on', 'states', 'initial', 'type', 'meta', 'entry', 'exit', 'description', 'result', 'onDone', 'onFail',
```

Add to `validateParallelState()`:

```php
private static function validateParallelState(array $stateConfig, string $path): void
{
    // ...existing validations...

    // If parallel dispatch is enabled, validate prerequisites
    if (config('machine.parallel_dispatch.enabled', false)) {
        self::validateParallelDispatchPrerequisites($stateConfig, $path);
    }
}

private static function validateParallelDispatchPrerequisites(array $stateConfig, string $path): void
{
    // Parallel dispatch without persistence is impossible — jobs need DB to coordinate
    // Note: This checks the root config's should_persist flag which is passed through
    // the recursive validation. We access it via the static context.
}
```

However, `StateConfigValidator` doesn't have access to the root config's `should_persist`. Better to validate at `MachineDefinition` constructor level.

**0b. MachineDefinition constructor — After StateConfigValidator runs**

In `MachineDefinition::__construct()`, after line 92 (`$this->shouldPersist = ...`):

```php
// Validate parallel dispatch prerequisites
if (config('machine.parallel_dispatch.enabled', false)) {
    $this->validateParallelDispatchConfig();
}
```

New method on MachineDefinition:

```php
protected function validateParallelDispatchConfig(): void
{
    if (!$this->shouldPersist) {
        throw InvalidParallelStateDefinitionException::requiresPersistence();
    }

    // Check if any parallel state exists with entry actions on regions
    // This runs after the state tree is built, so we validate in a second pass
}
```

**0c. Machine::start() — Runtime validation**

```php
public function start(State|string|null $state = null): self
{
    $this->definition->machineClass = static::class;

    if (config('machine.parallel_dispatch.enabled', false)) {
        $this->validateParallelDispatchRuntime();
    }

    // ...existing start logic...
}

protected function validateParallelDispatchRuntime(): void
{
    // Machine must be a subclass — base Machine can't be reconstructed by jobs
    if (static::class === Machine::class) {
        throw InvalidParallelStateDefinitionException::requiresMachineSubclass();
    }
}
```

**0d. New exception methods on InvalidParallelStateDefinitionException**

```php
public static function requiresPersistence(): self
{
    return new self(
        'Parallel dispatch requires persistence (should_persist: true). ' .
        'Queue jobs need the database to coordinate state updates across workers.'
    );
}

public static function requiresMachineSubclass(): self
{
    return new self(
        'Parallel dispatch requires a Machine subclass with a definition() method. ' .
        'Queue jobs reconstruct the machine from the class name — the base Machine class cannot be used. ' .
        'Create a class like OrderMachine extends Machine and override definition().'
    );
}
```

**Validation summary:**

| When | What | Exception |
|------|------|-----------|
| `MachineDefinition::__construct()` | `parallel_dispatch.enabled` + `should_persist: false` | `requiresPersistence()` |
| `Machine::start()` | `parallel_dispatch.enabled` + base `Machine::class` | `requiresMachineSubclass()` |

---

### Step 1: Database Locks — `machine_locks` Table

Redis locks (`Cache::lock()`) are insufficient for parallel dispatch:
- Redis can lose keys during failover
- TTL-based expiration → lock can expire while process still runs
- Not in the same transaction scope as machine_events
- Hard to debug (not queryable)

Replace with database-level locks using a dedicated `machine_locks` table.

**1a. Migration: `create_machine_locks_table.php.stub`**

```php
Schema::create('machine_locks', function (Blueprint $table) {
    $table->ulid('root_event_id')->primary();
    $table->string('owner_id', 36);              // ULID of lock holder
    $table->dateTime('acquired_at');
    $table->dateTime('expires_at')->index();
    $table->string('context', 255)->nullable();   // 'send', 'parallel_region:findeks', etc.
});
```

- **Primary key = root_event_id** → database guarantees only ONE holder per machine
- **owner_id** → identifies which process/job holds the lock
- **expires_at** → self-healing stale locks from crashed processes
- **context** → debugging ("who holds this lock and why?")

**1b. Model: `MachineStateLock`**

```php
class MachineStateLock extends Model
{
    public $timestamps  = false;
    public $incrementing = false;
    protected $table    = 'machine_locks';
    protected $primaryKey = 'root_event_id';
    protected $keyType  = 'string';

    protected $fillable = [
        'root_event_id', 'owner_id', 'acquired_at', 'expires_at', 'context',
    ];

    protected $casts = [
        'acquired_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];
}
```

**1c. Service: `MachineLockManager`**

Two acquisition modes preserve the existing `Machine::send()` fail-fast semantics while enabling blocking waits for parallel region jobs:

- **`immediate`** (timeout=0): Try once, throw immediately if locked. Used by `Machine::send()` — preserves the existing `MachineAlreadyRunningException` behavior where a concurrent `send()` on the same machine fails instantly rather than queuing up.
- **`blocking`** (timeout=30): Retry with 100ms intervals. Used by `ParallelRegionJob` — jobs need to wait for sibling jobs that acquired the lock first.

```php
class MachineLockManager
{
    /**
     * Acquire a lock, waiting up to $timeout seconds.
     *
     * @param  int  $timeout  0 = immediate (fail if locked), >0 = block up to N seconds.
     *
     * @throws MachineLockTimeoutException  When timeout=0 and lock is held.
     * @throws MachineLockTimeoutException  When timeout>0 and lock not acquired within timeout.
     */
    public static function acquire(
        string $rootEventId,
        int $timeout = 0,
        int $ttl = 60,
        ?string $context = null,
    ): MachineLockHandle {
        $ownerId   = (string) Str::ulid();
        $start     = hrtime(true);
        $timeoutNs = $timeout * 1_000_000_000;

        while (true) {
            // Clean up expired locks (self-healing)
            MachineStateLock::where('expires_at', '<', now())->delete();

            try {
                MachineStateLock::create([
                    'root_event_id' => $rootEventId,
                    'owner_id'      => $ownerId,
                    'acquired_at'   => now(),
                    'expires_at'    => now()->addSeconds($ttl),
                    'context'       => $context,
                ]);

                return new MachineLockHandle($rootEventId, $ownerId);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // Immediate mode: fail on first attempt
                if ($timeout === 0) {
                    $holder = MachineStateLock::find($rootEventId);
                    throw MachineLockTimeoutException::build(
                        $rootEventId, 0, $holder?->context, $holder?->acquired_at
                    );
                }

                // Blocking mode: retry until timeout
                if ((hrtime(true) - $start) >= $timeoutNs) {
                    $holder = MachineStateLock::find($rootEventId);
                    throw MachineLockTimeoutException::build(
                        $rootEventId, $timeout, $holder?->context, $holder?->acquired_at
                    );
                }

                usleep(100_000); // 100ms between retries
            }
        }
    }
}
```

**1d. Value Object: `MachineLockHandle`**

```php
class MachineLockHandle
{
    public function __construct(
        private string $rootEventId,
        private string $ownerId,
    ) {}

    public function release(): void
    {
        MachineStateLock::where('root_event_id', $this->rootEventId)
            ->where('owner_id', $this->ownerId)
            ->delete();
    }

    /** Extend the lock TTL (for long-running operations). */
    public function extend(int $seconds = 60): void
    {
        MachineStateLock::where('root_event_id', $this->rootEventId)
            ->where('owner_id', $this->ownerId)
            ->update(['expires_at' => now()->addSeconds($seconds)]);
    }
}
```

**1e. Exception: `MachineLockTimeoutException`**

```php
class MachineLockTimeoutException extends \RuntimeException
{
    public static function build(
        string $rootEventId,
        int $timeout,
        ?string $holderContext = null,
        ?\DateTimeInterface $acquiredAt = null,
    ): self {
        $message = "Could not acquire lock for machine '{$rootEventId}' within {$timeout}s.";

        if ($holderContext !== null) {
            $message .= " Currently held by: {$holderContext}";
        }
        if ($acquiredAt !== null) {
            $message .= " (since {$acquiredAt->format('H:i:s')})";
        }

        return new self($message);
    }
}
```

**1f. Replace Cache::lock() in Machine::send()**

```php
// Before:
$lock = Cache::lock('mre:'.$this->state->history->first()->root_event_id, 60);
if (isset($lock) && !$lock->get()) {
    throw MachineAlreadyRunningException::build(...);
}

// After:
$lockHandle = null;
if ($this->state instanceof State) {
    $rootEventId = $this->state->history->first()->root_event_id;

    try {
        $lockHandle = MachineLockManager::acquire(
            rootEventId: $rootEventId,
            timeout: 0,  // ← immediate mode: fail if locked (preserves existing semantics)
            context: 'Machine::send',
        );
    } catch (MachineLockTimeoutException) {
        throw MachineAlreadyRunningException::build($rootEventId);
    }
}

try {
    // ...transition + persist...
} finally {
    $lockHandle?->release();

    // Dispatch pending parallel region jobs AFTER lock release.
    // This prevents deadlock with sync queue driver (where jobs
    // execute in the same process and would try to acquire the
    // same lock that send() still holds).
    $this->dispatchPendingParallelJobs();
}
```

**Why dispatch AFTER lock release:**
If using Laravel's `sync` queue driver (common in tests and local dev), dispatched jobs execute immediately in the same PHP process. If `send()` still holds the lock when the job runs, the job's `MachineLockManager::acquire()` (blocking mode) would wait forever → deadlock. Dispatching after `finally` release guarantees the lock is free when jobs start.

For async queue drivers (`redis`, `database`, `sqs`), jobs execute in separate worker processes with a natural delay, so dispatch timing doesn't matter. But the `finally` approach is safe for both.

**Why database locks are better for this use case:**

| | Redis (`Cache::lock`) | Database (`machine_locks`) |
|---|---|---|
| Durability | Volatile (can lose on failover) | ACID — same DB as machine_events |
| Transaction scope | Separate from DB writes | Same connection, can wrap in transaction |
| Debugging | `redis-cli` only | `SELECT * FROM machine_locks` |
| Stale detection | TTL-based (silent expiry) | Queryable: "this lock is 5min old, who holds it?" |
| Race conditions | Possible during Redis cluster splits | PK constraint = impossible |
| Dependencies | Requires Redis | Uses existing DB connection |

---

### Step 2: Config — `config/machine.php`

Add `parallel_dispatch` section:

```php
'parallel_dispatch' => [
    'enabled'      => env('MACHINE_PARALLEL_DISPATCH_ENABLED', false),
    'queue'        => env('MACHINE_PARALLEL_DISPATCH_QUEUE'),       // null = default
    'lock_timeout' => env('MACHINE_PARALLEL_DISPATCH_LOCK_TIMEOUT', 30), // seconds
    'lock_ttl'     => env('MACHINE_PARALLEL_DISPATCH_LOCK_TTL', 60),     // seconds
],
```

- `enabled`: opt-in, default false (no behavior change for existing users)
- `queue`: dedicated queue name for parallel region jobs
- `lock_timeout`: how long `MachineLockManager::acquire()` waits to acquire
- `lock_ttl`: how long a lock lives before considered stale

### Step 3: Machine.php — Dispatch Coordination

**3a. Set machine class + root event ID on definition (in `start()`)**

```php
public function start(State|string|null $state = null): self
{
    $this->definition->machineClass = static::class;

    $this->state = match (true) {
        // ...existing logic...
    };

    if ($this->state?->history?->first()) {
        $this->definition->rootEventId = $this->state->history->first()->root_event_id;
    }

    return $this;
}
```

**Why:** The job needs to reconstruct the Machine. It needs the class name and root event ID. MachineDefinition can't know these on its own.

**3b. Dispatch pending parallel region jobs (in `send()` — `finally` block)**

Dispatch is called in the `finally` block, **after lock release**. This prevents deadlock with the `sync` queue driver (see Step 1f for reasoning).

The `send()` method structure becomes:

```php
try {
    // ...transition + persist + handleValidationGuards...
} finally {
    $lockHandle?->release();
    $this->dispatchPendingParallelJobs();
}
```

New **public** method on Machine (must be public — ParallelRegionJob also calls it when onDone transitions into another parallel state):

```php
public function dispatchPendingParallelJobs(): void
{
    if (empty($this->definition->pendingParallelDispatches)) {
        return;
    }

    $rootEventId = $this->state->history->first()->root_event_id;
    $queue       = config('machine.parallel_dispatch.queue');

    foreach ($this->definition->pendingParallelDispatches as $dispatch) {
        $job = new ParallelRegionJob(
            machineClass:  static::class,
            rootEventId:   $rootEventId,
            regionId:      $dispatch['regionId'],
            eventPayload:  $dispatch['eventPayload'],
        );

        if ($queue !== null) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }

    $this->definition->pendingParallelDispatches = [];
}
```

**Key invariant:** Persist BEFORE dispatch. Jobs reload state from DB — it must be there. Dispatch AFTER lock release — prevents sync driver deadlock.

---

### Step 4: MachineDefinition — Pending Dispatch Mechanism

**4a. New properties**

```php
/** Machine class name for job reconstruction (set by Machine::start). */
public ?string $machineClass = null;

/** Root event ID for state restoration (set by Machine::start). */
public ?string $rootEventId = null;

/** Pending parallel region dispatches (consumed by Machine::send). */
public array $pendingParallelDispatches = [];
```

**4b. New public proxy: `createEventBehavior()` — for ParallelRegionJob**

`initializeEvent()` is `protected` and must stay that way (internal API). Instead of changing its visibility, we add a thin public proxy that ParallelRegionJob can use:

```php
/**
 * Public proxy for initializing an EventBehavior from raw event data.
 * Used by ParallelRegionJob to reconstruct the event behavior
 * for region entry actions.
 */
public function createEventBehavior(array $event, State $state): EventBehavior
{
    return $this->initializeEvent($event, $state);
}
```

This follows the existing event-machine pattern — public methods delegate to protected internals. The naming (`create` vs `initialize`) signals that this is a factory call.

**4c. Extract `processParallelOnDone()` from `transitionParallelState()`**

The onDone logic currently lives inline inside `transitionParallelState()` (lines 975-1034). ParallelRegionJob needs to call this same logic when the last job detects all regions are final. Extract it into a callable public method:

```php
/**
 * Process parallel state completion: fire onDone transition if all regions are final.
 *
 * Extracted from transitionParallelState() so that ParallelRegionJob can also
 * trigger onDone when the last completing job detects areAllRegionsFinal().
 */
public function processParallelOnDone(
    StateDefinition $parallelState,
    State $state,
    ?EventBehavior $eventBehavior = null,
): State {
    $state->setInternalEventBehavior(
        type: InternalEvent::PARALLEL_DONE,
        placeholder: $parallelState->route,
    );

    if (!isset($parallelState->config['onDone'])) {
        return $state;
    }

    $onDoneConfig = $parallelState->config['onDone'];
    $targetId     = is_array($onDoneConfig) ? ($onDoneConfig['target'] ?? null) : $onDoneConfig;

    if ($targetId === null) {
        return $state;
    }

    $targetState = $this->getNearestStateDefinitionByString($targetId, $parallelState);

    if (!$targetState instanceof StateDefinition) {
        return $state;
    }

    // Run exit actions on all active states (children before parent — SCXML test406)
    foreach ($state->value as $activeStateId) {
        $activeState = $this->idMap[$activeStateId] ?? null;
        $activeState?->runExitActions($state);

        // Record region exit
        $regionParent = $activeState?->parent;
        while ($regionParent !== null && $regionParent->parent !== $parallelState) {
            $regionParent = $regionParent->parent;
        }
        if ($regionParent !== null) {
            $state->setInternalEventBehavior(
                type: InternalEvent::PARALLEL_REGION_EXIT,
                placeholder: $regionParent->route,
            );
        }
    }

    // Exit the parallel state itself (after children — SCXML ordering)
    $parallelState->runExitActions($state);

    // Transition to target
    $initialState                  = $targetState->findInitialStateDefinition() ?? $targetState;
    $state->currentStateDefinition = $initialState;
    $state->value                  = [$state->currentStateDefinition->id];

    // Run entry actions on target
    $targetState->runEntryActions($state, $eventBehavior);
    if ($initialState !== $targetState) {
        $initialState->runEntryActions($state, $eventBehavior);
    }

    $state->setInternalEventBehavior(
        type: InternalEvent::STATE_ENTRY_FINISH,
        placeholder: $state->currentStateDefinition->route,
    );

    return $state;
}
```

**4c-ii. New method: `processParallelOnFail()` — `@fail` event handler**

Symmetric to `processParallelOnDone()`. Triggered by `ParallelRegionJob::failed()` when a region job exhausts all retries. Follows the same exit-all-regions → transition pattern.

```php
/**
 * Process parallel state failure: fire @fail transition when a region job fails permanently.
 *
 * Called by ParallelRegionJob::failed() when all retries are exhausted.
 * If no onFail handler is defined, the machine stays in the parallel state
 * (user's machine design choice — same as a parallel state without onDone).
 */
public function processParallelOnFail(
    StateDefinition $parallelState,
    State $state,
    ?EventBehavior $eventBehavior = null,
): State {
    $state->setInternalEventBehavior(
        type: InternalEvent::PARALLEL_FAIL,
        placeholder: $parallelState->route,
    );

    if (!isset($parallelState->config['onFail'])) {
        return $state;
    }

    $onFailConfig = $parallelState->config['onFail'];
    $targetId     = is_array($onFailConfig) ? ($onFailConfig['target'] ?? null) : $onFailConfig;

    if ($targetId === null) {
        return $state;
    }

    $targetState = $this->getNearestStateDefinitionByString($targetId, $parallelState);

    if (!$targetState instanceof StateDefinition) {
        return $state;
    }

    // Run onFail actions if defined (before exit — can inspect parallel state)
    if (is_array($onFailConfig) && isset($onFailConfig['actions'])) {
        $actions = (array) $onFailConfig['actions'];
        foreach ($actions as $actionName) {
            $action = $this->getInvokableBehavior($actionName, 'actions');
            if ($action !== null) {
                $action($state->context, $eventBehavior, $state);
            }
        }
    }

    // Run exit actions on all active states (children before parent — SCXML test406)
    foreach ($state->value as $activeStateId) {
        $activeState = $this->idMap[$activeStateId] ?? null;
        $activeState?->runExitActions($state);

        // Record region exit
        $regionParent = $activeState?->parent;
        while ($regionParent !== null && $regionParent->parent !== $parallelState) {
            $regionParent = $regionParent->parent;
        }
        if ($regionParent !== null) {
            $state->setInternalEventBehavior(
                type: InternalEvent::PARALLEL_REGION_EXIT,
                placeholder: $regionParent->route,
            );
        }
    }

    // Exit the parallel state itself (after children — SCXML ordering)
    $parallelState->runExitActions($state);

    // Transition to target
    $initialState                  = $targetState->findInitialStateDefinition() ?? $targetState;
    $state->currentStateDefinition = $initialState;
    $state->value                  = [$state->currentStateDefinition->id];

    // Run entry actions on target
    $targetState->runEntryActions($state, $eventBehavior);
    if ($initialState !== $targetState) {
        $initialState->runEntryActions($state, $eventBehavior);
    }

    $state->setInternalEventBehavior(
        type: InternalEvent::STATE_ENTRY_FINISH,
        placeholder: $state->currentStateDefinition->route,
    );

    return $state;
}
```

**New enum value: `InternalEvent::PARALLEL_FAIL`**

```php
// In src/Enums/InternalEvent.php, add:
case PARALLEL_FAIL = '{machine}.parallel.{placeholder}.fail';
```

Then refactor `transitionParallelState()` lines 975-1034 to call this extracted method:

```php
// In transitionParallelState(), replace the inline onDone block with:
if ($this->areAllRegionsFinal($state->currentStateDefinition, $state)) {
    return $this->processParallelOnDone($state->currentStateDefinition, $state, $eventBehavior);
}
```

**4d. Change `areAllRegionsFinal()` visibility to `public`**

Currently `protected`. ParallelRegionJob needs to call it to check parallel completion.

```php
// Before:
protected function areAllRegionsFinal(StateDefinition $parallelState, State $state): bool

// After:
public function areAllRegionsFinal(StateDefinition $parallelState, State $state): bool
```

This is safe because it's a pure read-only query — it doesn't modify any state.

**4e. New method: `shouldDispatchParallel()`**

```php
protected function shouldDispatchParallel(StateDefinition $parallelState): bool
{
    // Must be enabled in config
    if (!config('machine.parallel_dispatch.enabled', false)) {
        return false;
    }

    // Must have persistence (need DB for job coordination)
    if (!$this->shouldPersist) {
        return false;
    }

    // Must have a reconstructable Machine class
    if ($this->machineClass === null || $this->machineClass === Machine::class) {
        return false;
    }

    // Must have at least one region with entry actions worth parallelizing
    if ($parallelState->stateDefinitions === null) {
        return false;
    }

    $regionsWithEntryActions = 0;
    foreach ($parallelState->stateDefinitions as $region) {
        $regionInitial = $region->findInitialStateDefinition();
        if ($regionInitial !== null && !empty($regionInitial->entry)) {
            $regionsWithEntryActions++;
        }
    }

    // Only dispatch if 2+ regions have entry actions (otherwise no parallelism gain)
    return $regionsWithEntryActions >= 2;
}
```

**4f. Modify `enterParallelState()`**

```php
protected function enterParallelState(
    State $state,
    StateDefinition $parallelState,
    ?EventBehavior $eventBehavior = null
): void {
    // Record entering the parallel state
    $state->setInternalEventBehavior(
        type: InternalEvent::STATE_ENTER,
        placeholder: $parallelState->route,
    );

    // Run entry actions on the parallel state itself
    $parallelState->runEntryActions($state, $eventBehavior);

    // Collect all initial states from all regions
    $initialStates = $parallelState->findAllInitialStateDefinitions();
    $state->setValues(array_map(fn (StateDefinition $s): string => $s->id, $initialStates));

    // PARALLEL DISPATCH: skip entry actions, mark for dispatch
    if ($this->shouldDispatchParallel($parallelState)) {
        $eventPayload = $eventBehavior !== null
            ? ['type' => $eventBehavior->type]
            : [];

        foreach ($parallelState->stateDefinitions as $region) {
            $regionInitial = $region->findInitialStateDefinition();

            // Record region entry (logging only, no actions)
            $state->setInternalEventBehavior(
                type: InternalEvent::PARALLEL_REGION_ENTER,
                placeholder: $region->route,
            );

            if ($regionInitial !== null) {
                $state->setInternalEventBehavior(
                    type: InternalEvent::STATE_ENTER,
                    placeholder: $regionInitial->route,
                );

                // Regions WITH entry actions → dispatch as job
                if (!empty($regionInitial->entry)) {
                    $this->pendingParallelDispatches[] = [
                        'regionId'     => $region->id,
                        'eventPayload' => $eventPayload,
                    ];
                }
                // Regions WITHOUT entry actions → nothing to parallelize
            }
        }

        return;
    }

    // SEQUENTIAL MODE (existing behavior)
    if ($parallelState->stateDefinitions !== null) {
        foreach ($parallelState->stateDefinitions as $region) {
            $state->setInternalEventBehavior(
                type: InternalEvent::PARALLEL_REGION_ENTER,
                placeholder: $region->route,
            );
            $regionInitial = $region->findInitialStateDefinition();
            if ($regionInitial !== null) {
                $state->setInternalEventBehavior(
                    type: InternalEvent::STATE_ENTER,
                    placeholder: $regionInitial->route,
                );
                $regionInitial->runEntryActions($state, $eventBehavior);
            }
        }
    }
}
```

**4g. Modify `transitionParallelState()` — entering nested parallel state**

Lines 903-918 handle transitioning INTO a parallel state within a parallel region:

```php
if ($targetState->type === StateDefinitionType::PARALLEL) {
    // ... existing expansion + entry actions ...
}
```

Add the same dispatch check here. If dispatching, mark pending and skip entry actions.

---

### Step 5: ParallelRegionJob — The Queue Job

**File: `src/Jobs/ParallelRegionJob.php`**

```php
<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;

class ParallelRegionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;
    public int $tries   = 3;
    public int $backoff = 30;

    public function __construct(
        protected string $machineClass,
        protected string $rootEventId,
        protected string $regionId,
        protected array  $eventPayload,
    ) {}

    public function handle(): void
    {
        // 1. Reconstruct machine from DB
        /** @var Machine $machine */
        $machine    = $this->machineClass::create(state: $this->rootEventId);
        $definition = $machine->definition;

        // 2. Guard: is machine still in a parallel state?
        //    An external event (e.g., CANCEL) may have moved the machine out
        //    of the parallel state while this job was queued. In that case,
        //    the region no longer exists in the active state — gracefully no-op.
        if (!$machine->state->isInParallelState()) {
            return;
        }

        // 3. Find region's initial state
        $region = $definition->idMap[$this->regionId] ?? null;
        if ($region === null) {
            return;
        }

        $regionInitial = $region->findInitialStateDefinition();
        if ($regionInitial === null || empty($regionInitial->entry)) {
            return;
        }

        // 4. Guard: is this region still at its initial state?
        //    Another job or external event may have already advanced this region.
        if (!in_array($regionInitial->id, $machine->state->value, true)) {
            return;
        }

        // 5. Initialize event behavior via public proxy
        $eventBehavior = !empty($this->eventPayload)
            ? $definition->createEventBehavior($this->eventPayload, $machine->state)
            : null;

        // 6. Snapshot context BEFORE entry actions
        $contextBefore = $machine->state->context->toArray();

        // ═══════════════════════════════════════════════
        // 7. RUN ENTRY ACTIONS (expensive part — NO LOCK)
        // ═══════════════════════════════════════════════
        $regionInitial->runEntryActions($machine->state, $eventBehavior);

        // 8. Capture side effects
        $contextAfter = $machine->state->context->toArray();
        $contextDiff  = $this->computeContextDiff($contextBefore, $contextAfter);

        $raisedEvents = [];
        while ($definition->eventQueue->isNotEmpty()) {
            $raisedEvents[] = $definition->eventQueue->shift();
        }

        // ═══════════════════════════════════════════════
        // 9. ACQUIRE DATABASE LOCK (blocking) — serialize state updates
        // ═══════════════════════════════════════════════
        $lockTimeout = (int) config('machine.parallel_dispatch.lock_timeout', 30);
        $lockTtl     = (int) config('machine.parallel_dispatch.lock_ttl', 60);
        $lockHandle  = MachineLockManager::acquire(
            rootEventId: $this->rootEventId,
            timeout: $lockTimeout,
            ttl: $lockTtl,
            context: "parallel_region:{$this->regionId}",
        );

        try {
            // 10. Reload FRESH state from DB (sees other jobs' changes)
            /** @var Machine $freshMachine */
            $freshMachine = $this->machineClass::create(state: $this->rootEventId);

            // 11. Guard: re-check under lock — machine may have left parallel state
            if (!$freshMachine->state->isInParallelState()) {
                return; // finally block releases lock
            }

            // 12. Apply context diff (merge, not overwrite)
            if (!empty($contextDiff)) {
                $freshContext = $freshMachine->state->context->toArray();
                $merged = $this->arrayRecursiveMerge($freshContext, $contextDiff);
                foreach ($merged as $key => $value) {
                    $freshMachine->state->context->set($key, $value);
                }
            }

            // 13. Process raised events (causes region transitions)
            //     transition() handles @always, compound onDone, event queue internally.
            //     Each raised event may advance this region toward its final state.
            foreach ($raisedEvents as $event) {
                $freshMachine->state = $freshMachine->definition->transition(
                    $event,
                    $freshMachine->state
                );
            }

            // 14. Check parallel completion — areAllRegionsFinal + onDone
            //     persist() only writes to DB — it does NOT trigger onDone.
            //     We must explicitly check and handle parallel completion here.
            //
            //     IMPORTANT: We cannot use $freshMachine->state->currentStateDefinition
            //     because that points to one of the active region child states, NOT the
            //     parallel state itself. Instead, navigate from the region to its parent.
            $freshRegion    = $freshMachine->definition->idMap[$this->regionId] ?? null;
            $parallelParent = $freshRegion?->parent;
            if ($parallelParent !== null && $freshMachine->definition->areAllRegionsFinal($parallelParent, $freshMachine->state)) {
                // All regions done — trigger onDone transition via the definition.
                // $eventBehavior is null here (no originating event in job context).
                // runEntryActions() accepts nullable $eventBehavior — safe.
                $freshMachine->state = $freshMachine->definition
                    ->processParallelOnDone($parallelParent, $freshMachine->state);
            }

            // 15. Persist final state
            $freshMachine->persist();

            // 16. Dispatch any new pending parallel jobs
            //     (e.g., onDone target is itself a parallel state)
            $freshMachine->dispatchPendingParallelJobs();

        } finally {
            $lockHandle->release();
        }
    }

    /**
     * Compute the diff between two context arrays.
     * Returns only keys that changed or were added.
     */
    protected function computeContextDiff(array $before, array $after): array
    {
        $diff = [];
        foreach ($after as $key => $value) {
            if (!array_key_exists($key, $before) || $before[$key] !== $value) {
                $diff[$key] = $value;
            }
        }
        return $diff;
    }

    /**
     * Recursively merge arrays (same as Machine::arrayRecursiveMerge).
     */
    protected function arrayRecursiveMerge(array $array1, array $array2): array
    {
        $merged = $array1;
        foreach ($array2 as $key => &$value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->arrayRecursiveMerge($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }
        return $merged;
    }

    /**
     * Called by Laravel when all retries are exhausted.
     *
     * Triggers the @fail event on the parallel state. If onFail is defined,
     * the machine transitions to the error target state. If not defined,
     * the machine stays in the parallel state (user's design choice).
     *
     * Other region jobs that are still running will detect the machine
     * left the parallel state via the double-guard pattern and no-op.
     */
    public function failed(\Throwable $exception): void
    {
        try {
            $lockTimeout = (int) config('machine.parallel_dispatch.lock_timeout', 30);
            $lockTtl     = (int) config('machine.parallel_dispatch.lock_ttl', 60);
            $lockHandle  = MachineLockManager::acquire(
                rootEventId: $this->rootEventId,
                timeout: $lockTimeout,
                ttl: $lockTtl,
                context: "parallel_region_fail:{$this->regionId}",
            );

            try {
                /** @var Machine $machine */
                $machine = $this->machineClass::create(state: $this->rootEventId);

                // Guard: machine may have already left parallel state
                if (!$machine->state->isInParallelState()) {
                    return;
                }

                $region         = $machine->definition->idMap[$this->regionId] ?? null;
                $parallelParent = $region?->parent;

                if ($parallelParent === null) {
                    return;
                }

                // Create @fail event with failure details as payload.
                // User's onFail actions can access this via EventBehavior parameter.
                $failEvent = EventDefinition::from([
                    'type'    => '@fail',
                    'payload' => [
                        'region_id' => $this->regionId,
                        'error'     => $exception->getMessage(),
                        'exception' => get_class($exception),
                        'attempts'  => $this->attempts(),
                    ],
                ]);

                // Trigger @fail — transitions to onFail target if defined,
                // otherwise just records the PARALLEL_FAIL internal event.
                $machine->state = $machine->definition->processParallelOnFail(
                    $parallelParent,
                    $machine->state,
                    $failEvent,
                );

                $machine->persist();
                $machine->dispatchPendingParallelJobs();

            } finally {
                $lockHandle->release();
            }

        } catch (\Throwable $e) {
            // Last resort: if even the @fail handler fails, log both errors.
            logger()->error('ParallelRegionJob: @fail handler also failed', [
                'machine_class'      => $this->machineClass,
                'root_event_id'      => $this->rootEventId,
                'region_id'          => $this->regionId,
                'original_error'     => $exception->getMessage(),
                'fail_handler_error' => $e->getMessage(),
            ]);
        }
    }
}
```

---

## Critical Design Decisions

### 1. Entry actions run WITHOUT lock

The entire point of parallelism. The API call (expensive part) runs in a queue worker without holding the lock. Only the state update afterwards acquires the lock.

### 2. Fresh state reload under lock

After the entry action completes, the job reloads the ENTIRE machine state from DB. This ensures it sees changes made by other jobs that completed first. Context diff is applied on top of this fresh state.

### 3. Last job orchestrates

No separate coordinator process. The last job to complete naturally finds `areAllRegionsFinal() === true` and fires `processParallelOnDone()`. This is an explicit check in the job — `persist()` does NOT trigger onDone automatically.

### 4. Pending dispatch pattern

`MachineDefinition::enterParallelState()` doesn't dispatch jobs directly (it has no access to the Machine class or persistence). Instead, it marks pending dispatches. `Machine::send()` consumes these after persist.

### 5. Opt-in via config

`parallel_dispatch.enabled = false` by default. Zero behavior change for existing users.

### 6. Requires Machine subclass

Parallel dispatch only works with Machine subclasses that override `definition()` (e.g., `OrderMachine extends Machine`). Inline `MachineDefinition::define()` usage falls back to sequential.

### 7. Two lock modes: immediate vs blocking

`Machine::send()` uses **immediate** mode (timeout=0) — preserves the existing fail-fast `MachineAlreadyRunningException` semantics. `ParallelRegionJob` uses **blocking** mode (timeout=30) — jobs wait for sibling jobs that hold the lock. This preserves backward compatibility while enabling parallel execution.

### 8. Dispatch after lock release (sync driver safety)

Jobs are dispatched in the `finally` block **after** lock release. With the `sync` queue driver (common in tests and local dev), dispatched jobs execute immediately in the same PHP process. If `send()` still held the lock, the job would deadlock trying to acquire it. The `finally` approach is safe for both sync and async drivers.

### 9. Double-guard pattern in jobs

Jobs check parallel state validity at two points: (1) before running entry actions (early bail-out), and (2) under lock after fresh state reload (authoritative check). An external `CANCEL` event could transition the machine out of the parallel state between these two checks. The second check under lock is the authoritative one.

### 10. Public proxy for protected internals

`initializeEvent()` stays `protected` (internal API boundary). ParallelRegionJob uses `createEventBehavior()` — a thin public proxy. This follows event-machine's existing pattern where public methods delegate to protected internals.

### 11. Extracted `processParallelOnDone()` with optional `$eventBehavior`

The onDone logic is extracted from `transitionParallelState()` into a public `processParallelOnDone()` method. Both `transitionParallelState()` (sequential path) and `ParallelRegionJob` (parallel path) call the same extracted method — single source of truth for onDone semantics. The method accepts an optional `?EventBehavior $eventBehavior = null` parameter: the sequential path passes the original transition event, the parallel path passes `null` (no originating event in job context). `runEntryActions()` already accepts nullable `$eventBehavior`.

### 12. Parallel parent resolution via `$region->parent`

In ParallelRegionJob, the parallel state parent is found via `$definition->idMap[$this->regionId]->parent`, NOT via `$freshMachine->state->currentStateDefinition`. The `currentStateDefinition` points to one of the active region child states (e.g., `findeks_check.checking`), not the parallel state itself. The region ID stored in the job is stable and its parent relationship is structural (won't change at runtime).

### 13. Entry action idempotency is the user's responsibility

Region entry actions run BEFORE the database lock is acquired. If the action succeeds but the lock acquisition or persist fails, Laravel retries the job — re-running the entry action. The package does NOT guarantee idempotency of entry actions. Users should make parallel entry actions idempotent (e.g., idempotency keys for API calls, check-before-write patterns). This is documented as a constraint.

### 14. In-memory event queue does NOT need persistence

The `MachineDefinition::eventQueue` (`Collection`) stores raised events in memory. Actions call `$this->raise()` which pushes to this shared queue. The queue is processed after each transition completes.

**Analysis across 4 scenarios shows no event loss in parallel dispatch:**

| Scenario | Why safe |
|----------|----------|
| Region entry actions raise events | Phase 1 skips entry actions → no raise. Phase 2: each job has its own isolated eventQueue, captures raised events locally, processes under lock. |
| Parallel state's own entry actions raise events | Runs in Phase 1 (same process), queue processed by `getInitialState()` or `transitionParallelState()` before returning — identical to sequential mode. |
| Exit/transition actions raise events before parallel entry | Runs in Phase 1 (same process), queue processed before dispatch. |
| Cross-region raised events (Job A's raise affects Region B) | Job A processes its own raised events via `transition()` under lock. `transitionParallelState()` broadcasts to all regions. Double-guard in Job B catches stale state. |

**Constraint:** Parallel state's own entry actions MUST NOT raise events that target dispatched regions. In dispatch mode, region entry actions haven't run yet — an event transitioning a region before its entry action completes violates SCXML entry-action-before-transition semantics (SCXML test372).

**Why NOT persist:** Each job reconstructs the machine with a fresh empty eventQueue. The job runs entry actions → events are raised locally → captured in `$raisedEvents` array → processed under lock via `transition()`. The in-memory queue is always scoped to the current process. Cross-process event coordination is handled by the lock + fresh state reload pattern, not by a shared queue.

### 15. `@fail` is an internal event — symmetric to `@done`

`@fail` follows the same pattern as `@done` and `@always` — it's a package-level internal event, not a user-sent event. The naming with `@` prefix makes the family relationship clear:

| Event | Config Key | Trigger | Behavior |
|-------|-----------|---------|----------|
| `@always` | `on: { '@always': ... }` | Every state change | Guard-based conditional transition |
| `@done` | `onDone` | All regions reach final | Exit parallel → enter target |
| `@fail` | `onFail` | Region job exhausts all retries | Exit parallel → enter error target |

The `@fail` event carries failure details as payload (region_id, error message, exception class, attempt count). User's `onFail` actions can access this via the `EventBehavior` parameter — same DI pattern as all other behaviors.

### 16. `@fail` and `@done` are optional — machine design choice

Neither `onDone` nor `onFail` is mandatory. This is a deliberate machine design choice:

- **No `onDone`:** Machine stays in parallel state after all regions complete. External events can still advance it. Valid for actor-driven parallelism (e.g., waiting for human input).
- **No `onFail`:** Machine stays in parallel state after a region fails. Manual intervention required. The `PARALLEL_FAIL` internal event is still recorded in history for debugging.
- **Both defined:** Recommended for machine-driven parallelism with dispatch. The machine handles both success and failure paths automatically.

No validation exception is thrown for missing `onDone`/`onFail`. This is documented as a machine design guideline, not a framework constraint.

### 17. `@fail` cancels sibling jobs via double-guard

When `@fail` fires and transitions the machine out of the parallel state, sibling jobs that are still running will naturally detect this via the double-guard pattern:

1. **Job still in entry action (no lock):** Finishes action → acquires lock → reloads fresh state → `isInParallelState() === false` → no-op. The action's side effects are "wasted" but the state is consistent.
2. **Job waiting for lock:** `@fail` handler releases lock → sibling acquires → reloads → not in parallel → no-op.
3. **Job not yet started:** Reconstructs machine → `isInParallelState() === false` → no-op.

No explicit "cancel" mechanism is needed. The lock + fresh state reload pattern provides natural cancellation.

### 18. Both regions fail → first `@fail` wins

If both Region A and Region B fail, the first `failed()` to acquire the lock triggers `@fail` and transitions the machine out. The second `failed()` reloads fresh state → machine is no longer in parallel state → no-op. Only the first failure's details are in the `@fail` payload. This is acceptable — the machine is in an error state either way.

---

## Edge Cases & Constraints

| Case | Handling | Source |
|------|----------|--------|
| Region has no entry actions | Not dispatched as job (skipped) | Design |
| Only 1 region has entry actions | Falls back to sequential (no parallelism gain) | Design |
| `shouldPersist = false` | Falls back to sequential + fail-fast validation | Design |
| Inline `MachineDefinition::define()` | Falls back to sequential (can't reconstruct) | Design |
| Context key conflict between regions | Last writer wins. Document: regions SHOULD write separate keys | Design |
| Job fails (API timeout) | Laravel retry (3 attempts, 30s backoff). Region stays at initial | Spring SM #493 |
| Both jobs finish at exact same instant | DB PK constraint → second waits 100ms, retries | Design |
| External event during job execution | Lock serializes. Job reloads fresh state. Graceful no-op if machine left parallel state | Design |
| Done event ordering | Region done events fire BEFORE parallel done | SCXML test570 |
| Done event timing | Done fires AFTER all onentry actions complete | SCXML test372 |
| Entry ordering | Parent before children on entry | SCXML test404 |
| Exit ordering | Children before parent on exit | SCXML test406 |
| Internal events priority | Raised events processed before external events | SCXML test421 |
| Same event in multiple regions | All regions transition simultaneously | XState parallel |
| Cross-region targeting | No re-entry of parallel state itself | XState parallel |
| Self-transition in parallel region | Exit + re-entry, actions fire again | XState parallel |
| History states in parallel | Do not interfere with areAllRegionsFinal() | XState #3170 |
| Targetless transition | No-op (no state change, no re-entry) | XState parallel |
| Nested parallel within parallel | Inner dispatches independent of outer | XState parallel |
| Stale lock from crashed process | Self-healing via expires_at cleanup | Design |
| onDone sees merged context | Last job reloads fresh state → full context | Design |
| All jobs fail | Machine stuck at initial. Manual intervention. Logged via failed() | Spring SM |
| Job retry runs entry action again | Entry actions are NOT idempotent by default. If action succeeds but lock acquisition fails, retry re-runs the action (e.g., duplicate API call). Users SHOULD make entry actions idempotent or use Laravel's `UniqueJob` trait. Document as constraint. | Review finding |
| Sync queue driver | Dispatch after lock release (finally block) prevents deadlock | Review finding |
| Machine::send() lock semantics | Immediate mode (timeout=0) → preserves MachineAlreadyRunningException | Review finding |
| initializeEvent() is protected | Public proxy: createEventBehavior() — no visibility change | Review finding |
| persist() doesn't trigger onDone | Job explicitly calls areAllRegionsFinal() + processParallelOnDone() | Review finding |
| Job runs but machine left parallel | Double-guard: check before action + check under lock after reload | Review finding |
| onDone target is parallel state | Job dispatches new pending parallel jobs after processParallelOnDone | Review finding |
| In-memory event queue in parallel dispatch | NOT persisted. Each job has isolated queue. Events captured locally, processed under lock | Event queue analysis |
| Parallel state's own entry raises region-targeting event | CONSTRAINT: must not target dispatched regions. Entry actions haven't run yet | Event queue analysis |
| Cross-region raised event from job | Job processes via transition() → broadcasts to all regions. Sibling job's double-guard catches stale state | Event queue analysis |
| Region entry action raises multiple events | All captured locally in raisedEvents[], processed sequentially under lock | Event queue analysis |
| @fail with onFail defined | failed() acquires lock → processParallelOnFail → exits parallel → enters error state | @fail design |
| @fail without onFail defined | failed() acquires lock → PARALLEL_FAIL event recorded → machine stays in parallel state | @fail design |
| @fail cancels sibling job | @fail transitions out → sibling job's double-guard detects not-in-parallel → no-op | @fail design |
| Both regions fail → first @fail wins | First failed() acquires lock → @fail fires. Second failed() → not-in-parallel → no-op | @fail design |
| @fail during sibling's entry action | Sibling finishes action → lock → fresh reload → not-in-parallel → no-op. Action side effects wasted but state consistent | @fail design |
| @fail payload contains failure details | EventBehavior payload: region_id, error message, exception class, attempt count | @fail design |
| @fail handler itself fails | Last-resort catch logs both original + handler errors. Machine stays in parallel state | @fail design |
| onFail target is another parallel state | processParallelOnFail enters new parallel → marks pending dispatches → job dispatches them | @fail design |
| @fail preserves completed region's context | failed() reloads fresh state → context from completed sibling preserved in merge | @fail design |

---

## Test Plan

### Phase A: Lock Tests

1. **MachineLockManager::acquire() acquires lock**
   - Returns MachineLockHandle, row visible in machine_locks

2. **MachineLockManager::acquire() blocks when lock held**
   - Second acquire waits, acquires after first releases

3. **MachineLockManager::acquire() times out**
   - Throws MachineLockTimeoutException with holder info after timeout

4. **Stale lock cleanup**
   - Expired lock (expires_at < now) is cleaned up before new acquisition

5. **MachineLockHandle::release() removes lock**
   - Row deleted from machine_locks, subsequent acquire succeeds immediately

6. **MachineLockHandle::extend() extends TTL**
   - expires_at updated without releasing lock

7. **Machine::send() uses database lock (regression)**
   - All existing tests pass with new lock mechanism

### Phase B: Validation Tests

8. **parallel_dispatch.enabled + should_persist:false → throws requiresPersistence**
   - At MachineDefinition construction time

9. **parallel_dispatch.enabled + base Machine::class → throws requiresMachineSubclass**
   - At Machine::start() time

10. **parallel_dispatch.disabled → no validation (existing behavior)**
    - Both shouldPersist:false and base Machine work fine when dispatch disabled

### Phase C: Parallel Dispatch Tests — Core

11. **`shouldDispatchParallel()` returns correct results**
    - `false` when config disabled / shouldPersist false / < 2 regions with entry actions
    - `true` when all conditions met

12. **`enterParallelState()` marks pending dispatches**
    - Dispatch enabled: `pendingParallelDispatches` populated, entry actions NOT run
    - Dispatch disabled: entry actions run normally (existing behavior)

13. **`Machine::send()` dispatches jobs after persist**
    - Jobs dispatched with correct machineClass, rootEventId, regionId, eventPayload
    - `pendingParallelDispatches` cleared after dispatch

14. **ParallelRegionJob processes entry action and updates state**
    - Job runs entry action, applies context changes, persists

15. **Two regions complete with correct context merge**
    - Region A sets `key_a`, Region B sets `key_b`
    - After both complete, context has both keys

16. **Last job triggers areAllRegionsFinal + onDone**
    - First job completes → region A final, region B still working
    - Second job completes → both final → onDone fires → next state

17. **Raised events from entry actions cause region transitions**
    - Entry action raises `REPORT_SAVED` → region transitions to next state

18. **@always transitions fire after parallel region completion**
    - Cross-region sync: retailer waits for customer policy → @always fires

19. **Chained compound onDone within parallel dispatch**
    - Entry action → raise event → final → compound onDone → next state

20. **Fallback to sequential when conditions not met**
    - Config disabled → normal sequential behavior

### Phase D: Edge Cases from Ecosystem Research

These tests are derived from XState parallel tests, SCXML W3C conformance tests, Spring State Machine issues, and Temporal patterns.

#### Done Event Ordering (SCXML test570, test417, test372)

21. **Region done events fire BEFORE parallel done event**
    - SCXML test570: When all regions reach final, `done.state.region_a` and `done.state.region_b` fire first, THEN `done.state.parallel_parent` fires.
    - Verify: In parallel dispatch, the last completing job must fire done events in correct order.

22. **Compound child done.state fires within parallel region**
    - SCXML test417: A compound state inside a parallel region reaching its final child fires `done.state.compound_parent`. This must work even when the compound state's entry actions ran as a dispatched job.

23. **Region done event fires AFTER all onentry actions complete**
    - SCXML test372: The done.state.region event must NOT fire until entry actions on the final state are fully complete. In parallel dispatch, the job must finish entry actions before declaring region done.

#### Entry/Exit Action Ordering (SCXML test404, test405, test406)

24. **Parallel state entry: parent entered before children**
    - SCXML test404: When entering a parallel state, the parallel state's entry actions run first, then each region's initial state entry actions.
    - In parallel dispatch: parallel state entry actions run synchronously in Phase 1, region entry actions run in Phase 2 jobs.

25. **Parallel state exit: children exited before parent**
    - SCXML test406: When exiting a parallel state, each region's current state exit actions run first, then the parallel state's exit actions.
    - Verify: When a job triggers onDone and exits the parallel state, exit ordering is correct.

26. **Entry/exit ordering across default entry into nested parallel compound states**
    - SCXML test405: Complex nesting: parallel → region → compound → initial state. Entry order must be: parallel → region → compound → initial.

#### Simultaneous Orthogonal Transitions (XState parallel tests)

27. **Same event transitions multiple regions simultaneously**
    - XState: Sending event `E` when both region_a and region_b have transitions on `E` → both regions transition simultaneously.
    - In parallel dispatch: This applies to events sent AFTER initial dispatch, while regions are in intermediate states. Each region's transition must be processed.

28. **Targetless transition in parallel region is a no-op**
    - XState: A targetless transition (no `target` property) in a parallel region should NOT cause state change or re-entry. It just fires actions.
    - Verify: No spurious entry/exit actions fire.

#### Cross-Region Targeting (XState)

29. **Cross-region event does NOT re-enter parallel state**
    - XState: Event targeting a state in ANOTHER region should NOT cause re-entry of the parallel state itself. Only the target region transitions.
    - Verify: Parallel state entry/exit actions do NOT fire for cross-region transitions.

30. **Re-entering transitions fire entry actions again**
    - XState: A transition targeting a state the region is already in (self-transition) MUST fire exit then entry actions again.
    - In parallel dispatch: If a job's raised event causes re-entry, entry actions must fire.

#### Internal Events Priority (SCXML test421)

31. **Internal events (raised) take priority over external events**
    - SCXML test421: If an entry action raises an internal event, that internal event is processed BEFORE any external events in the queue.
    - In parallel dispatch: Each job processes its own raised events fully before releasing the lock.

#### History States in Parallel (XState issue #3170)

32. **History states do NOT interfere with areAllRegionsFinal()**
    - XState #3170: When a parallel state has history states, re-entering via history should correctly evaluate areAllRegionsFinal(). History pseudo-states should not count as active states.
    - Current implementation: Verify `areAllRegionsFinal()` ignores history states.

#### Job Failure & Partial State (Spring SM #493 pattern)

33. **Single region job failure leaves machine in partial-final state**
    - Spring SM lesson: Entry actions must complete atomically before transitions.
    - If Region A job succeeds (region → final) but Region B job fails (stays at initial):
      - Machine stays in parallel state (not all regions final)
      - areAllRegionsFinal() returns false
      - Laravel retries the failed job (3 attempts)
      - After retry succeeds, areAllRegionsFinal() → onDone fires

34. **Job failure does NOT corrupt other regions' state**
    - When Region B job fails, Region A's successful state changes are preserved.
    - Fresh state reload ensures each job works with consistent DB state.

35. **All region jobs fail → machine stuck in parallel initial**
    - After all retries exhausted, machine remains at all regions' initial states.
    - Manual intervention required (documented). `failed()` method logs details.

#### Context Merge Edge Cases

36. **Deeply nested context keys merge correctly**
    - Region A writes `{report: {findeks: {score: 750}}}`, Region B writes `{report: {turmob: {status: 'clean'}}}`.
    - After merge: `{report: {findeks: {score: 750}, turmob: {status: 'clean'}}}`.
    - Uses `arrayRecursiveMerge()` — same as existing incremental context storage.

37. **Both regions write to same top-level key → last writer wins**
    - Region A writes `{status: 'a_done'}`, Region B writes `{status: 'b_done'}`.
    - Last job to acquire lock overwrites. Document this as a constraint: parallel regions SHOULD write to separate context keys.

38. **Empty context diff does NOT cause spurious persist**
    - If an entry action doesn't modify context, contextDiff is empty → no context merge needed.

#### Lock Contention Edge Cases

39. **Both jobs finish at exact same millisecond → one waits, both succeed**
    - Database PK constraint prevents simultaneous insert. Second job retries after 100ms.
    - Verify: Both jobs eventually complete, state is consistent.

40. **Lock TTL expires during long entry action → stale lock self-heals**
    - If a previous crash left a stale lock (expires_at < now), new acquire() cleans it up.
    - Verify: `MachineStateLock::where('expires_at', '<', now())->delete()` runs before insert attempt.

41. **Lock extend() during very long entry action**
    - If an action takes > lock_ttl, the job should extend the lock before it expires.
    - Note: In current design, entry actions run WITHOUT lock. Only the state update phase holds the lock. This is unlikely to be an issue.

#### External Events During Dispatch (Race Condition)

42. **External event sent while region jobs are in-flight**
    - User sends `CANCEL` event to machine while parallel region jobs are running.
    - Machine::send() acquires lock → transitions machine out of parallel state.
    - When region job finishes and reloads state, machine is no longer in parallel state.
    - Job should detect this and gracefully no-op (region no longer exists in current state).

43. **Multiple external events interleaved with job completions**
    - Event A (external) → lock → transition → release → Job B completes → lock → ...
    - Each operation sees fresh state from DB. Lock serializes all writes.

#### Nested Parallel State Dispatch (transitionParallelState)

44. **Transition INTO a nested parallel state within a region dispatches correctly**
    - Region's event causes transition to a sub-state that is itself parallel.
    - `transitionParallelState()` at line 903-918: should dispatch nested parallel entry actions.
    - Nested parallel dispatch creates new set of ParallelRegionJobs for the sub-regions.

45. **Three-level nesting: parallel → region → parallel → region**
    - Outer parallel dispatches 2 region jobs. One of those regions enters an inner parallel state.
    - Inner parallel dispatches its own region jobs. Independent lock acquisition for inner dispatches.

#### onDone Timing

46. **onDone actions see fully merged context from all regions**
    - When the last job triggers onDone, the machine state has context from ALL regions (merged via fresh reload).
    - onDone actions and the target state's entry actions can access complete context.

47. **onDone target state's entry actions fire in the last job's process**
    - The last completing job fires onDone → transitions → runs target state entry actions.
    - If those entry actions are expensive, they run in the queue worker (acceptable, as only one job does this).

### Phase E: Review-Discovered Edge Cases

These tests address issues found during plan review by verifying the actual code paths.

#### Lock Mode Semantics

48. **Machine::send() uses immediate lock mode (timeout=0)**
    - Two concurrent `send()` calls: first acquires lock, second throws `MachineAlreadyRunningException` immediately (no waiting).
    - Existing semantics preserved — no behavioral change from Redis→DB migration.

49. **ParallelRegionJob uses blocking lock mode (timeout=30)**
    - Two region jobs finish simultaneously: first acquires lock, second waits up to 30s.
    - Both eventually complete. No exception thrown.

#### Sync Queue Driver Safety

50. **Sync queue driver does NOT deadlock**
    - With `QUEUE_CONNECTION=sync`, `Machine::send()` dispatches jobs in `finally` block (after lock release).
    - Dispatched job runs immediately in same process, acquires lock (immediate for send, blocking for job) — succeeds because send's lock is already released.
    - Verify: full parallel dispatch flow works end-to-end with sync driver.

51. **Async queue driver dispatch timing**
    - With `QUEUE_CONNECTION=redis/database`, jobs are queued but not executed until a worker picks them up.
    - Verify: jobs see persisted state when they eventually run.

#### Job Guard Checks

52. **Job no-ops when machine is no longer in parallel state (before action)**
    - External `CANCEL` event transitions machine out of parallel state.
    - Job reconstructs machine, sees `isInParallelState() === false`, returns without running action.

53. **Job no-ops when machine is no longer in parallel state (under lock)**
    - External event arrives AFTER job starts entry action but BEFORE job acquires lock.
    - Job acquires lock, reloads fresh state, sees `isInParallelState() === false`, returns.

54. **Job no-ops when region is no longer at initial state**
    - Retry scenario: first attempt partially succeeded, region already advanced.
    - Job checks `in_array($regionInitial->id, $state->value)` → false → returns.

#### API Surface Changes

55. **`createEventBehavior()` delegates to `initializeEvent()`**
    - Returns same `EventBehavior` instance as `initializeEvent()` would.
    - Accepts array input, returns EventBehavior.

56. **`areAllRegionsFinal()` is public and read-only**
    - Calling it does not modify state or definition.
    - Returns correct result for: all final, partially final, none final.

57. **`processParallelOnDone()` produces same result as inline logic**
    - Extract refactoring: `transitionParallelState()` using `processParallelOnDone()` passes all existing parallel state tests.
    - Zero regression from the extraction.

#### Chained Parallel Dispatch

58. **onDone target is another parallel state → new dispatch cycle**
    - Last job triggers onDone → target state is also `type: 'parallel'`.
    - `processParallelOnDone()` enters the new parallel state → marks pending dispatches.
    - Job calls `dispatchPendingParallelJobs()` → new set of ParallelRegionJobs dispatched.
    - Verify: second-level jobs complete and trigger their own onDone.

### Phase F: Event Queue Isolation Tests

These tests verify that the in-memory event queue (`MachineDefinition::eventQueue`) works correctly in parallel dispatch mode — no events are lost or duplicated across processes.

#### Region Entry Action Raises (main path)

59. **Region entry action raises single event → captured and processed by job**
    - Region A's entry action raises `REPORT_SAVED`.
    - Job captures it in `$raisedEvents`, processes via `transition()` under lock.
    - Region A transitions from `checking` → `report_saved` → final.
    - Verify: event not lost, region reaches correct final state.

60. **Region entry action raises multiple events → all processed in order**
    - Region A's entry action raises `STEP_1_DONE` then `STEP_2_DONE`.
    - Job captures both, processes sequentially under lock.
    - Verify: transitions happen in correct order (`initial → step1 → step2 → final`).

61. **Region entry action raises NO events → context-only update works**
    - Region A's entry action only calls `$context->set('score', 750)`, no `$this->raise()`.
    - Job captures empty `$raisedEvents`, applies context diff only.
    - Region stays at initial state (no transition triggered).
    - Verify: context is correctly merged, no spurious transitions.

62. **Two regions raise different events → each job processes its own**
    - Region A raises `FINDEKS_DONE`, Region B raises `TURMOB_DONE`.
    - Job A processes `FINDEKS_DONE` (transitions region A).
    - Job B processes `TURMOB_DONE` (transitions region B).
    - Last job sees areAllRegionsFinal() → onDone.
    - Verify: no event cross-contamination between jobs.

#### Parallel State's Own Entry Action Raises

63. **Parallel state's entry action raises event → processed in Phase 1 (same process)**
    - Parallel state has entry action that raises `SETUP_COMPLETE`.
    - `enterParallelState()` runs entry action → event goes to eventQueue.
    - After `enterParallelState()` returns, `getInitialState()` (line 266) or `transitionParallelState()` (line 1037) processes the event.
    - Verify: event processed in HTTP request, not lost, regions still dispatch correctly.

64. **Parallel state's entry action raises event that does NOT target regions → safe**
    - Raised event has no handler in any region (e.g., it's a logging/tracking event).
    - `transitionParallelState()` finds no transitions → `@always` check returns current state.
    - Verify: no exception, no spurious transitions, dispatch proceeds normally.

#### Cross-Region Raised Events

65. **Job A's raised event broadcasts to all regions via transitionParallelState()**
    - Region A's entry action raises `DATA_AVAILABLE`.
    - Job A processes under lock: `transition(DATA_AVAILABLE)` → `transitionParallelState()`.
    - If Region B has a handler for `DATA_AVAILABLE`, Region B transitions (in Job A's lock scope).
    - Verify: Region B's state updated correctly in DB.

66. **Cross-region event advances sibling → sibling job detects stale state and no-ops**
    - Continuation of test 65: Job A's cross-region event moved Region B beyond initial.
    - Job B finishes its entry action, reloads fresh state under lock.
    - Guard: `in_array($regionInitial->id, $state->value)` → false (region already advanced).
    - Verify: Job B no-ops gracefully, no duplicate transitions, no duplicate entry actions side effects in DB.

67. **Cross-region event when sibling job hasn't started yet → no race condition**
    - Job A completes quickly, cross-region event advances Region B before Job B even starts.
    - Job B reconstructs machine → guard before action: region not at initial → no-op.
    - Verify: Region B's entry action never runs. Context from Job A's cross-region transition is preserved.

#### Event Queue Isolation Verification

68. **Job's eventQueue starts empty (not carried over from Phase 1)**
    - Phase 1: parallel state's own entry action raises an event, processed in-process.
    - Phase 2: Job reconstructs machine → `eventQueue` is fresh empty `Collection`.
    - Verify: `$definition->eventQueue->count() === 0` at job start.

69. **Multiple jobs do NOT share eventQueue (process isolation)**
    - Two jobs run in parallel (async workers).
    - Job A raises `EVENT_A`, Job B raises `EVENT_B`.
    - Job A's eventQueue contains only `EVENT_A`, Job B's contains only `EVENT_B`.
    - Verify: no cross-contamination. Each job's `$raisedEvents` array is independent.

### Phase G: @fail Event Tests

These tests verify the `@fail` internal event — symmetric to `@done`, triggered when a region job permanently fails.

#### Basic @fail Flow

70. **Region job fails with onFail defined → machine transitions to error state**
    - Region A's entry action throws. 3 retries exhausted.
    - `failed()` acquires lock → `processParallelOnFail()` → exits parallel → enters error state.
    - Verify: machine is in error state, `PARALLEL_FAIL` internal event in history.

71. **Region job fails without onFail defined → machine stays in parallel state**
    - No `onFail` config. Region A fails permanently.
    - `failed()` acquires lock → `processParallelOnFail()` → records `PARALLEL_FAIL` → returns (no transition).
    - Verify: machine still in parallel state, `PARALLEL_FAIL` in history for debugging.

72. **@fail payload contains failure details**
    - Region A fails with `RuntimeException('API timeout')`.
    - onFail action receives `EventBehavior` with payload: `region_id`, `error`, `exception`, `attempts`.
    - Verify: action can read `$event->payload['region_id']` and `$event->payload['error']`.

#### @fail and Sibling Job Interaction

73. **@fail cancels sibling job that hasn't started yet**
    - Region A fails → @fail → machine exits parallel.
    - Region B job starts, reconstructs machine → `isInParallelState() === false` → no-op.
    - Verify: Region B's entry action never runs.

74. **@fail cancels sibling job that is running entry action**
    - Region A fails → @fail → machine exits parallel.
    - Region B finishes entry action → acquires lock → fresh reload → not in parallel → no-op.
    - Verify: Region B's state changes are discarded, machine is in error state.

75. **@fail cancels sibling job waiting for lock**
    - Region A's `failed()` holds lock → processes @fail → releases.
    - Region B was waiting → acquires lock → fresh reload → not in parallel → no-op.
    - Verify: no race condition, consistent state.

76. **Both regions fail → first @fail wins, second no-ops**
    - Region A and Region B both exhaust retries.
    - First `failed()` acquires lock → @fail → transitions to error state.
    - Second `failed()` acquires lock → reloads → not in parallel → no-op.
    - Verify: machine in error state, only first failure's payload in @fail event.

#### @fail with Completed Sibling

77. **One region completes, other fails → completed context preserved**
    - Region B completes (context: `turmob_report`), persists.
    - Region A fails → `failed()` reloads fresh state (has `turmob_report`) → @fail transition.
    - Verify: error state's entry actions can access `turmob_report` from context.

78. **One region completes and triggers compound onDone, then sibling fails**
    - Region B completes → compound onDone within region → region reaches final.
    - Region A fails → @fail fires.
    - Verify: Region B's compound onDone state changes preserved in context.

#### @fail Edge Cases

79. **@fail handler itself throws → last-resort logging**
    - `processParallelOnFail()` throws during exit actions.
    - Outer catch logs both original error and handler error.
    - Verify: machine stays in parallel state (no partial transition), both errors logged.

80. **onFail target is another parallel state → new dispatch cycle**
    - `onFail: 'retry_with_fallback'` where target is `type: 'parallel'`.
    - @fail triggers → exits current parallel → enters new parallel → dispatches new jobs.
    - Verify: new parallel state jobs dispatched correctly.

81. **onFail with actions config**
    - `onFail: { target: 'error', actions: 'logFailureAction' }`.
    - onFail actions run before exit actions (can inspect parallel state).
    - Verify: action runs, then exit, then enter error state.

82. **@fail after external event already moved machine out of parallel**
    - External `CANCEL` event → machine no longer in parallel.
    - Region A fails → `failed()` → lock → reload → not in parallel → no-op.
    - Verify: no @fail transition, no error, graceful no-op.

---

## File Change Summary

| File | Change | Complexity |
|------|--------|------------|
| `config/machine.php` | Add `parallel_dispatch` section | Low |
| `database/migrations/create_machine_locks_table.php.stub` **(NEW)** | Lock table migration | Low |
| `src/Models/MachineStateLock.php` **(NEW)** | Lock model | Low |
| `src/Locks/MachineLockManager.php` **(NEW)** | Lock acquire/release service | Medium |
| `src/Locks/MachineLockHandle.php` **(NEW)** | Lock handle value object | Low |
| `src/Exceptions/MachineLockTimeoutException.php` **(NEW)** | Lock timeout exception | Low |
| `src/Exceptions/InvalidParallelStateDefinitionException.php` | Add `requiresPersistence()` + `requiresMachineSubclass()` | Low |
| `src/Actor/Machine.php` | Replace Cache::lock → DB lock (immediate mode), `dispatchPendingParallelJobs()` public, runtime validation | Medium |
| `src/Enums/InternalEvent.php` | Add `PARALLEL_FAIL` enum case | Low |
| `src/Definition/MachineDefinition.php` | `createEventBehavior()` proxy, extract `processParallelOnDone()` + `processParallelOnFail()`, `areAllRegionsFinal()` → public, pending dispatch + `enterParallelState` + `shouldDispatchParallel` + validation | High |
| `src/Jobs/ParallelRegionJob.php` **(NEW)** | Queue job with double-guard pattern, blocking lock, explicit onDone, @fail in failed() | High |
| `src/MachineServiceProvider.php` | Register new migration | Low |
| `tests/` **(NEW)** | 82 tests across 7 phases (locks, validation, dispatch, ecosystem, review, event queue, @fail) | High |
| `docs/advanced/parallel-states/parallel-dispatch.md` **(NEW)** | Full parallel dispatch guide (~300 lines) | High |
| `docs/.vitepress/config.ts` | Add sidebar entry for parallel-dispatch | Low |
| `docs/advanced/parallel-states/index.md` | Add related page link + best practice #7 | Low |
| `docs/advanced/parallel-states/event-handling.md` | Add dispatch timing tip + context warning | Low |
| `docs/advanced/parallel-states/persistence.md` | Add machine_locks migration section | Low |
| `docs/advanced/raised-events.md` | Add parallel dispatch event queue constraint warning | Low |
| `docs/building/configuration.md` | Add parallel_dispatch config reference | Low |
| `docs/getting-started/upgrading.md` | Add upgrade notes (migration + breaking lock change) | Medium |

---

## Implementation Order

### Phase A: Foundation (no behavior change)
1. `config/machine.php` — add parallel_dispatch config section
2. `create_machine_locks_table.php.stub` — migration
3. `MachineStateLock` — model
4. `MachineLockManager` + `MachineLockHandle` — lock service
5. `MachineLockTimeoutException` — exception
6. **Tests for lock mechanism** — acquire, release, block, timeout, stale cleanup
7. `Machine::send()` — replace `Cache::lock()` with `MachineLockManager`
8. **Run ALL existing tests** — verify zero regression

### Phase B: Validation (fail-fast)
9. `InvalidParallelStateDefinitionException` — add new factory methods
10. `MachineDefinition::__construct()` — validate parallel_dispatch + shouldPersist
11. `Machine::start()` — validate parallel_dispatch + Machine subclass
12. **Tests for validation** — each validation scenario

### Phase C: Parallel Dispatch (the feature)
13. `InternalEvent` — add `PARALLEL_FAIL` enum case
14. `MachineDefinition` — add `createEventBehavior()` public proxy
15. `MachineDefinition` — extract `processParallelOnDone()` from `transitionParallelState()`
16. `MachineDefinition` — add `processParallelOnFail()` (symmetric to onDone)
17. `MachineDefinition` — change `areAllRegionsFinal()` to public
18. **Run ALL existing tests** — verify extract refactoring causes zero regression
19. `MachineDefinition` — add properties + `shouldDispatchParallel()` + modify `enterParallelState()`
20. `Machine::send()` — add `dispatchPendingParallelJobs()` in finally block (after lock release)
21. `ParallelRegionJob` — the queue job (with double-guard, @fail in failed())
22. `transitionParallelState()` — handle entering nested parallel states
23. **Integration tests** — parallel completion, context merge, onDone, @always

### Phase D: Edge Case Tests (from ecosystem research)
24. **Done event ordering tests** — region done before parallel done (SCXML test570)
25. **Entry/exit ordering tests** — parent before children on entry, reverse on exit (SCXML test404-406)
26. **Internal events priority test** — raised events processed before external (SCXML test421)
27. **Simultaneous orthogonal transitions test** — same event in multiple regions (XState)
28. **Cross-region targeting test** — no parallel state re-entry (XState)
29. **Job failure & partial state tests** — single failure, all failures, no corruption (Spring SM #493)
30. **Context merge edge cases** — deep nesting, same key conflict, empty diff
31. **External event during dispatch test** — graceful no-op when machine left parallel state
32. **Nested parallel dispatch test** — parallel within parallel region
33. **onDone context visibility test** — onDone actions see fully merged context from all regions

### Phase E: Review-Discovered Edge Cases
34. **Lock mode semantics** — immediate for send, blocking for jobs (#48-49)
35. **Sync queue driver safety** — no deadlock with sync, correct timing with async (#50-51)
36. **Job guard checks** — no-op on stale state before action, under lock, on advanced region (#52-54)
37. **API surface tests** — createEventBehavior, areAllRegionsFinal readonly, processParallelOnDone parity (#55-57)
38. **Chained parallel dispatch** — onDone target is parallel → second dispatch cycle (#58)

### Phase F: Event Queue Isolation Tests
39. **Region raise tests** — single, multiple, no-raise context-only, two regions independent (#59-62)
40. **Parallel state's own entry raise** — processed in Phase 1, non-targeting event safe (#63-64)
41. **Cross-region raise tests** — broadcast via transitionParallelState, sibling no-op, pre-start race (#65-67)
42. **Queue isolation verification** — fresh empty queue per job, no cross-contamination (#68-69)

### Phase G: @fail Event Tests
43. **Basic @fail flow** — with onFail (transitions), without onFail (stays), payload contents (#70-72)
44. **@fail and sibling interaction** — cancels not-started, cancels running, cancels waiting, both fail (#73-76)
45. **@fail with completed sibling** — context preserved, compound onDone preserved (#77-78)
46. **@fail edge cases** — handler throws, onFail target is parallel, onFail with actions, external event pre-empts (#79-82)

### Phase H: Documentation

All documentation follows the existing VitePress structure under `docs/`.

#### G1. New file: `docs/advanced/parallel-states/parallel-dispatch.md`

The main documentation page for parallel dispatch. This is the most detailed doc — covers everything a user needs to know.

**Outline:**

```
# Parallel Dispatch

## What is Parallel Dispatch?
- Problem: sequential region entry actions (7s vs 5s example)
- Solution: Laravel queue jobs per region
- Machine-driven vs actor-driven parallelism distinction
- Zero code changes — same actions, guards, events

## How It Works
### Lifecycle
- Phase 1: HTTP Request (persist + dispatch)
- Phase 2: Parallel Execution (queue workers)
- Phase 3: Continuation (last job orchestrates)
- ASCII/mermaid sequence diagram of the 3 phases

### Timing Example
- Findeks 5s + Turmob 2s = 5s (max) not 7s (sum)
- Side-by-side comparison: sequential vs parallel

## Configuration
### Enabling Parallel Dispatch
- `config/machine.php` parallel_dispatch section
- All 4 config keys explained with examples
- Environment variables

### Publishing the Migration
- `php artisan vendor:publish` command for machine_locks table
- `php artisan migrate`
- Table structure explanation (root_event_id PK, owner_id, expires_at)

## Requirements
### Machine Subclass Required
- Why: jobs reconstruct machine from class name
- Example: OrderMachine extends Machine with definition()
- What happens without: falls back to sequential (no error)

### Persistence Required
- Why: jobs coordinate via database
- should_persist must be true
- Fail-fast: InvalidParallelStateDefinitionException at construction time

### Queue Worker Required
- At least one queue worker must be running
- Recommended: dedicated queue with `parallel_dispatch.queue` config
- Sync driver works (for testing) but no actual parallelism

## How Region Jobs Work
### Entry Action Execution
- Action runs WITHOUT lock (the parallel part)
- Context snapshot before/after → diff
- Raised events captured locally

### State Update (Under Lock)
- Database lock acquired (blocking mode, up to 30s)
- Fresh state reloaded from DB (sees other jobs' changes)
- Context diff merged (not overwritten)
- Raised events processed via transition()
- areAllRegionsFinal() checked

### Parallel Completion (onDone)
- Last completing job detects all regions final
- processParallelOnDone() fires → exits parallel → enters target
- Target state's entry actions run in the last job's process
- If target is also parallel → new dispatch cycle

## Constraints and Best Practices

### Region Independence
- Regions SHOULD be independent (statechart best practice)
- Cross-region coordination: use @always guards, not raised events
- Reference: docs/advanced/parallel-states/index.md "Best Practices" section

### Context Key Separation
- Each region SHOULD write to separate context keys
- Example: region_findeks writes `findeks_report`, region_turmob writes `turmob_report`
- Conflict: same key → last writer wins (non-deterministic order)
- Deeply nested keys merge correctly via arrayRecursiveMerge

### Event Queue Behavior
- In-memory event queue is NOT shared across jobs
- Each job has its own isolated eventQueue
- Events raised by region entry actions are captured locally per job
- Parallel state's own entry actions MUST NOT raise events targeting dispatched regions
  (entry actions haven't run yet — violates SCXML entry-before-transition semantics)
- Cross-region raised events: processed under lock, sibling jobs detect stale state

### External Events During Dispatch
- External events (e.g., CANCEL) can be sent while jobs are in-flight
- Machine::send() acquires lock (immediate mode) → transitions machine
- When job completes and reloads state, it detects machine left parallel state → graceful no-op
- No special handling needed — lock serializes all state updates

### Job Failure and the @fail Event
- Laravel retry: 3 attempts, 30s backoff (configurable)
- After all retries exhausted → `@fail` internal event fires
- If `onFail` is defined → machine transitions to error target state
- If `onFail` is not defined → machine stays in parallel state (design choice)
- Sibling jobs detect machine left parallel state → graceful no-op (natural cancellation)

#### Defining onFail

\`\`\`php ignore
'data_collection' => [
    'type'   => 'parallel',
    'onDone' => 'review',
    'onFail' => [
        'target'  => 'error',
        'actions' => 'handleFailureAction',
    ],
    'states' => [
        'region_findeks' => [...],
        'region_turmob'  => [...],
    ],
],
\`\`\`

The `@fail` event carries failure details as payload. Your onFail action
can access them via the `EventBehavior` parameter:

\`\`\`php ignore
class HandleFailureAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $failInfo = $event->payload;
        // $failInfo['region_id']  — which region failed
        // $failInfo['error']      — exception message
        // $failInfo['exception']  — exception class name
        // $failInfo['attempts']   — total attempts made
    }
}
\`\`\`

::: warning Entry Action Idempotency
Region entry actions run **before** the database lock is acquired. If the action
succeeds (e.g., API call returns) but the subsequent lock acquisition fails or the
job crashes, Laravel will retry the job — causing the entry action to run **again**.

**Recommendation:** Make entry actions idempotent (safe to re-run). For example,
use idempotency keys with external APIs, or check context for existing results
before making the call.
:::

### Machine Design Guidelines

Neither `onDone` nor `onFail` is mandatory — they are machine design choices:

| Pattern | When to use |
|---------|-------------|
| `onDone` + `onFail` | **Recommended.** Fully automated parallel workflows. Machine handles both success and failure paths. |
| `onDone` only | When failures should be handled manually (ops team monitors `failed_jobs`). |
| `onFail` only | Rare. When completion is driven by external events, but failures should auto-recover. |
| Neither | Actor-driven parallelism where external events drive all transitions. |

::: tip
For machine-driven parallelism (the primary use case for parallel dispatch), define **both** `onDone` and `onFail`. Without `onDone`, the machine stays in parallel state forever after all jobs complete. Without `onFail`, the machine stays stuck after a permanent job failure with no automatic recovery path.
:::

## Database Locks
### Why Not Redis?
- ACID guarantees (same DB as machine_events)
- Queryable for debugging (SELECT * FROM machine_locks)
- Self-healing stale locks (expires_at cleanup)
- No additional infrastructure dependency

### Lock Modes
- Immediate (timeout=0): Machine::send() — fails instantly if locked
- Blocking (timeout=30): ParallelRegionJob — waits for sibling jobs
- MachineAlreadyRunningException semantics preserved

### Monitoring Locks
- Query machine_locks table for debugging
- Stale locks auto-cleaned on next acquisition attempt
- Context column shows who holds the lock and why

## Monitoring and Debugging
### Checking Parallel Dispatch Status
- Query machine_events for machine_value (which regions are final)
- Query machine_locks for active locks
- Check Laravel failed_jobs for failed region jobs

### Common Issues
- "Machine already running": another send() is in progress (expected behavior)
- "Lock timeout": a region job took too long in the critical section
- "Region no-op": external event moved machine out of parallel state (safe)
- "@fail fired": a region job failed permanently — check failed_jobs table and @fail payload
- "Machine stuck in parallel": no onDone/onFail defined, or all jobs failed without onFail
```

#### G2. Update: `docs/.vitepress/config.ts`

Add the new page to the sidebar:

```typescript
{
  text: 'Parallel States',
  collapsed: true,
  items: [
    { text: 'Overview', link: '/advanced/parallel-states/' },
    { text: 'Event Handling', link: '/advanced/parallel-states/event-handling' },
    { text: 'Persistence', link: '/advanced/parallel-states/persistence' },
    { text: 'Parallel Dispatch', link: '/advanced/parallel-states/parallel-dispatch' }  // NEW
  ]
}
```

#### G3. Update: `docs/advanced/parallel-states/index.md`

Add to the "Related pages" section at the top:

```markdown
- [Parallel Dispatch](./parallel-dispatch) - Queue-based parallel execution for region entry actions
```

Add a new "Best Practices" item (#7):

```markdown
### 7. Use Parallel Dispatch for Expensive Entry Actions

When region entry actions make external API calls or perform expensive operations,
enable parallel dispatch to run them concurrently:

\`\`\`php ignore
// config/machine.php
'parallel_dispatch' => [
    'enabled' => true,
    'queue'   => 'parallel-regions',
],
\`\`\`

See [Parallel Dispatch](./parallel-dispatch) for full configuration and lifecycle details.
```

#### G4. Update: `docs/advanced/parallel-states/event-handling.md`

Add to the "Entry and Exit Actions" section, after "Entry Action Execution Order":

```markdown
::: tip Parallel Dispatch Changes Entry Action Timing
When [parallel dispatch](./parallel-dispatch) is enabled, region entry actions
run in **queue workers** instead of the HTTP request. The parallel state's own
entry actions still run synchronously. The execution **order** (parallel state →
regions) is preserved, but the **timing** changes: regions run concurrently
instead of sequentially.
:::
```

Add to the "Shared Context" section, after the warning about context conflicts:

```markdown
::: warning Context in Parallel Dispatch
When parallel dispatch is enabled, each region's entry action runs in a separate
queue worker process. Context changes are captured as diffs and merged under a
database lock. To avoid non-deterministic results, each region should write to
**separate context keys**. See [Parallel Dispatch — Context Key Separation](./parallel-dispatch#context-key-separation).
:::
```

#### G5. Update: `docs/advanced/parallel-states/persistence.md`

Add a new section after "Archival Considerations":

```markdown
## Database Locks for Parallel Dispatch

When [parallel dispatch](./parallel-dispatch) is enabled, state updates from
concurrent queue workers are serialized through the `machine_locks` table.
This table must be published and migrated:

\`\`\`bash
php artisan vendor:publish --tag=event-machine-migrations
php artisan migrate
\`\`\`

See [Parallel Dispatch — Database Locks](./parallel-dispatch#database-locks) for details.
```

#### G6. Update: `docs/advanced/raised-events.md`

Add a warning box in the "Event Queue Processing" section:

```markdown
::: warning Raised Events in Parallel Dispatch
When [parallel dispatch](/advanced/parallel-states/parallel-dispatch) is enabled,
the in-memory event queue behaves differently:

- **Region entry actions**: Each queue worker job has its own isolated event queue.
  Raised events are captured locally and processed under a database lock. No events
  are lost.
- **Parallel state's own entry actions**: Run in the HTTP request (same as without
  dispatch). Raised events are processed normally.
- **Constraint**: Parallel state's own entry actions MUST NOT raise events that
  target dispatched regions. The region entry actions haven't run yet — processing
  such events would transition a region before its entry action completes.

Cross-region coordination should use [`@always` guards](/advanced/always-transitions#cross-region-synchronization-in-parallel-states),
not raised events.
:::
```

#### G7. Update: `docs/building/configuration.md`

Add `parallel_dispatch` to the configuration reference table and add a new section:

```markdown
## Parallel Dispatch Configuration

Controls queue-based parallel execution for parallel state regions.
See [Parallel Dispatch](/advanced/parallel-states/parallel-dispatch) for full details.

\`\`\`php ignore
// config/machine.php
'parallel_dispatch' => [
    'enabled'      => env('MACHINE_PARALLEL_DISPATCH_ENABLED', false),
    'queue'        => env('MACHINE_PARALLEL_DISPATCH_QUEUE'),
    'lock_timeout' => env('MACHINE_PARALLEL_DISPATCH_LOCK_TIMEOUT', 30),
    'lock_ttl'     => env('MACHINE_PARALLEL_DISPATCH_LOCK_TTL', 60),
],
\`\`\`

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | bool | `false` | Enable parallel dispatch for parallel state regions |
| `queue` | string\|null | `null` | Queue name for region jobs (null = default queue) |
| `lock_timeout` | int | `30` | Seconds to wait for database lock acquisition |
| `lock_ttl` | int | `60` | Seconds before a lock is considered stale |
```

#### G8. Update: `docs/getting-started/upgrading.md`

Add upgrade notes for the new version:

```markdown
## Upgrading to X.Y.0

### New: Parallel Dispatch (opt-in)

Parallel state region entry actions can now run concurrently via Laravel queue jobs.

**Migration required** (even if you don't enable the feature):
\`\`\`bash
php artisan vendor:publish --tag=event-machine-migrations
php artisan migrate
\`\`\`

This creates the `machine_locks` table used for state update serialization.

**To enable:**
\`\`\`env
MACHINE_PARALLEL_DISPATCH_ENABLED=true
\`\`\`

**No code changes required.** Same actions, guards, events. Only configuration.

See [Parallel Dispatch](/advanced/parallel-states/parallel-dispatch) for full documentation.

### Breaking: Database locks replace Redis locks

`Machine::send()` now uses database-level locks (`machine_locks` table) instead of
Redis `Cache::lock()`. This provides ACID guarantees and better debugging. The
`MachineAlreadyRunningException` behavior is preserved — concurrent `send()` calls
on the same machine still fail immediately.
```

---

## Research Sources

The edge cases in Phase D are derived from the following sources:

| Source | Key Learning |
|--------|-------------|
| **XState parallel.test.ts** (1346 lines) | Simultaneous orthogonal transitions, cross-region targeting, history state interference (#3170), targetless transitions, self-transitions with re-entry, nested parallel done events don't bubble (#2349) |
| **SCXML W3C Conformance Tests** (test370-422, test570) | Done event ordering (region before parallel), done event timing (after onentry), entry/exit ordering (parent before children / children before parent), internal events priority over external |
| **Spring State Machine** (#493) | Actions must complete atomically before transitions — race condition when async actions don't finish before state update |
| **MassTransit Saga** (#3619) | Test harness timeouts with concurrent saga state machines — relevant for our test design |
| **Temporal PHP SDK** | Single-threaded execution model; concurrent signal handlers need mutexes — validates our "lock after action, not during" approach |
