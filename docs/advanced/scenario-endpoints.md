# Scenario Endpoints

How scenarios integrate with machine HTTP endpoints — QA workflow, request/response format, and file organization.

## QA Workflow

1. QA opens frontend, sees scenario selector (staging only)
2. Endpoint response includes `availableEvents` and `availableScenarios`
3. QA selects a scenario from the dropdown for a specific event
4. QA triggers the event — frontend sends `{ scenario: "...", scenarioParams: {...} }`
5. Machine processes event with scenario overrides active
6. Endpoint returns final state — QA continues manually

A machine can be running normally without any scenario. At **any state**, QA can activate a scenario for the next event.

## Request Format

```http
POST /api/orders/{orderId}/submit
{
    "type": "SubmitOrderEvent",
    "scenario": "at-review-scenario",
    "scenarioParams": {}
}
```

The `scenario` field is the **slug** — the kebab-case version of the scenario class basename (e.g., `AtReviewScenario` → `at-review-scenario`). The `scenarioParams` field contains validated parameters defined in the scenario's `params()` method.

With parameters:

```http
POST /api/orders/{orderId}/review-rejected
{
    "type": "ReviewRejectedEvent",
    "scenario": "at-rejected-scenario",
    "scenarioParams": {
        "reason": "INSUFFICIENT_FUNDS",
        "creditScore": 1200
    }
}
```

## Response Format

When `MACHINE_SCENARIOS_ENABLED=true`, every endpoint response includes an `availableScenarios` field. This field is **state-aware** — it only lists scenarios whose `$source` matches the machine's current state, grouped by event type:

```json
{
    "data": {
        "id": "evt_01HXYZ...",
        "machineId": "order",
        "state": ["order.under_review"],
        "availableEvents": ["ReviewApprovedEvent", "ReviewRejectedEvent"],
        "output": {},
        "isProcessing": false,
        "availableScenarios": {
            "ReviewApprovedEvent": [
                {
                    "slug": "at-approved-scenario",
                    "description": "Fast-forward to approved",
                    "target": "approved",
                    "params": {}
                }
            ],
            "ReviewRejectedEvent": [
                {
                    "slug": "at-rejected-scenario",
                    "description": "Rejection with specific reason",
                    "target": "rejected",
                    "params": {
                        "reason": {
                            "type": "enum",
                            "values": ["GENERAL", "INSUFFICIENT_FUNDS"],
                            "label": "Rejection Reason",
                            "rules": ["required"],
                            "required": true
                        }
                    }
                }
            ]
        }
    }
}
```

### `availableScenarios` Structure

| Key | Type | Description |
|-----|------|-------------|
| Top-level keys | `string` | Event type strings (e.g., `"ReviewApprovedEvent"`) — resolved via `EventBehavior::getType()`, never FQCN |
| `slug` | `string` | Kebab-case identifier (e.g., `"at-approved-scenario"`) — used in `scenario` request field |
| `description` | `string` | Human-readable description from the scenario class |
| `target` | `string` | Final state route the scenario reaches |
| `params` | `object` | Parameter definitions with `type`, `values`, `label`, `rules`, `required` — empty `{}` when no params |

The field is built by `ScenarioDiscovery::groupedByEvent()` which scans the `Scenarios/` directory relative to the machine class. Only scenarios whose `$source` property matches the current state (exact or suffix match) are included.

When `MACHINE_SCENARIOS_ENABLED=false` (default), the `availableScenarios` field is **not present** in the response — zero overhead in production. When enabled but no scenarios match the current state, the field is an empty object `{}`.

### `activeScenario` Field

When a scenario with `continuation()` is active, the response includes an `activeScenario` field:

```json
{
    "data": {
        "id": "evt_01HXYZ...",
        "state": ["order.awaiting_pin"],
        "availableEvents": ["PIN_CONFIRMED"],
        "output": null,
        "isProcessing": false,
        "availableScenarios": {
            "PIN_CONFIRMED": [
                {"slug": "at-report-saved-with-pin", "description": "PIN confirmed — report saved"}
            ]
        },
        "activeScenario": {
            "slug": "at-awaiting-pin-scenario",
            "description": "Findeks — PIN entry required",
            "hasContinuation": true
        }
    }
}
```

| Key | Type | Description |
|-----|------|-------------|
| `slug` | `string` | Kebab-case identifier of the active scenario |
| `description` | `string` | Human-readable description from the scenario class |
| `hasContinuation` | `bool` | Always `true` when present — indicates the scenario will auto-continue |

**`availableScenarios` and `activeScenario` are independent.** Both can appear simultaneously. QA sees:
- "ScenarioA is active and will continue automatically" (`activeScenario`)
- "You can also switch to ScenarioB from here" (`availableScenarios`)

When no scenario with continuation is active, `activeScenario` is not present in the response.

## Scenario Deactivation

