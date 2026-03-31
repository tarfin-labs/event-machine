# Graceful Lock Contention for HTTP Endpoints

**Status:** Draft
**Date:** 2026-03-29

---

## Problem

When a machine is processing an event (lock held), any HTTP request to the same machine fails with `MachineAlreadyRunningException`. This is a race condition that occurs consistently in real-world usage.

### Timeline

```
T1: POST /payment-options-selected → machine acquires lock
T2: Machine transitions stateA → stateB
T3: Entry action: BroadcastStateAction (ShouldDispatchAfterCommit)
T4: persist() → DB COMMIT → broadcast dispatched → frontend receives via WebSocket
T5: Frontend calls GET /status
T6: GET /status → $machine->send() → lock acquisition fails → 500 error
T7: handleValidationGuards() + finally block → lock released
```

The broadcast fires after `persist()` (DB commit) but before the lock is released in the `finally` block. The state IS in the database, but the lock is still held.

### Why This Is Common

In the CarSalesMachine:
- `BroadcastStateAction` runs on every state entry (`listen.entry`)
- Frontend has an Echo listener that calls `fetchStatus()` on every broadcast
- With fast WebSocket delivery (Reverb), the race window is wide enough to hit consistently

### Current Behavior

`$machine->send()` acquires the lock with `timeout=0` (non-blocking). On failure:

```php
} catch (MachineLockTimeoutException) {
    throw MachineAlreadyRunningException::build($rootEventId);
}
```

This throws an unhandled exception → 500 error. No state data, no context, no recovery path.

---

## Solution: Graceful Degradation with `isProcessing` Flag

### Design Principles

1. **Actor Model alignment** — XState v5 has `actor.getSnapshot()` that reads state without event processing. Reading an actor's state should not require the event queue.
2. **HTTP semantics** — GET is safe/idempotent (read), POST is unsafe (write). Different failure modes for different intents.
3. **Data consistency** — Always return a complete, internally consistent snapshot. Never mix state from different points in time.
4. **No silent event loss** — Write operations must explicitly fail so the caller can retry.

### Response Envelope Change

Add `isProcessing` to the standard response envelope:

```json
{
  "data": {
    "id": "01JQFZ...",
    "machineId": "car_sales",
    "state": ["processing_payment"],
    "availableEvents": ["CANCEL"],
    "output": { ... },
    "isProcessing": false
  }
}
```

`isProcessing` is **always present** in every endpoint response (`buildResponse`, `buildForwardedResponse`, `handleCreate`):
- `false` — normal path, event was processed, state is settled
- `true` — lock contention, returning last committed snapshot

### GET Endpoints — 200 with Snapshot

When a GET request hits lock contention:

1. Catch `MachineAlreadyRunningException`
2. Use `$machine->state` — already re-restored inside `$machine->send()` before lock attempt (line 308)
3. Build response with that state
4. Return **HTTP 200** with `isProcessing: true`

Why 200: The read succeeded. The client asked "what is the current state?" and we answered truthfully with the last committed state.

Why `$machine->state` is fresh: Inside `$machine->send()`, before lock acquisition, the machine does `$this->state = $this->restoreStateFromRootEventId($rootEventId)` (line 308). This reads the latest committed state from DB. Since the exception is thrown immediately after (line 324), `$machine->state` holds this freshly restored state. No additional re-restore is needed.

**Caveat:** The restore inside `send()` has a defensive `catch (\Throwable)` that silently continues with the old state if restore fails (e.g., DB connectivity issue). In this extreme edge case, `$machine->state` would be the state from controller entry, not freshly restored. This is acceptable — if the DB is unreachable, we can't do better, and the old state is still internally consistent.

```
GET /status during contention:

HTTP 200
{
  "data": {
    "id": "01JQFZ...",
    "machineId": "car_sales",
    "state": ["processing_payment"],
    "availableEvents": ["CANCEL"],
    "output": { "status": "Ödeme işleniyor..." },
    "isProcessing": true
  }
}
```

### POST/PUT/DELETE Endpoints — 423 with Snapshot

When a write request hits lock contention:

1. Catch `MachineAlreadyRunningException`
2. Use `$machine->state` (same as GET — already fresh from internal restore)
3. Return **HTTP 423 Locked** with current state + `isProcessing: true`

