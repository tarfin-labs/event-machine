# GET Endpoint Query Parameter Validation

**Status:** Draft
**Author:** Claude
**Date:** 2026-03-26
**Scope:** Normalize GET request query parameters into `payload` so EventBehavior validation rules work for all HTTP methods
**Reported by:** Turan (CarSalesMachine — GET endpoint query params bypass validation rules)

---

## Problem

When an endpoint uses `'method' => 'GET'`, request data arrives as flat query parameters. `MachineController::resolveEvent()` passes `$request->all()` directly to `EventBehavior::validateAndCreate()`, but EventBehavior expects data nested under `payload`.

### Current Behavior

```
GET /car-sales/status?dealer_code=ABC123&plate_number=34ABC99
```

`$request->all()` returns:
```php
['dealer_code' => 'ABC123', 'plate_number' => '34ABC99']
```

EventBehavior validation rules target `payload.*`:
```php
public static function rules(): array
{
    return [
        'payload.dealer_code'   => ['required', 'string'],
        'payload.plate_number'  => ['required', 'string'],
    ];
}
```

**Result:** Validation rules never match — `payload` key doesn't exist. The event is created with `payload: null`, validation is silently bypassed.

### Expected Behavior

Query parameters should be automatically wrapped into `payload` so the same `rules()` definitions work for both GET and POST endpoints.

---

## Root Cause

Two call sites pass raw `$request->all()` to EventBehavior:

| Call Site | File | Line |
|-----------|------|------|
| `resolveEvent()` | `MachineController.php` | 121 |
| `executeForwardedEndpoint()` | `MachineController.php` | 296 |

Both use:
```php
$eventClass::validateAndCreate($request->all());
```

For POST/PATCH/PUT with JSON body, `$request->all()` naturally contains `{"type": "...", "payload": {...}}`. For GET, query params are flat — no `payload` wrapper exists.

### Async paths are NOT affected

`$request->all()` only appears in `MachineController` (the HTTP boundary). All async paths construct events internally with `['type' => ..., 'payload' => [...]]`:

| Async Path | Event Source | Affected? |
|-----------|-------------|-----------|
| `ChildMachineJob` | Parent's `with` config → child context | No |
| `SendToMachineJob` | `dispatchTo()` / `dispatchToParent()` array | No |
| `ChildMachineCompletionJob` | `@done` / `@fail` routing (no event payload) | No |
| `ParallelRegionJob` | Internal region dispatch | No |
| `Machine::send()` from code | Already receives `['type' => ..., 'payload' => [...]]` | No |

The fix is purely at the HTTP boundary — no async, delegation, or inter-machine communication is affected.

---

## Design Constraints