When a POST request arrives **without** a `scenario` field, the controller checks if the machine had a previously active scenario (via `scenario_class` column in `machine_current_states`). If so, it clears the columns — the machine returns to normal (non-scenario) behavior:

```http
POST /api/orders/{orderId}/review-approved
{
    "type": "ReviewApprovedEvent"
}
```

No `scenario` field → previous scenario deactivated (clears `scenario_class` and `scenario_params` columns in `machine_current_states`). QA can resume manual testing at any point.

**Exception — continuation scenarios:** When a scenario with `continuation()` is active, sending a request **without** a `scenario` field does **not** deactivate it. Instead, the continuation overrides are applied automatically.

To explicitly deactivate a continuation scenario, you have three options:
1. Send `scenario: null` in the request (explicit opt-out)
2. Send a different scenario slug (switch)
3. Wait for the machine to reach a final state (auto-deactivation)

### Explicit Deactivation with `scenario: null`

To force-deactivate an active continuation scenario, send `scenario: null` in the request payload:

```http
POST /api/orders/{orderId}/confirm-pin
{
    "type": "PIN_CONFIRMED",
    "scenario": null
}
```

This clears `scenario_class` and `scenario_params` from the database. The event is then processed with **real behavior** — no overrides. This is useful when QA wants to exit a continuation mid-flow and test the real implementation.

::: tip `scenario: null` vs omitting `scenario`
- **Omitting `scenario`** (no field in payload): continuation auto-restores and applies overrides
- **`scenario: null`** (field present with null value): continuation deactivated, real behavior used
:::

### Final-State Auto-Deactivation

When the machine reaches a **final state** during continuation execution, the scenario is automatically deactivated — `scenario_class` and `scenario_params` are cleared from the database. No manual deactivation needed.

## Continuation Flow

A continuation scenario spans multiple HTTP requests:

**Request 1 — Initial activation:**
```
POST /endpoint { scenario: "at-awaiting-pin-scenario", type: "REPORT_REQUESTED" }

→ ScenarioPlayer::execute() with plan() overrides
→ Machine reaches target (awaiting_pin)
→ Response: activeScenario present, availableScenarios listed
```

**Request 2, Option A — Continue with active scenario (no slug):**
```
POST /endpoint { type: "PIN_CONFIRMED", payload: { pin: "123456" } }

→ Controller detects active continuation in DB
→ ScenarioPlayer::executeContinuation() with continuation() overrides
→ Machine advances through mocked states → reaches final state
→ Scenario auto-deactivated
→ Response: activeScenario absent
```

**Request 2, Option B — Switch to different scenario:**
```
POST /endpoint { scenario: "at-report-saved-with-pin", type: "PIN_CONFIRMED" }

→ Old scenario deactivated, new scenario activated
→ ScenarioPlayer::execute() with new scenario's plan()
→ Response: new activeScenario (if it has continuation)
```

**Request 2, Option C — Explicit deactivation (scenario: null):**
```
POST /endpoint { type: "PIN_CONFIRMED", scenario: null }

→ Controller sees explicit scenario:null → deactivateScenario()
→ Normal machine->send() — no overrides, real behavior
→ Response: activeScenario absent
```

If the continuation hits another interactive state (no `@continue` entry), the machine pauses and the scenario stays active for a third request, and so on.

## Scenario Switching

When a continuation scenario is active, QA can switch to a different scenario by sending its slug:

```http
POST /api/orders/{orderId}/confirm-pin
{
    "type": "PIN_CONFIRMED",
    "scenario": "at-report-saved-with-pin"
}
```

The old scenario (with its continuation) is replaced by the new scenario. The new scenario runs its `plan()` from the current state. This allows QA to change direction mid-flow without manually deactivating.

## Error Handling

| Condition | HTTP Status | Error |
|-----------|-------------|-------|
| Unknown scenario slug | 422 | `ScenarioFailedException` — scenario not found |
| Machine not at scenario's `$source` state | 422 | `ScenarioFailedException::sourceMismatch()` |
| Request `type` doesn't match scenario's `$event` | 422 | `ScenarioFailedException::eventMismatch()` |
| Target not reached after execution | 422 | `ScenarioTargetMismatchException` |
| Machine is faked (`Machine::fake()`) | 500 | `ScenarioConfigurationException` |

## File Organization

Each machine's scenarios live under its own `Scenarios/` directory:

```
app/Machines/Order/
├── OrderMachine.php
├── Guards/
├── Actions/
└── Scenarios/
    ├── AtPaymentVerificationScenario.php
    ├── AtReviewScenario.php
    └── AtReviewScenario/
        └── Guards/
            └── IsBlacklistedGuardScenario.php
```

`ScenarioDiscovery` finds scenarios by scanning the `Scenarios/` directory relative to the machine class file — no boot-time scanning, no caching needed.
