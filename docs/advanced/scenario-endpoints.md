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

When `MACHINE_SCENARIOS_ENABLED=false` (default), the `availableScenarios` field is **not present** in the response — zero overhead in production.

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