Why 423: The event was NOT processed. The client must know this explicitly. 423 (Locked) is the correct HTTP status — the resource is locked for processing.

Why include state: The frontend can update its UI with the current state even though the write failed. It knows to retry.

```
POST /submit-payment during contention:

HTTP 423
{
  "data": {
    "id": "01JQFZ...",
    "machineId": "car_sales",
    "state": ["processing_payment"],
    "availableEvents": ["CANCEL"],
    "output": null,
    "isProcessing": true
  }
}
```

### Data Correctness Guarantee

`$machine->state` after a failed `$machine->send()` is always internally consistent. The `restoreStateFromRootEventId()` call inside `send()` restores the full machine state from DB — state, context, history are all from the same point in time:

| Timing of restore inside send() | DB has | We return | availableEvents | output | Consistent? |
|----------------------------------|--------|-----------|-----------------|--------|-------------|
| Before persist() committed | stateA | stateA | stateA's | stateA's | ✅ |
| After persist() committed | stateB | stateB | stateB's | stateB's | ✅ |

We never return stateA with stateB's availableEvents. The entire snapshot comes from one `restoreStateFromRootEventId()` call which restores a coherent machine state.

---

## Implementation

### Files to Change

1. **`src/Routing/MachineController.php`** — catch `MachineAlreadyRunningException` in `executeEndpoint()` and `executeForwardedEndpoint()`
2. **`src/Routing/MachineController.php`** — add `isProcessing` parameter to `buildResponse()` and `buildForwardedResponse()`

No changes to `MachineAlreadyRunningException` — `$machine->state` already provides all needed data after the exception.

### MachineController::executeEndpoint() — Lock Contention Handling

Catch order is critical: `MachineAlreadyRunningException` **must** be placed before the `\Throwable` catch, otherwise `\Throwable` catches it first.

The `action?->before()` has already executed when the exception is caught. This is acceptable — `before()` ran, but since the event was not processed, `after()` is intentionally skipped. The action's `onException()` is also skipped because this is not an error — it's a graceful degradation to a snapshot response.

```php
protected function executeEndpoint(
    Machine $machine,
    EventBehavior $event,
    ?string $actionClass,
    ?string $outputKey,
    int $statusCode,
    ?array $outputKeys = null,
    ?bool $includeAvailableEvents = true,
): JsonResponse {
    $action = $actionClass !== null
        ? resolve($actionClass)->withMachineContext($machine, $machine->state)
        : null;

    $action?->before();

    try {
        $state = $machine->send(event: $event);
    } catch (MachineAlreadyRunningException) {
        // $machine->state is fresh — send() restores from DB before lock attempt.
        // GET → 200 (read succeeded), POST/PUT/DELETE → 423 (event not processed).
        $httpStatus = request()->isMethod('GET') ? 200 : 423;

        return $this->buildResponse(
            state: $machine->state,
            machine: $machine,
            outputKey: $outputKey,
            statusCode: $httpStatus,
            outputKeys: $outputKeys,
            includeAvailableEvents: $includeAvailableEvents,
            isProcessing: true,
        );
    } catch (MachineValidationException $e) { // @phpstan-ignore catch.neverThrown
        return response()->json([
            'message' => $e->getMessage(),
            'errors'  => method_exists($e, 'errors') ? $e->errors() : [],
        ], 422);
    } catch (ValidationException $e) { // @phpstan-ignore catch.neverThrown
        return response()->json([
            'message' => $e->getMessage(),
            'errors'  => $e->errors(),
        ], 422);
    } catch (\Throwable $e) {
        $response = $action?->onException($e);

        if ($response !== null) {
            return $response;
        }

        throw $e;
    }

    if ($action !== null) {
        $action->withMachineContext($machine, $state);
    }

    $action?->after();

    $this->dispatchChildCompletionIfFinal($machine, $state);

    return $this->buildResponse(
        state: $state,
        machine: $machine,
        outputKey: $outputKey,
        statusCode: $statusCode,
        outputKeys: $outputKeys,
        includeAvailableEvents: $includeAvailableEvents,
        isProcessing: false,
    );
}
```

### MachineController::executeForwardedEndpoint() — Same Pattern

The forwarded endpoint has the same catch chain structure. `MachineAlreadyRunningException` is caught before `\Throwable`:

```php
try {
    $state = $machine->send([
        'type'    => $parentEventType,
        'payload' => $event->payload ?? [],
    ]);
} catch (MachineAlreadyRunningException) {
    $httpStatus = $request->isMethod('GET') ? 200 : 423;

    return $this->buildForwardedResponse(
        machine: $machine,
        state: $machine->state,
        defaults: $defaults,
        isProcessing: true,
        statusCodeOverride: $httpStatus,
    );
} catch (MachineValidationException $e) { // @phpstan-ignore catch.neverThrown
    // ... existing
} catch (ValidationException $e) { // @phpstan-ignore catch.neverThrown
    // ... existing
} catch (\Throwable $e) {
    // ... existing
}
```

Note: `executeForwardedEndpoint()` already receives `Request $request` as a parameter, so `$request->isMethod('GET')` is used directly (no `request()` helper needed).

### MachineController::buildResponse() — Add isProcessing

```php
protected function buildResponse(
    State $state,
    Machine $machine,
    ?string $outputKey,
    int $statusCode,
    ?array $outputKeys = null,
    ?bool $includeAvailableEvents = true,
    bool $isProcessing = false,
): JsonResponse {
    // ... existing output resolution logic unchanged ...

    $response = [
        'id'              => $rootEventId,
        'machineId'       => $state->currentStateDefinition->machine->id ?? null,
        'state'           => $state->value,
        'availableEvents' => $state->availableEvents(),
        'output'          => $outputData,
        'isProcessing'    => $isProcessing,
    ];

    return response()->json(['data' => $response], $statusCode);
}
```

### MachineController::buildForwardedResponse() — Add isProcessing

```php
protected function buildForwardedResponse(
    Machine $machine,
    State $state,
    array $defaults,
    bool $isProcessing = false,
    ?int $statusCodeOverride = null,
): JsonResponse {
    // ... existing output/child resolution logic ...

    $statusCode = $statusCodeOverride ?? ($defaults['_status_code'] ?? 200);

    // Add 'isProcessing' to both response shapes (custom output and default)
    $response['isProcessing'] = $isProcessing;

    return response()->json(['data' => $response], $statusCode);
}
```

### MachineController::handleCreate() — isProcessing via Default

`handleCreate()` calls `buildResponse()` directly without `$machine->send()`. No lock contention possible. The `isProcessing: false` default in `buildResponse()` covers this — no change needed in `handleCreate()`.

---

## Edge Cases

### Output Behavior in Fallback Path

In the fallback path, `state->triggeringEvent` is the triggering event from the LAST persisted transition, not the current request's event. For status endpoints, output behaviors typically read context data and don't depend on the triggering event's payload. If an output behavior requires the current request's event payload, it should be redesigned — a read endpoint's output should not depend on the read request's input.

### Stateless Endpoints

Stateless endpoints (`handleStateless`) create fresh machines per request — no persistence, no lock. Lock contention cannot occur. `isProcessing` will be `false` via default.

### Create Endpoints

Create endpoints (`handleCreate`) don't call `$machine->send()` — they just create and persist. No lock contention possible. `isProcessing` will be `false` via default.

### Forwarded Endpoints — No Child State in Fallback

In the normal path, `buildForwardedResponse()` calls `$state->getForwardedChildState()` to include child machine state in the response. In the contention fallback path, the event was never forwarded to the child — so `getForwardedChildState()` returns `null` and the `child` key is absent from the response.

This is correct behavior: the forwarded event was not processed, so there is no child state to report. For GET forwarded endpoints (200), the response contains the parent's current state without child info. For POST forwarded endpoints (423), the response signals the event was rejected. In both cases, the frontend receives a valid snapshot of what IS known (parent state) and `isProcessing: true` signals that the full picture is not yet available.

### Parallel State Machines

During parallel dispatch, `ParallelRegionJob` workers hold locks independently. A GET request during parallel processing would hit the lock. The fallback returns the last committed parallel state snapshot — regions may be partially complete. `isProcessing: true` correctly signals "not settled yet".

### action->before() Already Executed

