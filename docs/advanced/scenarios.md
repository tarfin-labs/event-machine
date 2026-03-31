# Scenarios

> Behavior overrides activated through existing machine endpoints — enabling QA and product teams to navigate complex state flows in staging without manual multi-step setup.

## What Are Scenarios?

Complex machines like `CarSalesMachine` have deep state hierarchies with parallel child delegations, API integrations, and multi-step guard chains. To test a feature at state `checking_protocol`, a human must:

1. Trigger `START_APPLICATION` with valid models
2. Grant consent, pass eligibility check
3. Wait for `FindeksMachine` to complete (6+ steps, external API calls)
4. Wait for `TurmobMachine` to complete
5. Both parallel regions must finish, guard check passes

This requires 10+ manual steps, 2 child machines, and multiple API calls. Product teams and QA cannot do this in staging without developer assistance.

**Scenarios solve this:** define behavior overrides and delegation outcomes once, activate from existing frontend endpoints via a `scenario` field, arrive at the desired state with a fully functional machine.

The resulting machine is **indistinguishable** from one that arrived at that state organically — real transitions, real event history, real context mutations.

## Quick Start

### 1. Scaffold a scenario

```bash
php artisan machine:scenario AtShipping OrderMachine \
    pending SubmitOrderEvent shipping
```

This analyzes the machine definition, finds the path from source to target, and generates:

`app/Machines/Order/Scenarios/AtShippingScenario.php`

### 2. Review and adjust the generated plan

<!-- doctest-attr: ignore -->
```php
class AtShippingScenario extends MachineScenario
{
    protected string $machine     = OrderMachine::class;
    protected string $source      = 'pending';
    protected string $event       = SubmitOrderEvent::class;
    protected string $target      = 'shipping';
    protected string $description = 'At shipping — payment completed, ready to ship';

    protected function plan(): array
    {
        return [
            // @always guard: skip eligibility check
            'eligibility_check' => [
                IsBlacklistedGuard::class => false,
            ],
            // Child machine: simulate payment completion
            'processing_payment' => '@done',
        ];
    }
}
```

### 3. Enable scenarios in staging

```env
MACHINE_SCENARIOS_ENABLED=true
```

### 4. Activate via endpoint

```http
POST /api/orders/{orderId}/submit
{
    "type": "SubmitOrderEvent",
    "scenario": "at-shipping-scenario"
}
```

The machine processes the event with overrides active, arrives at `shipping`, and returns the final state.

### 5. Validate

```bash
php artisan machine:scenario-validate
```

## MachineScenario Class

Every scenario extends `MachineScenario` with 5 identity properties and an optional `plan()` method:

<!-- doctest-attr: ignore -->
```php
class AtShippingScenario extends MachineScenario
{
    /** Which machine this scenario targets. */
    protected string $machine = OrderMachine::class;

    /** State the machine must be in BEFORE the event. */
    protected string $source = 'pending';

    /** Event that triggers this scenario. */
    protected string $event = SubmitOrderEvent::class;

    /** Where the machine should end up after execution. */
    protected string $target = 'shipping';

    /** Human-readable description shown in endpoint responses. */
    protected string $description = 'At shipping — payment completed';

    protected function plan(): array
    {
        return [
            'eligibility_check' => [
                IsBlacklistedGuard::class => false,
            ],
            'processing_payment' => '@done',
        ];
    }
}
```

### Identity Properties

| Property | Type | Example | Purpose |
|----------|------|---------|---------|
| `$machine` | `class-string` | `OrderMachine::class` | Which machine this scenario targets |
| `$source` | `string` | `'pending'` | Full state route — where the machine is BEFORE the event |
| `$event` | `string` | `SubmitOrderEvent::class` | Which event triggers this scenario |
| `$target` | `string` | `'shipping'` | Full state route — where the machine should end up |
| `$description` | `string` | `'At shipping'` | Human-readable, shown in endpoint responses |

Multiple scenarios can share the same `(source, event)` with different targets. The **slug** (derived from the class name) disambiguates.

### Full State Route Requirement

