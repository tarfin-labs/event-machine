# Scenario Endpoints

How scenarios integrate with machine HTTP endpoints — QA workflow, request/response format, scenario routes, and file organization.

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

After any successful transition, the response includes `availableScenarios` grouped by event:

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

## Scenario Routes

When scenarios are enabled, `MachineRouter` registers two additional routes:

| Route | Description |
|-------|-------------|
| `GET {prefix}/scenarios` | List all scenarios for this machine |
| `GET {prefix}/scenarios/{slug}/describe` | Scenario details (identity, params) |

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