When `MachineAlreadyRunningException` is caught, `action?->before()` has already run. The action lifecycle in the fallback path is: `before()` ✅ → `after()` ❌ → `onException()` ❌. This is by design — the event was not processed, so `after()` (which typically post-processes the result) has nothing to do. `onException()` is skipped because this is a graceful response, not an error.

---

## What This Does NOT Change

- **Lock mechanism** — `MachineLockManager`, lock TTL, re-entrant tracking: all unchanged
- **`$machine->send()`** — still throws `MachineAlreadyRunningException` on lock failure
- **`MachineAlreadyRunningException`** — no structural changes to the exception class
- **POST/write semantics** — events are still rejected (423), never silently dropped
- **Event recording** — fallback path does NOT record the read event (the machine was busy)
- **Core transition logic** — `MachineDefinition::transition()` untouched

---

## Test Plan

### Test Infrastructure

**Config prerequisite:** Lock acquisition in `$machine->send()` only triggers when `config('queue.default') !== 'sync'` OR `config('machine.parallel_dispatch.enabled')` is true. Unit tests use sync queue by default. All contention tests must set `config(['machine.parallel_dispatch.enabled' => true])` in setup.

**Lock simulation pattern** (from existing `LockRejectionStateTest`):
```php
config()->set('machine.parallel_dispatch.enabled', true);

// Create and persist machine
$machine = SomeMachine::create();
$machine->send(['type' => 'START']);
$machine->persist();
$rootEventId = $machine->state->history->first()->root_event_id;

// Simulate lock held by another process
MachineStateLock::create([
    'root_event_id' => $rootEventId,
    'owner_id'      => (string) Str::ulid(),
    'acquired_at'   => now(),
    'expires_at'    => now()->addSeconds(60),
    'context'       => 'simulated_other_process',
]);

// HTTP request to locked machine → contention
```

**Stubs needed:** Existing stubs cover most scenarios:
- `TestEndpointMachine` + `TestEndpointAction` — POST endpoints with action lifecycle tracking
- `GetEndpointMachine` — GET endpoint with validation
- `ForwardParentEndpointMachine` + `ForwardChildEndpointMachine` — forwarded endpoints
- **New stub needed:** A machine with both GET (targetless/status) and POST endpoints on the same state, with `should_persist: true`. This enables testing both GET and POST contention on the same machine instance.

---

### Unit Tests — `tests/Routing/EndpointLockContentionTest.php`

#### A. Response Envelope — isProcessing Always Present

1. **Normal POST request includes isProcessing:false**
   - No lock held, POST endpoint
   - Assert response has `data.isProcessing` key with value `false`

2. **Normal GET request includes isProcessing:false**
   - No lock held, GET endpoint
   - Assert response has `data.isProcessing` key with value `false`

3. **Create endpoint includes isProcessing:false**
   - `POST /create`
   - Assert response has `data.isProcessing` key with value `false`

4. **Stateless endpoint includes isProcessing:false**
   - Stateless POST endpoint
   - Assert response has `data.isProcessing` key with value `false`

#### B. GET Contention — 200 with Snapshot

5. **GET endpoint returns 200 + isProcessing:true during lock contention**
   - Create machine, persist, insert lock row
   - `GET /status` → assert 200
   - Assert `data.isProcessing` is `true`
   - Assert `data.state` matches persisted state
   - Assert `data.availableEvents` is non-null array
   - Assert `data.id` matches rootEventId
   - Assert `data.machineId` is present

6. **GET contention returns correct availableEvents for current state**
   - Machine in `idle` state with `STATUS_REQUESTED` and `START` available
   - Lock held → GET /status
   - Assert `availableEvents` contains the events valid from `idle`

7. **GET contention returns output from current state**
   - Machine with output behavior on GET endpoint
   - Lock held → GET /status
   - Assert `data.output` contains expected output data computed from current context

#### C. POST Contention — 423 Locked

8. **POST endpoint returns 423 + isProcessing:true during lock contention**
   - Create machine, persist, insert lock row
   - `POST /start` → assert 423
   - Assert `data.isProcessing` is `true`
   - Assert `data.state` matches persisted state (NOT the target state of the event)

9. **POST contention does not modify machine state**
   - Machine in `idle`, lock held
   - `POST /start` (would transition to `started`)
   - Assert 423
   - Release lock, restore machine from DB
   - Assert machine still in `idle` (event was not processed)