`$source`, `$target`, and `plan()` keys must use the **full state route** (dot-notation path):

<!-- doctest-attr: ignore -->
```php
// Bad — ambiguous, could exist in multiple parallel regions
protected string $source = 'awaiting_confirmation';

// Good — unambiguous full path
protected string $source = 'checkout.payment.awaiting_confirmation';
```

For root-level states (e.g., `pending`, `shipping`), the leaf key IS the full route.

### Properties vs Methods

Properties are the default. Methods can be used when computed values are needed:

<!-- doctest-attr: ignore -->
```php
// Default — property assignment (recommended)
protected string $machine = OrderMachine::class;

// Alternative — method override (when computation is needed)
public function machine(): string
{
    return config('machines.order.class');
}
```

The base class checks: method first, property fallback.

### `@start` — Transient Initial States

Child machines often have transient initial states (`idle → @always → ...`). There's no external event to send — the machine auto-starts. Use `MachineScenario::START` as the event:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;

class AtReportSavedFromStartScenario extends MachineScenario
{
    protected string $machine = FindeksMachine::class;
    protected string $source  = 'idle';                   // Initial state
    protected string $event   = MachineScenario::START;   // Special: no event sent
    protected string $target  = 'report_saved';

    protected function plan(): array
    {
        return [
            'checking_existing_report' => [
                HasExistingReportGuard::class => false,
            ],
            'querying_phones' => '@done',
            // ...
        ];
    }
}
```

When `$event` is `@start`, ScenarioPlayer creates a fresh machine and processes the `@always` chain with overrides active. No `$machine` parameter needed:

<!-- doctest-attr: ignore -->
```php
$scenario = new AtReportSavedFromStartScenario();
$player   = new ScenarioPlayer($scenario);
$state    = $player->execute(); // Creates machine internally
```

## Writing plan()

Every `plan()` key is a **full state route**. The **value type** determines what happens at that state:

| Value type | Detection | Meaning |
|-----------|-----------|---------|
| `array` without `'outcome'` key | `is_array($value) && !isset($value['outcome'])` | Behavior overrides |
| `string` starting with `@` | `str_starts_with($value, '@')` | Delegation outcome |
| `array` with `'outcome'` key | `isset($value['outcome'])` | Delegation outcome with output and/or guard overrides |
| `class-string<MachineScenario>` | `is_subclass_of($value, MachineScenario::class)` | Child scenario reference |

### Behavior Overrides

For states without delegation — override guards, actions, calculators, and outputs:

<!-- doctest-attr: ignore -->
```php
protected function plan(): array
{
    return [
        'routing' => [
            OrderTotalCalculator::class => ['customer' => $mockCustomer],
            HasAgreedToTermsGuard::class => true,
        ],
        'eligibility_check' => [
            IsBlacklistedGuard::class => false,
        ],
    ];
}
```

Both class-based behaviors (`ClassName::class => value`) and inline behaviors (`'camelCaseKey' => value`) are supported.

#### Override Value Forms

| Behavior | Bool | Array (context write) | Closure | Class |
|----------|------|----------------------|---------|-------|
| **Guard** | Return value | -- | Must return `bool` | `GuardScenarioBehavior` |
| **Action** | -- | Key-value to context | `void` | `ActionScenarioBehavior` |
| **Calculator** | -- | Key-value to context | `void` | `CalculatorScenarioBehavior` |
| **Output** | -- | Return as output | Must return `mixed` | `OutputScenarioBehavior` |

**Guards:**

<!-- doctest-attr: ignore -->
```php
'eligibility_check' => [
    // Bool shorthand
    IsBlacklistedGuard::class => false,

    // Closure with DI
    HasAgreedToTermsGuard::class => function (ContextManager $ctx): bool {
        return $ctx->get('customer')->hasAgreedToTerms();
    },

    // Reusable scenario behavior class
    IsProfileCompleteGuard::class => IsProfileCompleteGuardScenario::class,
],
```

**Actions:**

<!-- doctest-attr: ignore -->
```php
'calculating_prices' => [
    // Array shorthand — key-value pairs written to context
    ProcessReviewAction::class => ['reviewApproved' => true],

    // Closure with DI
    CreateOrderAction::class => function (ContextManager $ctx) {
        $ctx->set('orderId', 'ORD-' . Str::random());
    },

    // Reusable scenario behavior class
    CalculatePricesAction::class => CalculatePricesActionScenario::class,
],
```

**Calculators:**

<!-- doctest-attr: ignore -->
```php
'routing' => [
    // Array shorthand — pre-set context values
    OrderTotalCalculator::class => [
        'customer' => $mockCustomer,
        'merchant' => $mockMerchant,
    ],

    // Closure with DI
    OrderTotalCalculator::class => function (ContextManager $ctx) {
        $ctx->set('merchant', Merchant::find(7));
    },
],
```

**Outputs:**

<!-- doctest-attr: ignore -->
```php
'approved' => [
    // Array shorthand — return as output data
    OrderSummaryOutput::class => ['orderId' => 'ORD-001', 'status' => 'approved'],
],
```

#### Same Behavior, Different Values Per State

When the same behavior appears under multiple states with different values, ScenarioPlayer uses the last state's value (last-wins policy):

<!-- doctest-attr: ignore -->
```php
'routing' => [
    HasAgreedToTermsGuard::class => true,
],
'info_checking' => [
    HasAgreedToTermsGuard::class => false,
],
```

### Delegation Outcomes

For states with `machine` or `job` delegation — declare what the child/job produces:

<!-- doctest-attr: ignore -->
```php
protected function plan(): array
{
    return [
        // Simple outcome
        'payment_verification.payment.processing' => '@done.completed',
        'payment_verification.identity.checking' => '@done',

        // Job actor outcome
        'querying_phones' => '@done',
    ];
}
```

**Outcome with output data:**

<!-- doctest-attr: ignore -->
```php
'payment_verification.payment.processing' => [
    'outcome' => '@done.completed',
    'output'  => ['transactionId' => 'TXN-001', 'amount' => 9999],
],
```

**Outcome with guard overrides for `@done` transitions:**

<!-- doctest-attr: ignore -->
```php
'polling' => [
    'outcome' => '@done',
    IsOtpRequiredGuard::class => true,
],
```

| Format | Example | When to use |
|--------|---------|-------------|
| Simple string | `'@done.report_saved'` | Parent only cares about routing |
| With output | `['outcome' => '@done', 'output' => [...]]` | Parent's `@done` action reads output |
| With guard override | `['outcome' => '@done', Guard::class => true]` | `@done` transition has guards |
| Failure | `'@fail'` | Test failure path |
| Timeout | `'@timeout'` | Test timeout path |

**How it works:**
1. ScenarioPlayer intercepts delegation dispatch
2. Does NOT run the delegated machine/job
3. Simulates the completion by sending the declared outcome to the parent
4. Parent's `@done`/`@fail`/`@timeout` transition fires with the declared output

### Child Machine Scenarios

Instead of an outcome, reference a **child machine's own scenario** — the child runs and may pause at an interactive state:

<!-- doctest-attr: ignore -->
```php
protected function plan(): array
{
    return [
        'eligibility_check' => [
            IsBlacklistedGuard::class => false,
        ],
        'payment_verification.identity.checking' => '@done',
        'payment_verification.payment.processing' => AtAwaitingOtpScenario::class,
    ];
}
```

The child scenario is a standalone `MachineScenario` for the child machine:

<!-- doctest-attr: ignore -->
```php
class AtAwaitingOtpScenario extends MachineScenario
{
    protected string $machine     = PaymentMachine::class;
    protected string $source      = 'idle';
    protected string $event       = 'MACHINE_START';
    protected string $target      = 'awaiting_otp';
    protected string $description = 'PaymentMachine at awaiting_otp';