1. **POST behavior must not change** — existing POST endpoints with `payload` in the body must continue to work identically
2. **Validation rules stay the same** — users should NOT need separate rule sets for GET vs POST
3. **Works for both regular and forwarded endpoints** — both `resolveEvent()` and `executeForwardedEndpoint()` must be fixed
4. **No ambiguity** — if a GET request explicitly sends `?payload[key]=value`, respect it as-is (don't double-wrap)
5. **`type` and `version` are auto-resolved** — GET requests should not require `?type=EVENT_TYPE` in the URL; the event type comes from the endpoint config, not the request

---

## Solution

### Extract a `resolveRequestData()` method on `MachineController`

```php
/**
 * Normalize request data for EventBehavior consumption.
 *
 * For GET requests, query params arrive as flat key-value pairs without
 * the `payload` wrapper that POST JSON bodies naturally have. This method
 * wraps them so validation rules targeting `payload.*` work uniformly.
 */
protected function resolveRequestData(Request $request): array
{
    $data = $request->all();

    if ($request->isMethod('GET') && !isset($data['payload'])) {
        $data = ['payload' => $data];
    }

    return $data;
}
```

Then update both call sites:

**`resolveEvent()`** (line 121):
```php
return $eventClass::validateAndCreate($this->resolveRequestData($request));
```

**`executeForwardedEndpoint()`** (line 296):
```php
$event = $childEventClass::validateAndCreate($this->resolveRequestData($request));
```

### Why this approach

| Alternative | Rejected because |
|-------------|-----------------|
| Override in EventBehavior (`prepareForValidation`) | Leaks HTTP concerns into the domain layer; EventBehavior shouldn't know about GET vs POST |
| Require `?payload[key]=value` syntax | Non-standard for GET APIs, poor DX for API consumers |
| Separate rule format for GET | Violates constraint #2, doubles maintenance |
| Middleware | Over-engineered for a two-line fix |

The controller is the HTTP boundary — it's the right place to normalize transport-level differences before passing data to the domain layer.

---

## Edge Cases

### GET with explicit `payload` parameter
```
GET /endpoint?payload[card_number]=4111
```
`$request->all()` returns `['payload' => ['card_number' => '4111']]` — `isset($data['payload'])` is true, so no wrapping occurs. Correct.

### GET with no query params
```
GET /endpoint
```
`$request->all()` returns `[]` — wrapped to `['payload' => []]`. Validation rules like `'payload.x' => ['required']` will correctly fail. Correct.

### GET with `type` query param
```
GET /endpoint?type=STATUS_REQUESTED&dealer_code=ABC
```
`$request->all()` returns `['type' => 'STATUS_REQUESTED', 'dealer_code' => 'ABC']`. After wrapping: `['payload' => ['type' => 'STATUS_REQUESTED', 'dealer_code' => 'ABC']]`. The `type` from query gets buried in payload, but EventBehavior auto-resolves `type` from `getType()` when it's null (line 71-73 of EventBehavior). This is correct — the event type comes from the endpoint config, not the URL.

### POST unchanged
```
POST /endpoint {"type": "SUBMIT", "payload": {"amount": 100}}
```
`$request->isMethod('GET')` is false — no transformation. Existing behavior preserved.

### DELETE / PATCH / PUT with body
Same as POST — `isMethod('GET')` is false, no transformation. These methods support request bodies natively.

---

## Documentation

### What needs updating

`docs/laravel-integration/endpoints.md` mentions `'method' => 'PATCH'` as an example (line 114) but has **zero GET-specific guidance**. Since GET endpoints behave differently (query params vs body), this needs to be documented.

### Changes to `docs/laravel-integration/endpoints.md`

**1. Add a "GET Endpoints" section** (after "Array Configuration Options", before "URI Auto-Generation"):

Content to cover:
- When to use GET: read-only queries, status lookups, search endpoints
- Query params are automatically wrapped into `payload` — same validation rules work
- Example: machine definition with `'method' => 'GET'`, EventBehavior with `rules()`, and the resulting HTTP call
- Note: query param values are always strings (no type coercion) — use validation rules like `'numeric'` when needed

**2. Update the "Array Configuration Options" table** (line 139):

Add a note to the `method` row clarifying GET behavior:
> `'POST'` — HTTP method. For `GET`, query parameters are automatically normalized into `payload`.

**3. Add GET example to "Definition Formats" section** (inside format 3 "Array — full configuration"):

```php
// GET endpoint — query params wrapped into payload automatically
'STATUS_REQUESTED' => [
    'uri'    => '/status',
    'method' => 'GET',
],
```

### What does NOT need updating

- CLAUDE.md — already describes endpoint routing architecture generically
- CODEBASE_MAP.md — no structural change
- `docs/building/conventions.md` — no naming convention impact

---

## Test Plan

### New Test Stubs

**1. `GetEndpointMachine`** — regular GET endpoint with validation (two required fields):

```php
// Machine: idle --STATUS_REQUESTED--> done (final)
// Endpoint: GET /status (requires dealer_code + plate_number)
// Calculator: stores dealer_code and plate_number in context (to verify payload arrived)
// should_persist: true (needed for machineId-bound test)
'endpoints' => [
    'STATUS_REQUESTED' => [
        'uri'    => '/status',
        'method' => 'GET',
    ],
],
```

**2. `StatusRequestedEvent`** — EventBehavior with validation rules on two fields:

```php
class StatusRequestedEvent extends EventBehavior
{
    public static function getType(): string { return 'STATUS_REQUESTED'; }

    public static function rules(): array
    {
        return [
            'payload.dealer_code'  => ['required', 'string', 'min:3'],
            'payload.plate_number' => ['required', 'string'],
        ];
    }
}
```

Two required fields enables: testing partial submission fails (test #2), testing multiple params arrive together (test #1).

**3. `GetEndpointNoValidationMachine` + `PingEvent`** — GET endpoint without validation rules:

```php
// Machine: idle --PING--> done (final)
// Endpoint: GET /ping (no rules on PingEvent)
// Calculator: stores full payload in context (to verify wrapping happened)
class PingEvent extends EventBehavior
{
    public static function getType(): string { return 'PING'; }
    // No rules() override — all payloads accepted
}
```

Calculator storing payload enables asserting that wrapping occurred even without validation rules.

**4. `GetForwardParentMachine` + `GetForwardChildMachine` + `ChildStatusEvent`** — forwarded GET endpoint:

```php
// Parent states:
//   idle (initial) --START--> delegating
//   delegating:
//     machine: GetForwardChildMachine::class  (sync child)
//     forward:
//       CHILD_STATUS:
//         child_event: CHILD_STATUS
//         method: GET
//         uri: /child-status
//     @done => completed
//   completed: type: final
//
// Child states:
//   idle (initial) --CHILD_STATUS--> done (final)
//
// Test setup: POST /create → start event → parent reaches `delegating`
//             GET /{machineId}/child-status?child_param=hello → forwarded to child

class ChildStatusEvent extends EventBehavior
{
    public static function getType(): string { return 'CHILD_STATUS'; }

    public static function rules(): array
    {
        return [
            'payload.child_param' => ['required', 'string'],
        ];
    }
}
```

Parent needs a START event to reach `delegating` state before the forwarded GET can be sent. Test #9 and #10 must first create the parent and transition it.

### Router Registrations (in `beforeEach`)

```php
// Stateless GET (tests #1–7, #11–14)
MachineRouter::register(GetEndpointMachine::class, [
    'prefix' => '/api/get',
    'name'   => 'get_endpoint',
]);

// Stateless GET no-validation (tests #6, #7, #13)
MachineRouter::register(GetEndpointNoValidationMachine::class, [
    'prefix' => '/api/get-noval',
    'name'   => 'get_noval',
]);

// MachineId-bound GET (test #8)
MachineRouter::register(GetEndpointMachine::class, [
    'prefix'       => '/api/get-mid',
    'machineIdFor' => ['STATUS_REQUESTED'],
    'create'       => true,
    'name'         => 'get_mid',
]);

// Forwarded GET (tests #9, #10)
// START is POST (transition to delegating), CHILD_STATUS is forwarded GET
MachineRouter::register(GetForwardParentMachine::class, [
    'prefix'       => '/api/get-fwd',
    'machineIdFor' => ['START', 'CHILD_STATUS'],
    'create'       => true,
    'name'         => 'get_fwd',
]);
```

### Test Cases

#### A. Core Wrapping (stateless GET)

| # | Test | HTTP Request | Expected | Verifies |
|---|------|-------------|----------|----------|
| 1 | Both required params reach payload | `GET /status?dealer_code=ABC123&plate_number=34XY` | 200, context has both values | Basic wrapping works, multiple params arrive in payload |
| 2 | Missing one required param → 422 | `GET /status?dealer_code=ABC123` | 422, error mentions `plate_number` | Validation fires correctly on wrapped payload |
| 3 | Missing all required params → 422 | `GET /status` | 422, errors mention both fields | Empty `[]` wrapped to `['payload' => []]`, validation catches it |
| 4 | Validation rule enforced (min:3) | `GET /status?dealer_code=AB&plate_number=34XY` | 422, error on dealer_code min:3 | Rules execute beyond presence check |

#### B. No-Wrap Guard (`isset($data['payload'])`)

| # | Test | HTTP Request | Expected | Verifies |
|---|------|-------------|----------|----------|
| 5 | Explicit `payload[]` syntax not double-wrapped | `GET /status?payload[dealer_code]=ABC123&payload[plate_number]=34XY` | 200 | `isset` guard prevents double-wrapping |

#### C. No Validation Rules

| # | Test | HTTP Request | Expected | Verifies |
|---|------|-------------|----------|----------|
| 6 | GET with no rules, no params | `GET /ping` | 200 | Empty payload passthrough works |
| 7 | GET with no rules, params stored in context | `GET /ping?foo=bar&baz=qux` | 200, context payload has `foo` and `baz` | Wrapping happens even without validation; calculator can access payload |

#### D. MachineId-Bound GET

| # | Test | HTTP Request | Expected | Verifies |
|---|------|-------------|----------|----------|
| 8 | Create machine, then GET with machineId | 1. `POST /create` → get machineId 2. `GET /{machineId}/status?dealer_code=ABC&plate_number=34XY` | 200, machine transitions | `handleMachineIdBound` → `resolveEvent` path works |

#### E. Forwarded GET Endpoints

| # | Test | HTTP Request | Expected | Verifies |
|---|------|-------------|----------|----------|
| 9 | Forwarded GET validates and reaches child | 1. `POST /create` 2. `POST /{machineId}/start` 3. `GET /{machineId}/child-status?child_param=hello` | 200, child receives event | `executeForwardedEndpoint()` wrapping works |
| 10 | Forwarded GET missing required child param | (reuse parent from #9 setup) `GET /{machineId}/child-status` | 422, error mentions `child_param` | Forwarded validation fires |

#### F. Edge Cases

| # | Test | HTTP Request | Expected | Verifies |
|---|------|-------------|----------|----------|
| 11 | Numeric string passes `string` rule | `GET /status?dealer_code=12345&plate_number=34XY` | 200 | GET string coercion doesn't break string validation |
| 12 | Empty string converted to null by middleware | `GET /status?dealer_code=&plate_number=34XY` | 422, required error on dealer_code | Laravel `ConvertEmptyStringsToNull` middleware converts `''` → `null`, then `required` fails |
| 13 | Array-style query params preserved | `GET /ping?items[]=a&items[]=b` | 200, context payload has `items: ['a', 'b']` | Laravel array bracket syntax survives wrapping (uses no-validation machine) |
| 14 | `type` in query param doesn't override event type | `GET /status?type=WRONG&dealer_code=ABC123&plate_number=34XY` | 200, event type is `STATUS_REQUESTED` not `WRONG` | `type` gets buried in payload; EventBehavior resolves type from `getType()` |

### Not Tested (covered by existing tests)

- **POST regression** — `MachineControllerTest.php` already has comprehensive POST endpoint tests. Our change only activates for `isMethod('GET')`, POST path is untouched.
- **Model-bound GET** — same code path as machineId-bound (`handleModelBound` → `handleEndpoint` → `resolveEvent`). Testing machineId-bound covers the shared `resolveEvent()` call.

---

## Scope

### In scope
- `MachineController::resolveEvent()` — wrap GET query params
- `MachineController::executeForwardedEndpoint()` — wrap GET query params
- Test stub machines for GET endpoints (regular with validation, no-validation, forwarded)
- Tests for stateless, machineId-bound, and forwarded GET handlers
- Documentation update in `docs/laravel-integration/endpoints.md`

### Out of scope
- Changing EventBehavior structure (payload remains `null|array|Optional`)
- Adding GET-specific validation rule syntax
- Query parameter type coercion (e.g., `?amount=100` stays string `"100"`)
- Model-bound GET, async paths — see "Not Tested" and "Async paths are NOT affected" sections above

---

## Files to Change

| File | Change |
|------|--------|
| `src/Routing/MachineController.php` | Add `resolveRequestData()`, update `resolveEvent()` and `executeForwardedEndpoint()` |
| `tests/Stubs/Machines/Endpoint/GetEndpoint/GetEndpointMachine.php` | New: GET machine with validation + `should_persist` |
| `tests/Stubs/Machines/Endpoint/GetEndpoint/StatusRequestedEvent.php` | New: EventBehavior with two required fields + min:3 |
| `tests/Stubs/Machines/Endpoint/GetEndpoint/StoreStatusCalculator.php` | New: stores dealer_code + plate_number in context |
| `tests/Stubs/Machines/Endpoint/GetEndpoint/GetEndpointNoValidationMachine.php` | New: GET machine without validation rules |
| `tests/Stubs/Machines/Endpoint/GetEndpoint/PingEvent.php` | New: EventBehavior with no rules |
| `tests/Stubs/Machines/Endpoint/GetEndpoint/StorePingPayloadCalculator.php` | New: stores full payload in context |
| `tests/Stubs/Machines/Endpoint/GetEndpoint/GetForwardParentMachine.php` | New: parent with idle→delegating→completed, forwards GET to child |
| `tests/Stubs/Machines/Endpoint/GetEndpoint/GetForwardChildMachine.php` | New: child machine with validated GET event |
| `tests/Stubs/Machines/Endpoint/GetEndpoint/ChildStatusEvent.php` | New: child EventBehavior with validation |
| `tests/Stubs/Machines/Endpoint/GetEndpoint/GetForwardStartEvent.php` | New: START event for parent (transition idle→delegating) |
| `tests/Routing/GetEndpointTest.php` | New: 14 test cases |
| `docs/laravel-integration/endpoints.md` | Add GET section, update method table, add GET example |
