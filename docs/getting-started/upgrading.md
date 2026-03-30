# Upgrading Guide

## Support Policy

Only the **latest major version** receives bug fixes, new features, and security patches. All previous versions are end of life.

| Version | Status |
|---------|--------|
| **9.x** | **Active** — bug fixes, features, security |
| 8.x and below | End of life — upgrade to latest |

**Why only latest?**

EventMachine evolved rapidly from v1 to v7 with a small team. Maintaining multiple branches is not sustainable. More importantly, the upgrade barrier is low: v4 through v7 have **zero breaking changes to machine definitions** — the only breaking changes were PHP/Laravel version requirements (v4) and behavior constructor resolution (v6). A typical multi-version upgrade takes minutes, not days.

::: tip Upgrading from any version
Each section below has step-by-step migration instructions with before/after examples. For multi-version jumps (e.g., v3 → v7), follow each guide in sequence. No data migration is required between any versions — the `machine_events` table format has not changed since v1.
:::

## Version Compatibility

| EventMachine | PHP | Laravel | Status |
|--------------|-----|---------|--------|
| **9.x** | 8.3+ | 11.x, 12.x | **Active** |
| 8.x | 8.3+ | 11.x, 12.x | End of life |
| 7.x | 8.3+ | 11.x, 12.x | End of life |
| 6.x | 8.3+ | 11.x, 12.x | End of life |
| 5.x | 8.3+ | 11.x, 12.x | End of life |
| 4.x | 8.3+ | 11.x, 12.x | End of life |
| 3.x | 8.2+ | 10.x, 11.x, 12.x | End of life |
| 2.x | 8.1+ | 9.x, 10.x | End of life |
| 1.x | 8.0+ | 8.x, 9.x | End of life |

---

## From 8.x to 9.0

### Unified Output — `result`/`contextKeys` → `output`

v9 replaces three separate keywords (`result`, `contextKeys`, `results`) with a single unified `output` keyword. The type of the value determines the behavior:

| Before (v8) | After (v9) | Effect |
|-------------|------------|--------|
| `'result' => MyResult::class` | `'output' => MyOutput::class` | OutputBehavior class computes response |
| `'contextKeys' => ['a', 'b']` | `'output' => ['a', 'b']` | Array filters context keys |
| `'results' => [...]` (behavior array) | `'outputs' => [...]` | Behavior registration key renamed |

### Class Renames

| Before (v8) | After (v9) |
|-------------|------------|
| `ResultBehavior` | `OutputBehavior` |
| `{Name}Result` | `{Name}Output` |

### Method Renames

| Before (v8) | After (v9) |
|-------------|------------|
| `$machine->result()` | `$machine->output()` |
| `assertResult($expected)` | `assertOutput($expected)` |
| `ChildMachineDoneEvent::result()` | Removed — use `output()` only |

### Response Envelope Changes

The HTTP response envelope keys have been renamed for consistency:

```json
// BEFORE (v8)
{
    "data": {
        "machine_id": "01JARX...",
        "value": ["submitted"],
        "context": { "totalAmount": 100 },
        "available_events": [{ "type": "APPROVE", "source": "parent" }]
    }
}
```

```json
// AFTER (v9)
{
    "data": {
        "id": "01JARX...",
        "state": ["submitted"],
        "output": { "totalAmount": 100 },
        "availableEvents": [{ "type": "APPROVE", "source": "parent" }],
        "isProcessing": false
    }
}
```

### Config Key Migration Examples

**State definitions:**

```php ignore
// BEFORE (v8)
'approved' => [
    'type'   => 'final',
    'result' => ApprovalResult::class,
],

// AFTER (v9)
'approved' => [
    'type'   => 'final',
    'output' => ApprovalOutput::class,
],
```

**Endpoint definitions:**

```php ignore
// BEFORE (v8)
'GET_STATUS' => [
    'result'     => OrderStatusResult::class,
    'contextKeys' => ['totalAmount', 'currency'],
],

// AFTER (v9) — class form
'GET_STATUS' => [
    'output' => OrderStatusOutput::class,
],

// AFTER (v9) — array form (replaces contextKeys)
'GET_PRICE' => [
    'output' => ['totalAmount', 'currency'],
],
```

**Behavior arrays:**

```php ignore
// BEFORE (v8)
behavior: [
    'results' => [
        'orderResult' => OrderResult::class,
    ],
],

// AFTER (v9)
behavior: [
    'outputs' => [
        'orderOutput' => OrderOutput::class,
    ],
],
```

**Forward endpoint config:**

```php ignore
// BEFORE (v8)
'forward' => [
    'PROVIDE_CARD' => [
        'result'     => CardSubmittedResult::class,
        'contextKeys' => ['cardLast4'],
    ],
],

// AFTER (v9)
'forward' => [
    'PROVIDE_CARD' => [
        'output' => CardSubmittedOutput::class,
    ],
],
```

### New: State-Level Output (Any State)

In v8, `result` only worked on final states. In v9, `output` works on **any state** — the machine can expose different data depending on its current state:

```php ignore
'states' => [
    'awaiting_vehicle' => [
        'output' => [],                              // metadata only (no context data)
        'on'     => ['SUBMIT_VEHICLE' => 'pricing'],
    ],
    'pricing' => [
        'output' => ['installmentOptions', 'total'], // filtered context
        'on'     => ['SELECT_OPTION' => 'review'],
    ],
    'review' => [
        'output' => CustomerReviewOutput::class,     // computed output
        'on'     => ['SUBMIT' => 'completed'],
    ],
    'completed' => [
        'type'   => 'final',
        'output' => OrderCompletedOutput::class,
    ],
],
```

`$machine->output()` resolves the current state's output with hierarchical fallback:
1. Current atomic state has `output`? → use it
2. Parent compound state has `output`? → use it
3. None → `toResponseArray()` fallback

### New: Output Validation

Defining `output` on invalid states throws `InvalidOutputDefinitionException` at definition time:

- **Transient states** (`@always`) — never observed by consumers
- **Parallel region states** — only the parallel state itself can define output

### New: Consistent Response Envelope

All endpoints now return the same structure — `availableEvents` is never lost:

```json ignore
{
    "data": {
        "id": "01JARX...",
        "machineId": "order_workflow",
        "state": ["submitted"],
        "availableEvents": ["APPROVE", "REJECT"],
        "output": { "totalAmount": 100 },
        "isProcessing": false
    }
}
```

Endpoints without a custom output use the current state's output (or `toResponseArray()` fallback). No need to define `output` on every endpoint — the state determines the response shape.

### New: Graceful Lock Contention Handling

When a machine is processing an event (lock held), HTTP requests to the same machine no longer fail with a 500 error. Instead:

- **GET endpoints** return **HTTP 200** with the last committed state + `isProcessing: true`
- **POST/PUT/DELETE endpoints** return **HTTP 423 Locked** with the last committed state + `isProcessing: true`

The `isProcessing` field is present in **every** endpoint response:
- `false` — normal path, event was processed, state is settled
- `true` — lock contention, returning last committed snapshot

This is especially useful when `BroadcastStateAction` triggers an immediate frontend status check — the GET request now returns the current state instead of crashing.

See [Lock Contention Handling](/laravel-integration/endpoints#lock-contention-handling) for details.

### New: Consistent Behavior Resolution for Outputs

In v8, output behavior resolution was inconsistent across different entry points:
- `Machine::output()` and `resolveChildOutput()` only supported class FQCN — inline keys from the `behavior['outputs']` registry were not resolved, throwing a `BindingResolutionException`
- `MachineController::resolveAndRunOutput()` supported both, but with its own duplicated logic

In v9, all output resolution uses a single unified method (`MachineDefinition::resolveOutputKey()`) with a consistent dispatch order: **FQCN → registry → error**. This is the same order used by `getInvokableBehavior()` for actions, guards, and calculators.

**What this means in practice:**

- Inline output keys now work everywhere — `Machine::output()`, child machine `@done` output, endpoint output, and forwarded endpoint output all resolve inline keys from the `behavior['outputs']` registry
- Invalid output keys now throw `BehaviorNotFoundException` instead of `BindingResolutionException`

**Impact:** If your code catches `BindingResolutionException` from output resolution (unlikely — this only happens with config typos), update the catch to `BehaviorNotFoundException`. No changes needed for valid configurations.

See [Behavior Resolution](/behaviors/introduction#behavior-resolution) for the full dispatch order documentation.

### Migration Checklist

1. Rename all `ResultBehavior` subclasses to extend `OutputBehavior`
2. Rename class files: `{Name}Result` → `{Name}Output`
3. In machine definitions: `'result' =>` → `'output' =>` (states and endpoints)
4. In machine definitions: `'contextKeys' =>` → `'output' => [...]` (array form)
5. In behavior arrays: `'results' =>` → `'outputs' =>`
6. In PHP code: `$machine->result()` → `$machine->output()`
7. In tests: `assertResult()` → `assertOutput()`
8. In tests: `ChildMachineDoneEvent::result()` → `ChildMachineDoneEvent::output()`
9. Update API consumers for new response envelope keys (`id`, `state`, `output`, `availableEvents`)
10. Migrate parameterized behaviors: `'guard:arg1,arg2'` → `[[Guard::class, 'param' => value]]` (optional, deprecated syntax still works)
11. Update listener config: `Class::class => ['queue' => true]` → `[Class::class, '@queue' => true]` (**required**, old format removed)

### New: Named Parameters for Behaviors

Behaviors now accept named parameters via array-tuple syntax. The old `:arg1,arg2` colon syntax is deprecated (removed in v10).

**Before (still works, deprecated):**

```php ignore
'guards' => 'isAmountInRangeGuard:100,10000',

// Behavior receives untyped positional array
public function __invoke(ContextManager $ctx, ?array $arguments = null): bool {
    return $ctx->get('amount') >= (int) $arguments[0]
        && $ctx->get('amount') <= (int) $arguments[1];
}
```

**After:**

```php ignore
'guards' => [[IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000]],

// Behavior receives typed named parameters
public function __invoke(ContextManager $ctx, int $min, int $max): bool {
    return $ctx->get('amount') >= $min
        && $ctx->get('amount') <= $max;
}
```

Works with all behavior keys — guards, actions, calculators, entry/exit, outputs, listeners.

**Output with named params** (inner-array rule, same as guards/actions):

```php ignore
// Parameterized output — inner array
'output' => [[FormatOutput::class, 'format' => 'json']],

// Context key filter — plain array (unchanged)
'output' => ['orderId', 'totalAmount'],
```

**Migration pitfall:** When migrating, update BOTH config AND behavior signature. If only config is changed, old `?array $arguments` gets `null` — silent failure.

### New: Listener Config Format (breaking)

The listener config format has changed. Class-as-key syntax is replaced with tuple syntax. `@`-prefixed keys are framework-reserved (never reach `__invoke`).

**Before (no longer works):**

```php ignore
'listen' => [
    'entry' => [
        SyncAction::class,
        QueuedAction::class => ['queue' => true],
    ],
]
```

**After:**

```php ignore
'listen' => [
    'entry' => [
        SyncAction::class,
        [QueuedAction::class, '@queue' => true],
    ],
]
```

**With named params:**

```php ignore
'listen' => [
    'entry' => [
        [AuditAction::class, 'verbose' => true, '@queue' => true],
    ],
]
```

**Migration steps:**
1. Find all `'listen'` config blocks in your machine definitions.
2. Replace `ClassName::class => ['queue' => true]` with `[ClassName::class, '@queue' => true]`.
3. Sync listeners (numeric key, no options) remain unchanged: `ClassName::class`.

### Exception Specialization (breaking)

v9 replaces generic PHP exceptions (`InvalidArgumentException`, `RuntimeException`) with domain-specific exception classes across the entire codebase. This enables targeted `catch` blocks and clearer error handling.

#### New Exception Classes

| Before (v8) | After (v9) | Thrown From |
|-------------|------------|-------------|
| `InvalidArgumentException` (config validation) | `InvalidStateConfigException` | `StateConfigValidator`, `StateDefinition`, `MachineDefinition` |
| `InvalidArgumentException` (router config) | `InvalidRouterConfigException` | `MachineRouter` |
| `RuntimeException` (no parent machine) | `NoParentMachineException` | `InvokableBehavior` |
| `InvalidArgumentException` / `RuntimeException` (archive) | `ArchiveException` | `MachineEventArchive`, `CompressionManager` |
| `InvalidArgumentException` (machine class) | `InvalidMachineClassException` | `ChildMachineJob`, `SendToMachineJob` |
| `InvalidArgumentException` (job class) | `InvalidJobClassException` | `ChildJobJob` |
| `RuntimeException` (behavior not faked) | `BehaviorNotFakedException` | `Fakeable` trait |
| `RuntimeException` (no search paths) | `MachineDiscoveryException` | `MachineConfigValidatorCommand` |
| `InvalidArgumentException` (timer) | `InvalidTimerDefinitionException` | `Timer` |

#### Renamed Exception Classes

| Before (v8) | After (v9) |
|-------------|------------|
| `NoStateDefinitionFoundException` | `UndefinedTargetStateException` |

#### Deleted Exception Classes

| Before (v8) | After (v9) |
|-------------|------------|
| `InvalidFinalStateDefinitionException` | Merged into `InvalidStateConfigException` (`finalStateCannotHaveTransitions()`, `finalStateCannotHaveChildStates()`) |

#### Extended Exception Classes

| Class | New Factory Methods |
|-------|---------------------|
| `InvalidEndpointDefinitionException` | `forwardConflictsWithEndpoint()`, `forwardConflictsWithBehaviorEvent()`, `duplicateForwardEvent()` |
| `MachineDefinitionNotFoundException` | `failedToLoad()` |

#### Migration Steps

If you catch any of the old generic exceptions for EventMachine errors, update your catch blocks:

<!-- doctest-attr: ignore -->
```php
// BEFORE (v8)
use InvalidArgumentException;

try {
    StateConfigValidator::validate($config);
} catch (InvalidArgumentException $e) {
    // caught ALL InvalidArgumentExceptions, not just config errors
}

// AFTER (v9)
use Tarfinlabs\EventMachine\Exceptions\InvalidStateConfigException;

try {
    StateConfigValidator::validate($config);
} catch (InvalidStateConfigException $e) {
    // catches only config validation errors
}
```

<!-- doctest-attr: ignore -->
```php
// BEFORE (v8)
use RuntimeException;

try {
    $context->sendToParent('CHILD_DONE');
} catch (RuntimeException $e) {
    // caught ALL RuntimeExceptions
}

// AFTER (v9)
use Tarfinlabs\EventMachine\Exceptions\NoParentMachineException;

try {
    $context->sendToParent('CHILD_DONE');
} catch (NoParentMachineException $e) {
    // catches only the "no parent" case
}
```

See [Exceptions Reference](/reference/exceptions) for the full list of all exception classes.

### Migration Checklist (updated)

Items 12–15 are new for the exception specialization:

12. Update `catch (InvalidArgumentException)` blocks that handle EventMachine config errors → specific exception classes (see table above)
13. Update `catch (RuntimeException)` blocks that handle EventMachine runtime errors → specific exception classes
14. Rename `NoStateDefinitionFoundException` → `UndefinedTargetStateException` in any catch blocks or type hints
15. Remove `InvalidFinalStateDefinitionException` imports — now `InvalidStateConfigException`

### Typed Contracts — `with` → `input`, `MachineInput`/`MachineOutput`/`MachineFailure`

v9 introduces typed contracts for delegation boundaries. Machines and jobs can declare what data they expect (input), produce (output), and how their exceptions map to structured errors (failure).

**`with` → `input`:**

| Before (v8) | After (v9) | Effect |
|-------------|------------|--------|
| `'with' => ['orderId', 'amount']` | `'input' => ['orderId', 'amount']` | Untyped key mapping (renamed) |
| `'with' => ['amount' => 'totalAmount']` | `'input' => ['amount' => 'totalAmount']` | Key rename mapping (renamed) |
| N/A | `'input' => PaymentInput::class` | Typed: auto-resolve from parent context |
| N/A | `'input' => fn(ContextManager $ctx) => new PaymentInput(...)` | Typed: closure adapter |

**New machine config keys:**

<!-- doctest-attr: ignore -->
```php
MachineDefinition::define(config: [
    'id'      => 'payment',
    'input'   => PaymentInput::class,    // declares expected input
    'failure' => PaymentFailure::class,  // maps exceptions to typed failures
    'initial' => 'processing',
    'context' => ['paymentId' => null],
]);
```

**Typed output on states via `MachineOutput`:**

<!-- doctest-attr: ignore -->
```php
'completed' => [
    'type'   => 'final',
    'output' => PaymentOutput::class,   // extends MachineOutput — auto-resolved from context
],
```

**Typed output in parent @done/@fail actions:**

<!-- doctest-attr: ignore -->
```php
'@done' => [
    'target'  => 'shipped',
    'actions' => function (ContextManager $ctx, PaymentOutput $output): void {
        $ctx->set('paymentId', $output->paymentId);  // IDE autocomplete
    },
],
```

### Job Interface Renames

| Before (v8) | After (v9) |
|-------------|------------|
| `ReturnsResult` | `ReturnsOutput` |
| `result()` | `output()` |
| `ProvidesFailureContext` | `ProvidesFailure` |
| `failureContext(Throwable): array` | `failure(Throwable): MachineFailure` |

### ForwardContext Removed

`ForwardContext` is removed. Forward endpoint `OutputBehavior` classes now inject child's `MachineOutput` by type-hint instead of accessing raw child internals. Forward endpoints without custom `OutputBehavior` use child's `$machine->output()` directly.

### Machine::fake() Parameter Rename

| Before (v8) | After (v9) |
|-------------|------------|
| `Machine::fake(result: [...])` | `Machine::fake(output: [...])` |
| N/A | `Machine::fake(output: new PaymentOutput(...))` |

### New Base Classes

| Class | Purpose | Factory |
|-------|---------|---------|
| `MachineInput` | Parent → child data contract | `fromContext(ContextManager): static` |
| `MachineOutput` | Child → parent data contract | `fromContext(ContextManager): static` |
| `MachineFailure` | Exception → structured error | `fromException(Throwable): static` |

All three are abstract classes with `readonly` constructor properties and `toArray()` serialization. Subclass them with your domain-specific fields.

### New Exceptions

| Exception | When |
|-----------|------|
| `MachineInputValidationException` | `MachineInput::fromContext()` can't resolve a required constructor param |
| `MachineOutputResolutionException` | `MachineOutput::fromContext()` can't resolve a required constructor param |
| `MachineOutputInjectionException` | Forward endpoint `OutputBehavior` type-hints `MachineOutput` but child state has none |
| `MachineFailureResolutionException` | `MachineFailure::fromException()` can't resolve a required constructor param |

### Migration Checklist (typed contracts)

1. Rename `'with' =>` to `'input' =>` in all delegation configs (array format works as-is)
2. Rename `ReturnsResult` → `ReturnsOutput`, `result()` → `output()` in job actors
3. Rename `ProvidesFailureContext` → `ProvidesFailure`, `failureContext()` → `failure()` — return type changes from `array` to `MachineFailure`
4. Replace `ForwardContext` type-hints in forward endpoint `OutputBehavior` classes with child's `MachineOutput` type-hint
5. Optionally: define `MachineInput`/`MachineOutput`/`MachineFailure` subclasses for typed delegation contracts
6. Optionally: add `'input' => MyInput::class` and `'failure' => MyFailure::class` to machine configs
7. Optionally: replace array `'output'` on states with `MachineOutput` subclasses
8. Run `composer quality`

---

## From 7.x to 8.0

v8 is about **event preservation** and **testing maturity**. The single breaking change aligns `@always` transition behavior with XState v5 and the W3C SCXML spec. The rest of the release series adds endpoint filtering, EventBuilder, bulk faking, computed context, auto-generated event types, and numerous bug fixes for parallel state + delegation interactions.

### 8.0.0 — Event Preservation Through @always

**Breaking change:** Behaviors (actions, guards, calculators) on `@always` transitions now receive the **original triggering event** instead of the synthetic `@always` event.

| Aspect | v7 | v8 |
|--------|----|---|
| `$event->type` in @always behavior | `'@always'` | Original event type |
| `$event->payload` in @always behavior | `null` | Original event payload |
| `$event->actor()` in @always behavior | Derived from context | Derived from original event |

**Who is affected?** Only if your behaviors on `@always` transitions check `$event->type === '@always'` or rely on `$event->payload` being `null`. This is uncommon — most `@always` behaviors use only `ContextManager` and ignore the event.

**Before (v7):**

<!-- doctest-attr: ignore -->
```php
// Action on @always transition
class MyAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $event->type;    // '@always'
        $event->payload; // null — payload lost!
    }
}
```

**After (v8):**

<!-- doctest-attr: ignore -->
```php
// Same action, same @always transition — now receives the real event
class MyAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        $event->type;    // 'ORDER_SUBMITTED' (the original event)
        $event->payload; // ['tckn' => '123...'] (preserved!)
    }
}
```

**Migration steps:**

1. Update `composer.json`: `"tarfin-labs/event-machine": "^8.0"`
2. Search for behaviors on `@always` transitions that use `EventBehavior` — if they check `$event->type === '@always'`, remove the check; if they rely on `$event->payload` being `null`, update to handle the real payload
3. Run your tests

**New feature: Raise actor auto-propagation** — Raised events automatically inherit actor from the triggering event when not explicitly set:

<!-- doctest-attr: ignore -->
```php
// Before (v7) — manual boilerplate
$this->raise(new ApprovedEvent(
    payload: $data,
    actor: $event->actor($context),
));

// After (v8) — auto-inherited
$this->raise(new ApprovedEvent(
    payload: $data,
));
```

**New feature: Endpoint filtering (`only`/`except`)** — `MachineRouter::register()` accepts `only` and `except` to split endpoints across middleware groups:

<!-- doctest-attr: ignore -->
```php
MachineRouter::register(CarSalesMachine::class, [
    'prefix' => 'car-sales',
    'only'   => [ConsentGrantedEvent::class, PersonalInfoSubmittedEvent::class],
    'name'   => 'car-sales.public',
]);
```

**Stricter validation: `machineIdFor`/`modelFor`** — Router now validates that referenced event types exist in the registered endpoint set. Previously silently ignored.

### 8.1.0 — EventBuilder + HasBuilder

Purpose-built test data builders for complex event payloads:

<!-- doctest-attr: ignore -->
```php
OrderSubmittedEvent::builder()
    ->withOrderItems(3)
    ->withFarmerPaymentDate()
    ->make();
```

- `EventBuilder` abstract base class with `::new()`, `state()`, `make()`, `raw()`
- `HasBuilder` trait adds `Event::builder()` to event classes (like Laravel's `HasFactory`)

### 8.2.0 — Endpoint Filtering

`only`/`except` options on `MachineRouter::register()` for splitting endpoints across route groups. See 8.0.0 above.

### 8.2.1 — Machine Delegation Fix in Parallel Regions

Fixed child machines configured via the `machine:` key never being invoked in 7 different state entry paths — most critically parallel region initial states. Centralized entry protocol into `enterState()` and `enterStateInParallelRegion()` (-113 lines).

### 8.2.2 — Parallel + Delegation Follow-Up

Three additional fixes: forward events not routed in parallel state, event history snapshots corrupted in parallel context, and `ChildMachineCompletionJob` silently skipped in parallel context.

### 8.2.3 — Job Actor Dependency Injection

Fixed `ChildJobJob` bypassing Laravel's service container — job actors with type-hinted `handle()` parameters now resolve correctly via `app()->call()`.

### 8.2.4 — Event Queue After Child @done/@fail/@timeout

Fixed raised events and `@always` transitions not being processed after child completion transitions.

### 8.3.0 — simulateChildDone/Fail/Timeout for Job Actors

`simulateChildDone()`, `simulateChildFail()`, and `simulateChildTimeout()` now work with both `machine` and `job` delegation.

### 8.4.0 — Bulk Faking and startingAt()

Three testing DX improvements:

- **`fakingAllActions()`/`fakingAllGuards()`/`fakingAllBehaviors()`** — fake all class-based behaviors in one call with `except:` parameter
- **`guards:` parameter** on `withContext()`/`create()` — set guard fakes before machine initialization
- **`startingAt()`** — create machine at any state without running lifecycle

<!-- doctest-attr: ignore -->
```php
OrderMachine::startingAt('processing', context: ['orderId' => 1])
    ->fakingAllActions(except: [CriticalAction::class])
    ->send('COMPLETE')
    ->assertState('completed');
```

### 8.4.1 — Pre-Init Action Faking

Added `faking:` parameter to `withContext()`, `create()`, and `startingAt()` for spying actions before machine initialization.

### 8.4.2 — startingAt() Timer Support

Fixed `startingAt()` not calling `trackStateEntry()`, which caused `advanceTimers()` to silently do nothing.

### 8.5.0 — Testing Entry Point Simplification

`Machine::test()` and `Machine::startingAt()` are now the **only** entry points for class-based machine testing:

| Before | After |
|--------|-------|
| `TestMachine::create(MyMachine::class)` | `MyMachine::test()` |
| `TestMachine::withContext(MyMachine::class, [...])` | `MyMachine::test(context: [...])` |
| `TestMachine::startingAt(MyMachine::class, 'state', [...])` | `MyMachine::startingAt('state', context: [...])` |

**Behavior change:** `Machine::test(context: [...])` now merges context **before** initialization — entry actions see injected values.

Also added `assertNotDispatchedTo()` and two new documentation pages (Real Infrastructure Testing, Testing Troubleshooting).

### 8.5.1 — raise() After Compound/Parallel @done

Fixed `raise()` and `@always` not processed after `processCompoundOnDone()`, `processNestedParallelCompletion()`, and `exitParallelStateAndTransitionToTarget()`.

### 8.5.2 — Fire-and-Forget Post-Entry Fix

Extended the `processPostEntryTransitions` fix to fire-and-forget code paths in `handleJobInvoke()`, `handleAsyncMachineInvoke()`, and `handleFakedMachineInvoke()`.

### 8.5.3 — Centralize processPostEntryTransitions

**Architectural fix eliminating an entire bug class.** `enterState()` now internally calls `processPostEntryTransitions()` — callers no longer need to remember to call it. The same "forgot to call processPostEntryTransitions()" bug appeared 7 times across 8.2.4, 8.5.1, 8.5.2, and 8.5.3. Now impossible.

Also added `assertRaised()`/`assertNotRaised()`/`assertRaisedCount()`/`assertNothingRaised()` for isolated action testing.

::: warning If you subclass MachineDefinition
If you call `processPostEntryTransitions()` directly, remove those calls. `enterState()` handles it automatically via the `processPostEntry` parameter (default `true`).
:::

### 8.5.4 — ResultBehavior Event Fix

Fixed `ResultBehavior` receiving internal event data (NULL payload) instead of the original triggering event. `Machine::result()` and `MachineController::resolveAndRunResult()` now use `$state->triggeringEvent`.

### 8.6.0 — Computed Context in API Responses

Custom context classes can expose computed values in endpoint responses via `computedContext()`:

<!-- doctest-attr: ignore -->
```php
class OrderContext extends ContextManager
{
    public function __construct(
        public array $items = [],
        public float $total = 0.0,
    ) {
        parent::__construct();
    }

    protected function computedContext(): array
    {
        return [
            'itemCount' => count($this->items),
            'isEmpty'   => empty($this->items),
        ];
    }
}
```

Computed values appear in endpoint responses and `State::toArray()` but are **not persisted** to the database. Existing context classes without `computedContext()` are unaffected.

### 8.6.1 — ValidationGuardBehavior in Parallel States

Fixed `ValidationGuardBehavior` failure inside parallel state regions throwing `NoTransitionDefinitionFoundException` instead of returning a 422 validation error.

### 8.6.2 — Concurrent State Mutation Protection

Major QA infrastructure overhaul with 16 new real-Horizon tests and 5 concurrency bug fixes:

- **Always-on lock for async queues** — `Machine::send()` acquires a lock for all persisted machines when the queue driver is async
- **Deep delegation chain propagation** — `ChildMachineCompletionJob` propagates completion through multi-level chains (Parent → Child → Grandchild)
- **`SendToMachineJob` retry** — catches lock contention and uses `release(1)` for graceful retry
- **`ListenerJob` lock protection** — concurrent listeners no longer overwrite each other's context
- **`ChildMachineJob` duplicate prevention** — `lockForUpdate()` on tracking record

### 8.6.3 — SCXML Compliance and Test Hardening

75+ new test files from analysis of 12 state machine implementations and 210 W3C SCXML IRP tests. Four bug fixes:

- **Action ordering** corrected to `exit → transition → entry` (was `transition → exit → entry`)
- **Targetless transitions** no longer fire exit/entry actions (internal transition semantics)
- **Guard context mutation leak** prevented via snapshot/restore
- **Raised events processed before delegation** (SCXML invoker-05)

Also added cross-region transition rejection validation.

### 8.6.4 — machineId() After Restore

Fixed `$context->machineId()` returning `null` after state restore. `restoreStateFromRootEventId()` now calls `setMachineIdentity()` on every restore.

### 8.7.0 — Auto-Generated Event Behavior Types

`EventBehavior::getType()` is no longer abstract — it auto-derives the event type from the class name:

<!-- doctest-attr: ignore -->
```php
// Before — boilerplate
class OrderSubmittedEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'ORDER_SUBMITTED';
    }
}

// After — auto-generated from class name
class OrderSubmittedEvent extends EventBehavior
{
    // getType() returns 'ORDER_SUBMITTED' automatically
}
```

Existing `getType()` overrides continue to work. You can optionally remove them when the return value matches the convention.

### 8.7.1 — GET Endpoint Query Parameter Validation

Fixed GET endpoint query parameters silently bypassing `EventBehavior` validation. `MachineController::resolveRequestData()` now wraps GET query params into the `payload` key.

### 8.7.2 — Parallel State Guard Failure

Fixed regular `GuardBehavior` failure in parallel states throwing `NoTransitionDefinitionFoundException`. Now correctly records `TRANSITION_FAIL` and stays in the current state (matching non-parallel behavior).

### 8.7.3 — Parallel Internal Event Naming

Fixed exit/entry events in parallel region internal transitions using the parallel ancestor's route instead of the actual atomic state's route.

### Migration Checklist (v7 → v8)

1. Update `composer.json`: `"tarfin-labs/event-machine": "^8.0"`
2. Search for behaviors on `@always` transitions that check `$event->type === '@always'` or rely on null payload — update them
3. Run `composer quality`

---

## From 6.x to 7.0

v7 is the **actor model release** — machines can delegate to child machines, communicate across instances, react to time, and run on schedules. This is a major feature release with **no breaking changes to existing machines**. All existing code continues to work unchanged.

### 7.0.0 — State Machines That Compose

**New feature: Machine delegation** — States can launch child machines synchronously or asynchronously:

<!-- doctest-attr: ignore -->
```php
'processing_payment' => [
    'machine' => PaymentMachine::class,
    'with'    => ['orderId', 'totalAmount'],
    '@done'   => 'shipping',
    '@fail'   => 'payment_failed',
    '@timeout' => ['after' => 300, 'target' => 'payment_timed_out'],
    'queue'    => 'payments',
],
```

**New feature: Cross-machine communication** — Five methods for inter-machine messaging:

| Method | Direction | Mode |
|--------|-----------|------|
| `sendTo()` | → Any machine | Sync |
| `dispatchTo()` | → Any machine | Async |
| `sendToParent()` | → Parent | Sync |
| `dispatchToParent()` | → Parent | Async |
| `raise()` | → Self | Sync |

**New feature: Time-based events** — `after` (one-shot) and `every` (recurring) timers:

<!-- doctest-attr: ignore -->
```php
'awaiting_payment' => [
    'on' => [
        'PAY'           => 'processing',
        'ORDER_EXPIRED' => ['target' => 'cancelled', 'after' => Timer::days(7)],
        'REMINDER'      => ['actions' => 'sendReminderAction', 'every' => Timer::days(1)],
    ],
],
```

**New feature: Scheduled events** — Cron-based batch operations:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Scheduling\MachineScheduler;

MachineScheduler::register(ApplicationMachine::class, 'CHECK_EXPIRY')
    ->dailyAt('00:10')
    ->onOneServer();
```

**New feature: Machine faking** — Short-circuit child machines in tests:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;

PaymentMachine::fake(result: ['paymentId' => 'pay_123']);

$machine = OrderWorkflowMachine::create();
$machine->send(['type' => 'START']);

PaymentMachine::assertInvoked();
PaymentMachine::assertInvokedWith(['orderId' => 'ORD-1']);

Machine::resetMachineFakes();
```

**New feature: Machine identity** — `$context->machineId()` and `$context->parentMachineId()`

**New feature: Infinite loop protection** — Configurable `max_transition_depth` (default 100)

**New database tables** — Three new tables required:

| Table | Purpose |
|-------|---------|
| `machine_children` | Async child machine tracking |
| `machine_current_states` | Current state per instance (timers, schedules) |
| `machine_timer_fires` | Timer dedup and recurring fire tracking |

**New artisan commands:**

| Command | Purpose |
|---------|---------|
| `machine:process-timers` | Sweep timer events (auto-registered) |
| `machine:process-scheduled` | Process scheduled events |
| `machine:timer-status` | Display timer status |
| `machine:cache` | Cache machine discovery for production |
| `machine:clear` | Clear machine discovery cache |

**Migration steps:**

```bash
composer require tarfinlabs/event-machine:^7.0
php artisan vendor:publish --tag=machine-migrations
php artisan migrate
```

### 7.1.0 — Fire-and-Forget Machine Delegation

States can spawn child machines in the background without tracking lifecycle. Omit `@done` on a `machine` + `queue` state:

<!-- doctest-attr: ignore -->
```php
'prevented' => [
    'machine' => TurmobVerificationMachine::class,
    'with'    => ['tckn'],
    'queue'   => 'verifications',
    // No @done → fire-and-forget
    'on' => ['RETRY' => 'retrying'],
],
```

Three patterns: stay in state, spawn and move on (with `@always`), spawn and move on (with `target`).

### 7.2.0 — Forward-Aware Endpoints

Forward events are now auto-discovered from child machine definitions — no duplicate declarations needed.

::: warning Breaking Change
Forward events that also appear in parent's `endpoints` or `behavior.events` are now **rejected at parse time**. Remove forwarded events from `behavior.events` and `endpoints` — the `forward` key is the single source of truth.
:::

**Before:**

```php no_run
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class PaymentFlowMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'payment_flow',
                'initial' => 'collecting',
                'context' => ['orderId' => null],
                'states'  => [
                    'collecting' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => PaymentChildMachine::class,
                        'queue'   => 'payments',
                        'forward' => ['PROVIDE_CARD'],
                        '@done'   => 'completed',
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START'        => StartEvent::class,
                    'PROVIDE_CARD' => ProvideCardEvent::class, // REMOVE
                ],
            ],
        );
    }
}
```

**After:**

```php no_run
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class PaymentFlowMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'payment_flow',
                'initial' => 'collecting',
                'context' => ['orderId' => null],
                'states'  => [
                    'collecting' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => PaymentChildMachine::class,
                        'queue'   => 'payments',
                        'forward' => ['PROVIDE_CARD'],
                        '@done'   => 'completed',
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START' => StartEvent::class,
                    // PROVIDE_CARD removed — child owns it
                ],
            ],
        );
    }
}
```

Also added `available_events` introspection, `ForwardContext` injection, and 5 TestMachine assertion methods.

### 7.3.0 — @done.{state} Per-Final-State Routing

Route parent based on which final state the child reached:

<!-- doctest-attr: ignore -->
```php
'verifying' => [
    'machine' => VerificationMachine::class,
    '@done.approved' => 'processing',
    '@done.rejected' => 'declined',
    '@done.expired'  => 'timed_out',
    '@fail' => 'system_error',
],
```

### 7.4.0 — TestMachine v2 API

15 new fluent test methods for child delegation, async simulation, and cross-machine communication:

<!-- doctest-attr: ignore -->
```php
OrderMachine::test()
    ->fakingChild(PaymentMachine::class, result: ['id' => 'pay_1'], finalState: 'approved')
    ->send('PLACE_ORDER')
    ->assertState('completed')
    ->assertChildInvoked(PaymentMachine::class)
    ->assertRoutedViaDoneState('approved');
```

### 7.4.1

- Fixed TestMachine assertions using Pest-only `expect()` — now uses `PHPUnit\Framework\Assert` for compatibility with both Pest and PHPUnit

### 7.5.0 — Fakeable Machine::create()

`Machine::fake()` now intercepts `Machine::create()` for controller test isolation:

<!-- doctest-attr: ignore -->
```php
CarSalesMachine::fake();
$this->postJson("/consent/{$hash}/approve")->assertOk();
CarSalesMachine::assertCreated();
CarSalesMachine::assertSent('CONSENT_GRANTED');
```

Also added `InteractsWithMachines` trait for automatic test cleanup.

### 7.6.0 — In-Memory Timer Testing

`advanceTimers()` now works without database persistence. Also added `ChildMachineDoneEvent::forTesting()` and `ChildMachineFailEvent::forTesting()` factories.

### 7.6.1

- Fixed `CarbonInterface` type-hints — uses `CarbonInterface` instead of `Carbon` for `now()` compatibility

### 7.6.2

- Fixed targetless transitions accepting `''` and `[]` in addition to `null`

### 7.7.0 — Exception Metadata in @fail Handlers

`ProvidesFailureContext` contract for structured error data in `@fail` guards:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Contracts\ProvidesFailureContext;

class ConfirmFindeksPinJob implements ProvidesFailureContext
{
    public static function failureContext(\Throwable $exception): array
    {
        if ($exception instanceof FindeksException) {
            return [
                'errorCode' => $exception->getFindeksErrorCode(),
                'retryable' => $exception->isRetryable(),
            ];
        }
        return ['errorCode' => 'UNKNOWN'];
    }
}
```

### 7.7.1

- Documentation updates for `ProvidesFailureContext`

### 7.8.0 — Machine-Level Entry & Exit Actions

Root-level `entry`/`exit` actions now execute (previously parsed but never run):

<!-- doctest-attr: ignore -->
```php
MachineDefinition::define(
    config: [
        'id'      => 'order',
        'initial' => 'pending',
        'entry'   => 'initializeTrackingAction',  // runs once on start
        'exit'    => 'finalCleanupAction',         // runs once on final state
        'states'  => [...],
    ],
);
```

### 7.9.0 — State Change Listeners

Cross-cutting actions on every state change via `listen` config:

<!-- doctest-attr: ignore -->
```php
'listen' => [
    'entry'      => BroadcastStateAction::class,
    'exit'       => AuditLogAction::class,
    'transition' => FullAuditTrailAction::class,
],
```

Supports sync and queued (`['queue' => true]`) listeners. Transient states with `@always` are automatically skipped.

### 7.9.1

- Test coverage and documentation for listener + child delegation isolation

### 7.9.2 — Machine::result() Parameter Injection

Fixed `Machine::result()` using positional arguments instead of type-hint based parameter injection.

### Migration Steps (v6 → v7)

1. `composer require tarfinlabs/event-machine:^7.0`
2. `php artisan vendor:publish --tag=machine-migrations && php artisan migrate`
3. Start using new features when ready — no existing code needs to change

---

## From 5.x to 6.0

v6 introduces a comprehensive **testability layer** that makes state machine testing a first-class citizen. Three breaking changes to behavior resolution — most applications require **no code changes**.

### 6.0.0 — Testability Layer

**Breaking change 1: Behavior resolution via container** — Behaviors are now resolved through `App::make()` instead of `new $class()`. This enables constructor dependency injection.

**Before:**
<!-- doctest-attr: ignore -->
```php
// MachineDefinition::getInvokableBehavior()
return new $behaviorDefinition($this->eventQueue);
```

**After:**
<!-- doctest-attr: ignore -->
```php
return App::make($behaviorDefinition, ['eventQueue' => $this->eventQueue]);
```

**Action required only if** you override `InvokableBehavior::__construct()` with non-injectable parameters (plain `string`, `int`, `array` without defaults). Register a container binding:

<!-- doctest-attr: ignore -->
```php
$this->app->when(MyBehavior::class)->needs('$prefix')->give('my_prefix');
```

**Breaking change 2:** `InvokableBehavior::run()` always uses container (was `new static()` for non-faked behaviors).

**Breaking change 3:** `Fakeable::fake()` uses `App::bind()` with Closure. `resetFakes()` now uses `app()->offsetUnset()`.

**New testing features:**

| Feature | Description |
|---------|-------------|
| `Machine::test()` | Fluent test wrapper with 21+ assertion methods |
| `State::forTesting()` | Lightweight state factory for unit tests |
| `runWithState()` | Isolated testing with engine-identical DI |
| `EventBehavior::forTesting()` | Test factory for event construction |
| Constructor DI | Behaviors can inject service dependencies |
| `spy()`, `allowToRun()`, `mayReturn()` | Enhanced fakeable API |

**Migration steps:**

1. `composer require tarfinlabs/event-machine:^6.0`
2. Search for behaviors overriding `__construct()` with non-injectable parameters — register bindings
3. Ensure `resetAllFakes()` is called in `afterEach` for test fake cleanup

### 6.1.0 — HTTP Endpoints

Declarative endpoint layer — define endpoints in machine config, `MachineRouter` generates Laravel routes:

<!-- doctest-attr: ignore -->
```php
MachineRouter::register(OrderMachine::class, [
    'prefix'       => 'orders',
    'model'        => Order::class,
    'attribute'    => 'machine',
    'create'       => true,
    'machineIdFor' => ['CANCEL'],
]);
```

Four routing patterns: stateless, machineId-bound, model-bound, and hybrid. `State` now implements `JsonSerializable`.

### 6.2.0 — XState Export & Stately Studio Integration

New `machine:xstate` command replaces the old PlantUML generator:

```bash
php artisan machine:xstate "App\Machines\OrderMachine" --stdout
php artisan machine:xstate "App\Machines\OrderMachine" --format=js
```

Exports states, transitions, guards, actions, calculators, parallel/final states, context, and event payload schemas.

### 6.3.0 — Inline Behavior Faking

Inline closure behaviors can now be faked during tests:

<!-- doctest-attr: ignore -->
```php
OrderMachine::test()
    ->faking(['hasItemsGuard' => false])
    ->assertGuarded('SUBMIT');
```

`InlineBehaviorFake` intercepts closures at their invocation site in the engine.

### 6.4.0 — Explicit Model Routing & Endpoint DX

::: warning Breaking Change
Model-bound routing is no longer implicit. You must declare which events use model binding via `modelFor`:
:::

<!-- doctest-attr: ignore -->
```php
// Before (v6.1–v6.3): implicit
MachineRouter::register(OrderMachine::class, [
    'model' => Order::class,
]);

// After (v6.4): explicit
MachineRouter::register(OrderMachine::class, [
    'model'    => Order::class,
    'modelFor' => ['SUBMIT', 'APPROVE'],
]);
```

Also added list syntax for endpoints, `_EVENT` suffix auto-stripping in URIs, and event class keys in router options.

### Migration Checklist (v5 → v6)

1. `composer require tarfinlabs/event-machine:^6.0`
2. Check custom `__construct()` overrides on behaviors — register bindings for non-injectable parameters
3. Add `resetAllFakes()` to test cleanup
4. Optionally adopt `Machine::test()` fluent API

---

## From 4.x to 5.0

v5 brings **true parallel execution** — region entry actions run as concurrent Laravel queue jobs across multiple workers.

### 5.0.0 — True Parallel Dispatch

Opt-in concurrent execution via `ParallelRegionJob` queue jobs. Disabled by default — existing parallel state machines work unchanged.

```php ignore
// config/machine.php
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

**Region timeout** — configurable watchdog for stuck parallel states. Seven new internal events for observability (`PARALLEL_REGION_ENTER`, `PARALLEL_CONTEXT_CONFLICT`, `PARALLEL_DONE`, etc.).

**New `machine_locks` table** — database-based locking for parallel dispatch.

**Migration steps:**

```bash
composer update tarfinlabs/event-machine:^5.0
php artisan vendor:publish --tag=machine-migrations
php artisan migrate
```

### 5.1.0 — Conditional @done/@fail with Guards

`@done` and `@fail` transitions now support conditional branches:

<!-- doctest-attr: ignore -->
```php
'@done' => [
    ['target' => 'approved',      'guards' => IsAllSucceededGuard::class],
    ['target' => 'manual_review'],  // fallback
],
```

### 5.1.1

- Fixed root-level `on` events not working during parallel state (parallel escape transitions)
- Fixed `selectTransitions` deduplication for ancestor-level handlers

### 5.1.2

- Fixed targetless `@done`/`@fail` branch actions being silently skipped
- Fixed nested parallel exit actions not running on `@done`
- Fixed region ID prefix collision (`region_a` matching `region_ab`)
- Fixed missing `TRANSITION_START`/`TRANSITION_FINISH` events for parallel @done/@fail

---

## From 3.x to 4.0

v4 adds **parallel states** — multiple concurrent regions with full lifecycle management.

### 4.0.0 — Parallel States

**Breaking changes:**
- **Dropped PHP 8.2 support** — requires PHP 8.3+ (Pest v4 dependency)
- **Dropped Laravel 10 support** — requires Laravel 11+
- **Dropped Orchestra Testbench ^8.x** — requires ^9.0+

**New features:**
- **Parallel states** — `'type' => 'parallel'` with multiple concurrent regions
- **`onDone` auto-transitions** — fire when all regions reach final states
- **Compound state `onDone`** — XState-compatible `onDone` for compound states within parallel regions
- **Multi-value state support** — `matches()`, `matchesAll()`, `isInParallelState()`
- **DocTest integration** — documentation code blocks tested automatically

**Migration steps:**

1. Upgrade to PHP 8.3+ and Laravel 11+
2. `composer require tarfinlabs/event-machine:^4.0`
3. Review any custom `StateConfigValidator` usage — parallel state validation now uses `InvalidParallelStateDefinitionException`

### 4.0.1

- Fixed `@always` guard exception in parallel states — machine now correctly stays in current state when guard evaluates to `false`

### 4.0.2

- Fixed `areAllRegionsFinal()` nested final detection — only direct children of a parallel region count as region-final
- Added compound state `onDone` support with recursive chaining

---

## From 2.x to 3.0

v3 introduces **parameter injection by type-hint**, **custom context classes**, **calculators**, and the **event archival system**.

### 3.0.0 — Type-Hinted Behaviors and Event Archival

**Breaking change 1: Behavior parameter injection** — Parameters are now injected based on type hints, not position.

**Before (v2.x):**
```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
class MyAction extends ActionBehavior
{
    public function __invoke($context, $event): void
    {
        // Parameters were positional
    }
}
```

**After (v3.x):**
```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
use Tarfinlabs\EventMachine\Behavior\EventBehavior; // [!code hide]
class MyAction extends ActionBehavior
{
    public function __invoke(ContextManager $context, EventBehavior $event): void
    {
        // Type-hinted parameters are injected
    }
}
```

**Breaking change 2: ContextManager access** — Direct array access deprecated.

**Before (v2.x):**
<!-- doctest-attr: ignore -->
```php
$context->data['key'] = 'value';
```

**After (v3.x):**
<!-- doctest-attr: ignore -->
```php
$context->set('key', 'value');
// or
$context->key = 'value';
```

**Breaking change 3: State matching** — Use `matches()` instead of direct comparison.

**Before (v2.x):**
<!-- doctest-attr: ignore -->
```php
$machine->state->value === 'pending';
```

**After (v3.x):**
<!-- doctest-attr: ignore -->
```php
$machine->state->matches('pending');
```

**Breaking change 4: PHP 8.2+ required** (upgraded from 8.1).

**New features:**
- **Calculators** — New behavior type that runs before guards for context pre-computation
- **Event class keys** — Use event classes directly as transition keys (`SubmitEvent::class => [...]`)
- **Custom context classes** — `ContextManager` subclasses with typed properties and validation
- **Event archival** — `ArchiveService` with compression, fan-out processing, and auto-restore
- **Config validator command** — `php artisan machine:validate`
- **PHPStan level 5** compliance

**Migration steps:**

1. `composer require tarfinlabs/event-machine:^3.0`
2. `php artisan migrate` (new archive tables)
3. Update all behavior `__invoke()` signatures to use type hints
4. Replace `$context->data['key']` with `$context->get('key')` or `$context->key`
5. Replace `$state->value === 'state'` with `$state->matches('state')`
6. Move context modifications from guards to calculators

### 3.0.1

- Fixed slow archival queries on large tables (57GB+) — replaced `NOT EXISTS` subquery with `GROUP BY + HAVING` pattern (400+ seconds → ~100ms)

### 3.0.2

- Fixed config value type casting in `ArchiveService` — `level`, `days_inactive`, `restore_cooldown_hours` now properly cast to `int`

---

## From 1.x to 2.0

v2 introduces **calculator behaviors**, **inline behavior testing**, **static context validation**, **reset-all-fakes**, and **machine config validation**.

### 2.0.0 — Calculators and Config Validation

**Breaking change: State value format** — State values are now arrays containing the full path.

**Before (v1.x):**
<!-- doctest-attr: ignore -->
```php
$machine->state->value; // 'pending'
```

**After (v2.x):**
<!-- doctest-attr: ignore -->
```php
$machine->state->value; // ['machine.pending']
```

**Breaking change: Machine creation** — Use the static `create()` method.

**Before (v1.x):**
<!-- doctest-attr: ignore -->
```php
$machine = new OrderMachine();
$machine->start();
```

**After (v2.x):**
<!-- doctest-attr: ignore -->
```php
$machine = OrderMachine::create();
```

**Breaking change: Event sending** — Events use array format.

**Before (v1.x):**
<!-- doctest-attr: ignore -->
```php
$machine->dispatch('SUBMIT', ['key' => 'value']);
```

**After (v2.x):**
<!-- doctest-attr: ignore -->
```php
$machine->send([
    'type' => 'SUBMIT',
    'payload' => ['key' => 'value'],
]);
```

**New features:**
- **Calculator behaviors** — Pre-compute values before guards
- **Inline behavior testing** — Test inline closures from machine definitions
- **Static context validation** — Context validation methods converted to static
- **Reset all fakes** — `resetAllFakes()` for test cleanup
- **Config validation** — `StateConfigValidator` for definition-time checks

### 2.0.1

- Added support for status events (`@done`, `@fail`) in root-level config keys

### 2.1.0

- Added `machine:validate` artisan command
- Added tests for calculator execution in guarded transitions
- Added Laravel 12.x compatibility

### 2.1.1

- Fixed `Fakeable` trait issue with mock registration

### 2.1.2

- Added `InteractsWithInput` trait for `EventBehavior`

---

## 1.x — Initial Release Series

The foundation of EventMachine — event-driven state machines for Laravel with persistence, behaviors, and guards.

### 1.0.0 — First Release

The initial release of EventMachine, providing core state machine functionality:
- Machine definitions with states and transitions
- Action, guard, and event behaviors
- Event persistence via `machine_events` table
- State restoration from event history

### 1.0.1

- Fixed scenario bugs in state machine execution

### 1.1.0

- Removed guard start events from event history (noise reduction)

### 1.2.0

- **Incremental context storage** — reduced `machine_events` context field size by storing only changes
- **Behavior dependency injection** — behaviors receive injected parameters
- **Configurable persistence** — `should_persist` option to disable logging for non-critical machines
- **Mockable actions** — actions can be mocked in tests
- **`stopOnFirstFailure`** — validation guard improvement
- **State diagram generation** — automatic state machine diagram creation

### 1.3.0

- Added Laravel 11 support

### 1.4.0

- Fixed `MachineCast` `set` method to handle uninitialized machines

### 1.5.0

- Improved type resolution in `InvokableBehavior` for parameter injection

### 1.6.0

- Added `machines()` method on Eloquent models via `HasMachines` trait — set machines on a model without individual casts

### 1.7.0

- Added `Fakeable` trait for invokable behaviors — `fake()`, `spy()`, `shouldReturn()`

---

## Getting Help

If you encounter issues during upgrade:

1. Check the [GitHub Issues](https://github.com/tarfinlabs/event-machine/issues)
2. Review the [Release Notes](https://github.com/tarfinlabs/event-machine/releases)
3. Open a new issue with your upgrade scenario