    protected function plan(): array
    {
        return [
            'checking_existing_payment' => [
                HasExistingPaymentGuard::class => false,
            ],
            'authorizing' => '@done',
            'processing'  => '@done',
            'confirming' => [
                'outcome' => '@done',
                IsOtpRequiredGuard::class => true,
            ],
        ];
    }
}
```

**What happens:**
1. ScenarioPlayer intercepts child machine dispatch
2. Creates the child machine, applies the child scenario's `plan()` overrides
3. Child reaches `awaiting_otp` — interactive state, waits for input
4. Child **pauses** — parent stays at `payment_verification.payment.processing`
5. Forward endpoints become active — QA can send events to the child

| plan() value | Child state | Forward endpoints |
|-------------|-------------|-------------------|
| `'@done.completed'` | Completed (simulated) | **Not active** — child didn't run |
| `AtAwaitingOtpScenario::class` | Running, paused | **Active** — child is real, waiting for input |

### @continue — Multi-Step Scenarios

When a scenario needs to traverse **multiple interactive states** in a single activation, `@continue` auto-sends events at intermediate stops:

<!-- doctest-attr: ignore -->
```php
class AtAllocationScenario extends MachineScenario
{
    protected string $machine     = OrderMachine::class;
    protected string $source      = 'pending';
    protected string $event       = SubmitOrderEvent::class;
    protected string $target      = 'allocation';
    protected string $description = 'Full journey — all checks passed, review approved';