10. **POST contention returns correct state data in 423 body**
    - Assert response body has full envelope: `id`, `machineId`, `state`, `availableEvents`, `output`, `isProcessing`

#### D. Action Lifecycle

11. **action->before() runs, after() skipped during contention**
    - Endpoint with `TestEndpointAction` configured
    - Lock held → POST request → 423
    - Assert `TestEndpointAction::$beforeCalled` is `true`
    - Assert `TestEndpointAction::$afterCalled` is `false`

12. **action->onException() is NOT called during contention**
    - Lock held → POST request → 423
    - Assert `TestEndpointAction::$lastException` is `null`

#### E. Forwarded Endpoints

13. **Forwarded POST endpoint returns 423 during parent lock contention**
    - Create parent, start child delegation, insert lock on parent
    - `POST /provide-card` → assert 423
    - Assert `data.isProcessing` is `true`
    - Assert `data.state` is parent's current state

14. **Forwarded contention response has no child key**
    - Same setup as #13
    - Assert response does NOT have `data.child` key
    - Assert `data.isProcessing` is `true`

15. **Forwarded endpoint returns isProcessing:false on normal path**
    - No lock, forwarded POST
    - Assert response has `data.child` key with child state
    - Assert `data.isProcessing` is `false`

#### F. State Data Freshness

16. **Contention response reflects latest committed state**
    - Machine starts in `idle`, transitions to `started`, persist
    - Insert lock row (simulating further processing after persist)
    - GET request → assert `data.state` contains `started` (not `idle`)

17. **Multiple contention requests return same consistent snapshot**
    - Lock held
    - Send 3 GET requests
    - All 3 should return 200 with identical `state`, `availableEvents`, `output`

#### G. Boundary Conditions

18. **Contention on machine with custom endpoint statusCode**
    - Endpoint configured with `'status' => 201`
    - Lock held → POST → assert 423 (not 201, contention overrides configured status)
    - No lock → POST → assert 201 (normal path uses configured status)

19. **Contention on endpoint with outputKeys (array filter)**
    - Endpoint with `output => ['key1', 'key2']`
    - Lock held → GET → assert output only contains filtered keys

20. **Contention does not leave stale lock rows**
    - Lock held by other process, GET request returns 200
    - Assert lock row count unchanged (we didn't create or remove any locks)

---

### Unit Tests — Existing Files Regression

21. **All existing MachineControllerTest tests still pass with isProcessing:false in assertions**
    - Update existing assertions to expect `isProcessing: false` in response envelope
    - This verifies backward compatibility of the envelope change

22. **All existing GetEndpointTest tests include isProcessing:false**
    - Same pattern — existing tests now assert `isProcessing` is present and `false`

23. **All existing ForwardedEndpointHttpTest tests include isProcessing:false**
    - Forwarded responses include `isProcessing: false`

---

### LocalQA Tests — `tests/LocalQA/GracefulLockContentionQATest.php`

These tests use real MySQL + Redis + Horizon. They verify the race condition fix in a real concurrent environment.

**Setup pattern:**
```php
uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});
```

**New stub needed for LocalQA:** A machine class with:
- `should_persist: true`
- A slow entry action (e.g., `sleep(2)` or a long-running calculator) to hold the lock long enough
- GET endpoint (targetless, `/status`) and POST endpoint on the same state
- Registered via `MachineRouter::register()` in the QA Laravel app

#### QA1. Concurrent send + GET status

```
Steps:
1. Create machine with should_persist, persist it
2. Dispatch a SendToMachineJob to Horizon (async) with an event
   that triggers a slow transition (entry action sleeps ~2s)
3. Wait 500ms for Horizon to pick up the job and acquire lock
4. From test process: restore machine, attempt GET /status via HTTP
5. Assert GET returns 200 + isProcessing: true
6. waitFor machine to reach target state (slow action completed)
7. GET /status again
8. Assert isProcessing: false + new state
```

#### QA2. Concurrent send + POST (write rejected)

```
Steps:
1. Create machine, persist
2. Dispatch SendToMachineJob with slow-transition event
3. Wait 500ms for Horizon to acquire lock
4. From test process: POST to another endpoint
5. Assert POST returns 423 + isProcessing: true
6. waitFor machine to complete slow transition
7. POST same event again
8. Assert 200 + isProcessing: false (event processed normally)
```

#### QA3. Multiple rapid GETs during processing

```
Steps:
1. Create machine, persist
2. Dispatch slow-transition job
3. Wait 500ms
4. Fire 5 GET requests in rapid succession
5. Assert all 5 return 200
6. Assert all 5 have isProcessing: true
7. Assert all 5 have consistent state data
```

#### QA4. Lock released after processing — no stale contention

```
Steps:
1. Create machine, persist
2. Dispatch slow-transition job (2s sleep)
3. waitFor transition to complete
4. GET /status
5. Assert 200 + isProcessing: false
6. Assert no lock rows in machine_locks for this rootEventId
```

#### QA5. POST retry succeeds after contention resolves

```
Steps:
1. Create machine in idle state, persist
2. Dispatch SendToMachineJob with slow event (idle → processing, 2s)
3. Wait 500ms
4. POST /next-action → 423 + isProcessing: true (lock held)
5. waitFor slow transition to complete
6. POST /next-action again → 200 + isProcessing: false
7. Assert machine state reflects the POST event's transition
```

---

## Documentation Plan

### 1. `docs/laravel-integration/endpoints.md` — CRITICAL

Three sections need updating:

**a) Default Response (satır 271-319):** Add `isProcessing` field to both JSON examples (normal and parallel state):