    protected function plan(): array
    {
        return [
            'eligibility_check' => [
                IsBlacklistedGuard::class => false,
            ],
            'payment_verification.payment.processing'  => '@done.completed',
            'payment_verification.identity.checking' => '@done',
            'payment_verification' => [
                'isPaymentRegionCompletedGuard' => true,
            ],
            // Machine arrives at under_review — interactive state.
            // Auto-send ReviewApprovedEvent to continue toward target.
            'under_review' => [
                '@continue' => ReviewApprovedEvent::class,
                ProcessReviewAction::class => ['reviewApproved' => true],
            ],
        ];
    }
}
```

**Flow:**

```
1. QA sends SubmitOrderEvent with scenario
2. Machine: pending → eligibility_check → payment_verification (parallel)
3. Delegations simulated → under_review
4. ScenarioPlayer: @continue → auto-send ReviewApprovedEvent
5. Machine: under_review → allocation
6. ScenarioPlayer: no @continue at allocation → stop
7. Target validation: machine at allocation === $target
```

**`@continue` formats:**

<!-- doctest-attr: ignore -->
```php
// Event class only — no payload
'@continue' => ReviewApprovedEvent::class,

// Event class + payload
'@continue' => [ReviewApprovedEvent::class, 'payload' => ['source' => 'auto']],

// With scenario params
'@continue' => [OtpSubmittedEvent::class, 'payload' => [
    'otp' => $this->param('otp', '123456'),
]],
```

**`@continue` + behavior overrides in the same state:**

<!-- doctest-attr: ignore -->
```php
'under_review' => [
    '@continue'                    => ReviewApprovedEvent::class,
    ProcessReviewAction::class     => ['reviewApproved' => true],
    HasValidDocumentsGuard::class  => true,
],
```

Behavior overrides are registered first, then `@continue` fires.

**Rules:**
- `@continue` is only valid on **non-delegation states**
- ScenarioPlayer loops until no `@continue` match or `max_transition_depth` is reached
- If a `@continue` event fails, `ScenarioFailedException` is thrown

### Parallel States

Specify each region's delegation separately. Only regions you mention are controlled — unmentioned delegations run normally:

<!-- doctest-attr: ignore -->
```php
protected function plan(): array
{
    return [
        'eligibility_check' => [
            IsBlacklistedGuard::class => false,
        ],
        'payment_verification.payment.processing'  => '@done.completed',
        'payment_verification.identity.checking' => '@done',
        'payment_verification' => [
            'isPaymentRegionCompletedGuard' => true,
        ],
    ];
}
```

**Mix outcomes and scenarios:**

<!-- doctest-attr: ignore -->
```php
// Payment pauses at OTP, identity check completes
'payment_verification.payment.processing'  => AtAwaitingOtpScenario::class,
'payment_verification.identity.checking' => '@done',