```json
{
    "data": {
        "id": "01JARX5Z8KQVN...",
        "state": ["submitted"],
        "output": { ... },
        "availableEvents": [ ... ],
        "isProcessing": false
    }
}
```

**b) EndpointAction Lifecycle (satır 385-429):** Update the ASCII flow diagram to show the `MachineAlreadyRunningException` catch as a new branch:

```
+-- try {
|       $machine->send($event)
|   }
|
+-- catch (MachineAlreadyRunningException) {      ← NEW
|       GET  → 200 + snapshot + isProcessing:true
|       POST → 423 + snapshot + isProcessing:true
|       action.after() NOT called
|       action.onException() NOT called
|   }
|
+-- catch (Throwable $e) {
|       === action.onException($e) ===
|   }
```

**c) New section: "Lock Contention Handling"** — Add after EndpointAction Lifecycle:
- Explain the race condition scenario (broadcast + immediate GET)
- Document GET → 200, POST → 423 behavior
- Show `isProcessing` field semantics
- Explain that `$machine->state` is fresh from internal restore

### 2. `docs/understanding/machine-lifecycle.md` — HIGH

**Concurrent Execution Safety section (satır 292-313):** Currently says "cache locks" and shows `Cache::lock()` — both wrong. Rewrite to:
- Explain DB-backed mutex via `machine_locks` table and `MachineLockManager`
- Explain that `$machine->send()` acquires lock with `timeout=0` (non-blocking)
- Explain that HTTP endpoints now handle `MachineAlreadyRunningException` gracefully
- Add cross-reference to `docs/laravel-integration/endpoints.md` for details

### 3. `docs/laravel-integration/persistence.md` — HIGH

**Distributed Locking section (satır 231-263):** Currently shows `Cache::lock()` — completely stale. Rewrite to:
- Explain `MachineLockManager` + `machine_locks` table
- Show lock acquisition flow: `timeout=0`, `ttl=60`, `context='send'`
- Explain re-entrant lock support via `$heldLockIds`
- Explain when locking is active: async queue OR `parallel_dispatch.enabled`
- Mention graceful handling in HTTP layer (cross-reference to endpoints.md)

### 4. `CLAUDE.md` — HIGH

**HTTP Endpoint Routing section (satır ~89-96):** Add one bullet:
- **`MachineController`** description: add "graceful `MachineAlreadyRunningException` handling — GET returns 200 + snapshot, POST returns 423, `isProcessing` flag in all responses"

### 5. `docs/getting-started/upgrading.md` — RELEASE TIME

Add to the version section when released:
- New `isProcessing` field in all endpoint responses (additive, non-breaking)
- `MachineAlreadyRunningException` now handled gracefully in HTTP layer
- GET endpoints return 200 with snapshot during lock contention (was 500)
- POST endpoints return 423 with snapshot during lock contention (was 500)
- Note: existing clients should handle the new field — `isProcessing: false` is the default