// Payment completes, identity check fails → test @fail path
'payment_verification.payment.processing'  => '@done.completed',
'payment_verification.identity.checking' => '@fail',
```

### Fire-and-Forget Delegation

Fire-and-forget delegation (machine with `queue` + no `@done`, or job with `target`) does NOT need entries in `plan()`. The parent transitions immediately. In scenario mode, fire-and-forget dispatches are suppressed.

## Scenario Parameters

Scenarios can accept parameters from the frontend via `params()` and `param()`:

<!-- doctest-attr: ignore -->
```php
class AtRejectedScenario extends MachineScenario
{
    protected string $machine     = OrderMachine::class;
    protected string $source      = 'under_review';
    protected string $event       = ReviewRejectedEvent::class;
    protected string $target      = 'rejected';
    protected string $description = 'Order rejected with specific reason';

    protected function params(): array
    {
        return [
            // Rich definition — frontend renders a dropdown
            'reason' => [
                'type'   => 'enum',
                'values' => ['GENERAL', 'INSUFFICIENT_FUNDS', 'CREDIT_SCORE_LOW'],
                'label'  => 'Rejection Reason',
                'rules'  => ['required'],
            ],
            // Plain rules — frontend renders a generic input
            'creditScore' => ['integer', 'min:0', 'max:1900'],
        ];
    }

    protected function plan(): array
    {
        return [
            'allocation' => [
                RejectAction::class => [
                    'rejectionReason' => $this->param('reason'),
                    'creditScore'     => $this->param('creditScore', 750),
                ],
            ],
        ];
    }
}
```

### Parameter Definition Format

Each `params()` entry is either a **plain array** (validation rules only) or an **assoc array** (rich definition):

| Format | Detection | Example |
|--------|-----------|---------|
| Plain rules | Sequential array | `['required', 'string', 'max:500']` |
| Rich definition | Assoc array with `rules` key | `['type' => 'enum', 'values' => [...], 'rules' => ['required']]` |

**Rich definition keys:**

| Key | Required | Description |
|-----|----------|-------------|
| `rules` | Yes | Laravel validation rules array |
| `type` | No | Hint for frontend widget: `enum`, `number`, `string`, `boolean` |
| `values` | No | Allowed values (for `enum` type) |
| `label` | No | Human-readable label |
| `min` / `max` | No | Range constraints (for `number` type) |

Parameters are sent by the frontend in `scenarioParams` and validated before `plan()` is called.

## Naming Conventions

| Type | Pattern | Example |
|------|---------|---------|
| Machine scenario | `At{Target}Scenario` | `AtReviewScenario` |
| Behavior scenario | `{OriginalName}Scenario` | `HasAgreedToTermsGuardScenario` |

**Machine scenario names** are descriptive — typically the target state. When multiple scenarios target the same state via different paths, disambiguate:

- `AtPaymentVerificationScenario` — unique target, sufficient
- `AtPaymentVerificationViaConsentScenario` — same target, different source/event

**Behavior scenario names** mirror the original behavior name with `Scenario` suffix. This enables search: `HasAgreedToTerms` finds both `HasAgreedToTermsGuard` and `HasAgreedToTermsGuardScenario`.

## Endpoint Integration

### QA Workflow

1. QA opens frontend, sees scenario selector (staging only)
2. Endpoint response includes `availableEvents` and `availableScenarios`
3. QA selects a scenario from the dropdown for a specific event
4. QA triggers the event — frontend sends `{ scenario: "...", scenarioParams: {...} }`
5. Machine processes event with scenario overrides active
6. Endpoint returns final state — QA continues manually

A machine can be running normally without any scenario. At **any state**, QA can activate a scenario for the next event.

### Request Format

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

### Response Format

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

### Scenario Routes

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

## Scaffold Command

```bash
php artisan machine:scenario
    {name}       # Scenario class name (Scenario suffix auto-added)
    {machine}    # Machine class FQCN
    {source}     # Source state route (full or partial)
    {event}      # Triggering event (class FQCN or event type string)
    {target}     # Target state route (full or partial)
    {--dry-run}  # Print without writing
    {--force}    # Overwrite existing file
    {--path=0}   # Select path by index when multiple paths exist
```

**Example:**

```bash
php artisan machine:scenario AtAllocation OrderMachine \
    pending SubmitOrderEvent allocation
```

The command:
1. Resolves the path from source to target via BFS
2. Classifies each intermediate state (transient, delegation, interactive, parallel)
3. Generates appropriate `plan()` entries with TODO comments
4. Writes the file to `Scenarios/` next to the machine class

### Multiple Paths

When BFS finds multiple paths, the command presents them:

```
Found 2 paths from pending to allocation:

  [0] pending → eligibility_check → payment_verification → under_review → allocation
      3 overrides, 2 delegation outcomes, 1 @continue

  [1] pending → eligibility_check → manual_review → allocation
      2 overrides, 0 delegation outcomes, 0 @continue

Use --path=N to select. Using path [0].
```

### Deep Target (Cross-Delegation)

When the target is inside a child machine, use `{region}.{childState}` syntax:

```bash
php artisan machine:scenario AtPaymentDateCorrection OrderMachine \
    pending SubmitOrderEvent payment.awaiting_date_correction
```

The command resolves the delegation boundary, discovers matching child scenarios, and references them in the parent's `plan()`. If no child scenario exists, it suggests the command to create one.

### Generated Output

The scaffolder generates classification-specific entries:

| Classification | Generated entry |
|---------------|----------------|
| **Transient** | `'route' => [Guard::class => false]` with `// TODO: adjust` |
| **Delegation** | `'route' => '@done'` with `// Available: @done.X, @fail, @timeout` |
| **Parallel** | Region outcomes + `@done` guard override |
| **Interactive** | `'route' => ['@continue' => Event::class]` with `// Also: OtherEvent` |

When a `@continue` event has `EventBehavior::rules()`, payload fields are extracted:

<!-- doctest-attr: ignore -->
```php
'awaiting_report_request' => [
    '@continue' => [ReportRequestedEvent::class, 'payload' => [
        'phone'   => '', // TODO: required (string)
        'queryId' => '', // TODO: required (string)
    ]],
],
```

## Validation Command

```bash
php artisan machine:scenario-validate
    {machine?}        # Specific machine (optional — all if omitted)
    {--scenario=}     # Single scenario by class or slug
```

### What It Validates

**Level 1 — Static validation:**

| Check | Example error |
|-------|---------------|
| `$machine` class exists | `Machine class not found: OrderMachine` |
| `$source` exists in machine | `Source state 'awaiting_start' not found` |
| `$target` exists in machine | `Target state 'allocation' not found` |
| `$target` is not transient | `Target 'eligibility_check' is transient (@always)` |
| `$event` valid from `$source` | `Event not available from source` |
| All `plan()` routes exist | `State route 'eligibilty_check' not found` |
| Behavior classes exist | `Guard class 'IsBlacklistedGard' not found` |
| Delegation outcomes on delegation states only | `Has outcome '@done' but is not a delegation state` |
| `@continue` on non-delegation states only | `Has @continue but is a delegation state` |
| Child scenario machine matches delegation | `AtAwaitingOtpScenario targets PaymentMachine but delegates to IdentityCheckMachine` |

**Level 2 — Path validation:**

| Check | Example error |
|-------|---------------|
| Path exists from source to target | `No path from 'idle' to 'allocation' via 'StartEvent'` |
| `@continue` events lead toward target | Directional check |
| Deep target child scenario exists | `No scenario found for PaymentMachine targeting 'awaiting_otp'` |

### Output

```
Validating scenarios...

OrderMachine (5 scenarios)
  ✓ AtPaymentVerificationScenario         pending → payment_verification
  ✓ AtReviewScenario                      pending → under_review
  ✗ AtAllocationScenario                  pending → allocation
    State route 'under_reviews' not found in machine definition
  ✓ AtRejectedScenario                    under_review → rejected

4 passed, 1 failed
```

Exit code 0 = all valid, exit code 1 = failures found.

### CI/CD Integration

```bash
php artisan machine:scenario-validate --ansi
```

Add to your quality gate or CI pipeline to catch broken scenarios before they reach staging.

## Environment Gating

Scenarios are disabled by default. Enable in staging only:

```php
// config/machine.php
'scenarios' => [
    'enabled' => env('MACHINE_SCENARIOS_ENABLED', false),
],
```

| Gating point | Behavior when disabled |
|-------------|----------------------|
| `ScenarioPlayer` | Throws `ScenariosDisabledException` |
| `MachineRouter` | Scenario routes not registered |
| `MachineController::buildResponse()` | `availableScenarios` omitted |
| `MachineController::executeEndpoint()` | `scenario` field silently ignored |
| `Machine::create()` restoration | `scenario_class` query skipped |

Zero overhead in production.

## Async Propagation

Scenario overrides live in the Laravel container — process-scoped. When async jobs (parallel regions, queued listeners, child completion) run in separate processes, the scenario must propagate.

**Solution:** `machine_current_states.scenario_class` and `scenario_params` columns. Written during scenario activation, read during `Machine::create()` restoration when `scenarios.enabled=true`. The restored job registers scenario overrides in its own container, then processes its event normally.

### Lifecycle

1. Machine running normally → `scenario_class = null`
2. QA sends event with `scenario` → `scenario_class = 'AtReviewScenario'`
3. Async jobs restore machine → find `scenario_class` → hydrate → register overrides
4. QA sends next event WITHOUT scenario → `scenario_class = null` → real behavior resumes
5. QA sends next event with DIFFERENT scenario → new overrides replace old

## Engine Feature Reference

| Feature | Scenario interaction |
|---------|---------------------|
| **Timers (after/every)** | No special handling — scenario replay is synchronous, timers never fire |
| **Scheduled events** | No special handling — replay is synchronous |
| **Queued listeners** | Overrides propagate via `scenario_class` in DB |
| **ValidationGuardBehavior** | Override return value determines pass/fail |
| **Machine locking** | No change — existing lock semantics apply |
| **Machine::fake()** | Incompatible — `ScenarioConfigurationException` if machine is faked |
| **Event history** | Real event history created — machine indistinguishable from organic |
| **Event archival** | Transparent — no special handling |
| **should_persist** | `scenario_class` column only written when `should_persist=true` |
| **Entry/exit actions** | Execute normally — overrides apply same as transition actions |
| **Event bubbling** | Override key must match state route where transition is **defined** |
| **raise() / sendTo()** | Work normally — scenarios scoped to single machine instance |
| **computedContext()** | Not overridable — override actions that populate dependent context fields |
| **Lock contention** | Existing lock handling — POST returns 423 Locked |
| **Machine::query()** | Filter by `scenario_class` column on `machine_current_states` |
| **Transient as target** | Invalid — `$target` must be a settleable state |
| **Path coverage** | Scenario-driven paths recorded normally by `PathCoverageTracker` |
| **Fire-and-forget** | Dispatches suppressed during scenario mode |
| **Parallel as target** | Target `'payment_verification'` matches child routes like `payment_verification.payment.completed` — segment containment check |
| **`@start` event** | Creates fresh machine and processes `@always` chain — for transient initial states |

## Error Handling

| Exception | When |
|-----------|------|
| `ScenariosDisabledException` | `MACHINE_SCENARIOS_ENABLED=false` |
| `ScenarioConfigurationException` | Invalid state route, delegation outcome on non-delegation state, missing properties, invalid params, machine is faked |
| `ScenarioFailedException` | Guard rejection during replay, `@continue` event rejected |
| `ScenarioTargetMismatchException` | Machine did not reach `$target` after execution |
| `NoScenarioPathFoundException` | Scaffold command: no path from source to target |
| `AmbiguousScenarioPathException` | Scaffold command: multiple paths exist |

When a `MissingMachineContextException` is thrown during replay, it is enriched with a hint:

```
`customer` is missing in context.

Hint: add a context override in the plan() for the relevant state.
```
