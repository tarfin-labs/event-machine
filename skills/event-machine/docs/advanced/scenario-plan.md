# Writing plan()

How to define behavior overrides, delegation outcomes, child scenarios, @continue steps, and parallel state handling in your scenario's `plan()` method.

Every `plan()` key is a **full state route**. The **value type** determines what happens at that state:

| Value type | Detection | Meaning |
|-----------|-----------|---------|
| `array` without `'outcome'` key | `is_array($value) && !isset($value['outcome'])` | Behavior overrides |
| `string` starting with `@` | `str_starts_with($value, '@')` | Delegation outcome |
| `array` with `'outcome'` key | `isset($value['outcome'])` | Delegation outcome with output and/or guard overrides |
| `class-string<MachineScenario>` | `is_subclass_of($value, MachineScenario::class)` | Child scenario reference |

## Behavior Overrides

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

### Override Value Forms

| Behavior | Bool | Array (context write) | Closure | Class |
|----------|------|----------------------|---------|-------|
| **Guard** | Return value | n/a | Must return `bool` | `GuardScenarioBehavior` |
| **Action** | n/a | Key-value pairs written to context | `void` | `ActionScenarioBehavior` |
| **Calculator** | n/a | Key-value pairs written to context | `void` | `CalculatorScenarioBehavior` |
| **Output** | n/a | Returned as output data | Must return `mixed` | `OutputScenarioBehavior` |

`n/a` = combination not supported for that behavior type.

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

### Same Behavior, Different Values Per State

When the same behavior appears under multiple states with different values, ScenarioPlayer uses the last occurrence in `plan()` declaration order (last-wins policy). This is rarely needed — it only matters when the same guard or action runs at multiple intermediate states along the path:

<!-- doctest-attr: ignore -->
```php
'routing' => [
    HasAgreedToTermsGuard::class => true,
],
'info_checking' => [
    HasAgreedToTermsGuard::class => false,
],
```

## Delegation Outcomes

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
| Callable | `['outcome' => fn(ContextManager $c) => '...']` | Runtime-conditional outcome |

**How it works:**
1. ScenarioPlayer intercepts delegation dispatch
2. Does NOT run the delegated machine/job
3. Simulates the completion by sending the declared outcome to the parent
4. Parent's `@done`/`@fail`/`@timeout` transition fires with the declared output

### Callable Outcome

When the outcome depends on runtime data (e.g., a PIN entered by QA), use a `Closure` instead of a static string. The Closure uses `InvokableBehavior` parameter injection — type-hint what you need:

<!-- doctest-attr: ignore -->
```php
'confirming_pin' => [
    'outcome' => function (ContextManager $context): string {
        $pin         = $context->pin;
        $expectedPin = now()->format('dmy');  // DDMMYY
        return $pin === $expectedPin ? '@done' : '@fail';
    },
    IsPinRetryableGuard::class => true,  // applied when @fail routes
],
```

The Closure runs at delegation time, after entry actions have populated the context. Injectable parameters: `ContextManager`, `State`, `EventBehavior`, `EventCollection`.

Must return a valid outcome string: `'@done'`, `'@done.{state}'`, `'@fail'`, or `'@timeout'`.

Guard and action overrides in the same array (like `IsPinRetryableGuard::class => true` above) are extracted and registered as behavior overrides, so they take effect when `@fail`/`@done` routing evaluates guards.

## Child Machine Scenarios

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
    protected string $event       = MachineScenario::START;
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

### Async Children (with `'queue:'` delegation)

Child scenarios apply transparently to both sync and async (queued) child machines. The dispatch site reads the active child scenario from `ScenarioPlayer` and threads it to `ChildMachineJob`, which activates the scenario context in the worker process before the child boots.

::: warning Requires 9.10.3+
Earlier versions silently dropped the child scenario at dispatch time — async children booted without scenario context and ran full I/O. If you see a queued child making real external calls despite a scenario plan referencing it, upgrade to 9.10.3 or later.
:::

**Decision rule — inline outcome vs child scenario class:**

| You want… | Use |
|-----------|-----|
| Skip the child entirely; pretend it returned X | Inline outcome (`['outcome' => '@done.X', 'output' => [...]]`) — no child runs, no DB rows, no queue dispatch |
| Walk the child's state graph but mock its leaf actions | Child scenario class (`AtSomeStateScenario::class`) — child runs with overrides, may pause at interactive states |

The inline form is faster and stricter (no child code path exercised). The class form is the right choice when the child's own logic — its `@always` chain, its guards, its parallel regions — is part of what you want the QA scenario to verify.


## @continue — Multi-Step Scenarios

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

### Closure Payload

When the `@continue` event's payload depends on context populated by earlier transitions (typically by an action that ran during the trigger event), use a `Closure` instead of a static array. The Closure is invoked at `@continue` dispatch time with `InvokableBehavior` parameter injection — same DI semantics as the [Callable Outcome](#callable-outcome) in delegation states:

<!-- doctest-attr: ignore -->
```php
'ready' => [
    '@continue' => [CarSalesApplicationStartedEvent::class, 'payload' => function (CarSalesContext $ctx): array {
        return [
            'tckn'      => $ctx->tckn,        // populated by the trigger event's action
            'phone'     => $ctx->phone,
            'birthdate' => '1990-01-01',
        ];
    }],
],
```

Injectable parameters (same as Callable Outcome): `ContextManager` (or a typed subclass), `State`, `EventBehavior`, `EventCollection`. The Closure must return `array<string, mixed>` — returning anything else throws `ScenarioConfigurationException`.

**When to use vs. context override:** if the values are **already in context** (written by trigger-event actions), reach for a Closure. If they're test fixtures that should pre-populate context regardless of any action, prefer a context-write override on the source state — it sidesteps the trigger-event chain entirely.

### Parallel @continue

`@continue` works inside parallel states, but two rules apply:

1. **Declare `@continue` on leaf states inside the regions, not on the parallel parent.** The matcher uses suffix matching against active route paths, and the parent path is always a *prefix* of every active route — never a suffix. Putting `@continue` on the parallel parent state silently does nothing in older builds; from event-machine 9.10.1 onwards, `machine:scenario-validate` rejects it with a clear error.

2. **Fire the parent's transition event from a leaf, not from the parent.** When the parallel parent has a guarded transition that depends on every region reaching its final state (e.g. `isReadyForSubmissionGuard` checking `region_a.completed AND region_b.completed`), put the parent event's `@continue` on the *last* leaf in one of the regions. The player walks regions in round-robin order, so by the time it lands on that leaf, the other regions will already have advanced through their own `@continue`s.

<!-- doctest-attr: ignore -->
```php
// Parallel: 'data_collection' has two regions (retailer + customer_info).
// Both must reach final state before the parent's ApplicationSubmittedEvent
// (guarded by isReadyForSubmissionGuard) is accepted.
return [
    // Region: customer_info — drive to completed
    'data_collection.customer_info.under_review' => [
        '@continue' => CustomerInfoSubmittedEvent::class,
    ],

    // Region: retailer — drive to payment_option_selected (its terminal leaf)
    'data_collection.retailer.awaiting_vehicle_and_pricing' => [
        '@continue' => [VehicleAndPricingSubmittedEvent::class, 'payload' => [/*...*/]],
    ],
    'data_collection.retailer.calculating_prices' => '@done.done',
    'data_collection.retailer.awaiting_payment_options' => [
        '@continue' => [PaymentOptionsSelectedEvent::class, 'payload' => [/*...*/]],
    ],

    // Parent event from a region's final state — by the time the round-robin
    // reaches customer_info.completed, retailer is already at payment_option_selected,
    // so isReadyForSubmissionGuard passes.
    'data_collection.customer_info.completed' => [
        '@continue' => ApplicationSubmittedEvent::class,
    ],

    // ❌ Do NOT do this — parallel parent @continue never matches:
    // 'data_collection' => ['@continue' => ApplicationSubmittedEvent::class],
];
```

**How the player walks parallel regions:** every iteration, the player checks each active route in round-robin order (starting one position past the route that fired last). This guarantees fairness — no region can starve others by having more `@continue`s. If a fired event's guard fails and the active configuration doesn't change, the loop stops immediately rather than looping until `max_transition_depth`.

### Selective Pause

Intentionally omitting `@continue` from an interactive state creates a **selective pause** — the scenario loop stops, the machine stays at that state, and QA interacts with the real endpoint. After QA acts, the continuation overrides resume from the next state.

This is a deliberate design choice: the scenario designer selects which states QA must interact with (real behavior) and which are automated (via `@continue`).

**Example:** In a Findeks flow, QA must enter a real PIN at `awaiting_pin` (no `@continue`), but the subsequent `confirming_pin → polling → saving_report` chain is automated by continuation overrides.

## Parallel States

Specify each region's delegation separately. Only regions you mention are controlled — unmentioned delegations execute real delegation (child machine or job runs via queue, requiring external services to be available in staging):

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

## Fire-and-Forget Delegation

Fire-and-forget delegation (machine with `queue` + no `@done`, or job with `target`) does NOT need entries in `plan()`. The parent transitions immediately past the fire-and-forget state. In scenario mode, the actual dispatch is suppressed — no child job/machine runs:

<!-- doctest-attr: ignore -->
```php
// Machine config — fire-and-forget: has queue, no @done
'sending_notification' => [
    'job'   => SendNotificationJob::class,
    'queue' => 'notifications',
    // No @done — parent continues immediately
],

// Scenario plan — no entry needed for sending_notification
protected function plan(): array
{
    return [
        'checking_eligibility' => [IsEligibleGuard::class => false],
        // 'sending_notification' NOT listed — parent skips it automatically
    ];
}
```

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

## Continuation — Multi-Request Flows

When a scenario's `$target` is an **interactive state** (QA will send more events after arriving), the scenario needs to control what happens on subsequent requests. This is what `continuation()` is for.

### When to Use

Use `continuation()` when:
- The target state expects user input (interactive)
- Subsequent events after the target trigger delegation states or guard-protected transitions that need overrides
- Without overrides, the next request would hit real external services

::: warning Missing continuation is a silent bug
If your target state has event handlers that lead to delegations (retry buttons, resend actions, next-step submissions), the user action will dispatch a real job/machine without scenario interception. The symptom is subtle: the HTTP response returns successfully but a real async dispatch is in flight. See [When continuation() is required](/advanced/scenarios#_6-add-continuation-for-interactive-targets) for detection techniques.
:::

### Why a Separate Method

The same state can appear in both Phase 1 (reaching the target) and Phase 2 (after the target) with **different overrides**. PHP arrays cannot have duplicate keys, so a separate method cleanly separates the phases.

**Example:** In a Findeks flow, the `polling` state is visited twice:
- **Phase 1 (plan):** `IsPinRequiredGuard => true` — PIN is required, machine goes to `awaiting_pin`
- **Phase 2 (continuation):** `IsPinRequiredGuard => false` — PIN confirmed, machine proceeds to `saving_report`

### Before/After

**Before (two-scenario workaround):**

<!-- doctest-attr: ignore -->
```php
// Scenario 1: reach awaiting_pin
class AtAwaitingPinScenario extends MachineScenario { ... }

// Scenario 2: QA must manually activate this before sending PIN_CONFIRMED
class AtReportSavedWithPinScenario extends MachineScenario { ... }
```

QA has to know about both scenarios, select the second one at the right time.

**After (single scenario with continuation):**

<!-- doctest-attr: ignore -->
```php
class AtAwaitingPinScenario extends MachineScenario
{
    protected string $source = 'awaiting_report_request';
    protected string $event  = ReportRequestedEvent::class;
    protected string $target = 'awaiting_pin';

    protected function plan(): array
    {
        return [
            'polling' => [
                'outcome'                => '@done',
                IsPinRequiredGuard::class => true,  // Phase 1: PIN required
            ],
        ];
    }

    protected function continuation(): array
    {
        return [
            'confirming_pin' => '@done',
            'polling'        => [
                'outcome'                => '@done',
                IsPinRequiredGuard::class => false,  // Phase 2: PIN done
            ],
            'saving_report' => '@done',
        ];
    }
}
```

QA activates one scenario. After reaching `awaiting_pin`, subsequent requests automatically use continuation overrides.

### Continuation Format

`continuation()` uses the same format as `plan()` — every key is a full state route, values follow the same detection table (behavior overrides, delegation outcomes, `@continue` directives, child scenario references).

### Deactivation

The continuation scenario is automatically deactivated when:
- The machine reaches a **final state** during continuation execution
- QA sends a request with a **different scenario slug** (new scenario replaces old)
- QA sends a request with explicit empty scenario (normal behavior resumes)

If the continuation hits another interactive state (no `@continue` entry), the machine pauses and the scenario stays active for the next request.

## Pitfalls

Common mistakes when writing scenarios. Each of these produces confusing runtime errors because the scenario _activates_ successfully but fails during execution.

### Simulated `@fail` does not inject typed `MachineFailure`

When a delegation state has a `@fail` transition whose action type-hints a `MachineFailure` subclass:

<!-- doctest-attr: ignore -->
```php
// Machine config
'checking_phone' => [
    'job'   => CheckPhoneJob::class,
    '@fail' => [
        'target'  => 'failed',
        'actions' => StoreFailureReasonAction::class,
    ],
],

// Action expects typed failure
public function __invoke(FindeksContext $ctx, FindeksFailure $failure): void { ... }
```

A scenario with `'checking_phone' => '@fail'` will throw `TypeError` at runtime. The engine synthesizes a generic `ChildMachineFailEvent` with `error_message: 'Scenario simulated failure'` — it does not construct your `MachineFailure` subclass because it cannot know its shape.

**Workaround:** override the action with a context-write proxy so the real action never runs:

<!-- doctest-attr: ignore -->
```php
'checking_phone' => [
    'outcome' => '@fail',
    StoreFailureReasonAction::class => [
        'failureReason' => 'Scenario simulated failure',
        'isSuccessful'  => false,
    ],
],
```

Array-valued action overrides generate a proxy with only a `ContextManager $ctx` parameter, bypassing the typed injection entirely.

### Overrides are not reachable if guards route around them

Scenarios override behaviors, not branch selection. If the path to your overridden state depends on guards, the engine may take a different branch and never reach your override — silently.

<!-- doctest-attr: ignore -->
```php
// Machine has two branches at checking_cache:
// [HasCacheGuard=true]  → matching_phone (cache hit, no API call)
// [HasCacheGuard=false]  → querying_phone (fresh API query)

// Scenario wants to override querying_phone, but doesn't control the guard:
'phone_resolution.querying_phone' => '@done',  // never reached if cache exists
```

If the test customer has cached data, the machine takes the cache branch and the override is never applied. The scenario "succeeds" but the real API call in `matching_phone` fires.

**Fix:** override the branch-controlling guards to force the intended path:

<!-- doctest-attr: ignore -->
```php
'phone_resolution.checking_cache' => [
    HasCacheGuard::class => false,    // force the no-cache branch
],
'phone_resolution.querying_phone' => '@done',
```

After writing a scenario, run `php artisan machine:paths <MachineClass>` to confirm your override states appear on a path from source to target. If the graph has cache/retry/fast-path branches, the guards that control them must be overridden in the same plan.

### Transition actions with I/O fallbacks run during scenarios

Scenarios intercept **delegations** (job/machine invoke), not transition actions. Entry, exit, and transition actions execute with real side effects during scenario runs unless explicitly overridden.

<!-- doctest-attr: ignore -->
```php
// This action has a lazy I/O fallback — dangerous in scenarios
public function __invoke(MyContext $ctx, EventBehavior $event): void
{
    if ($ctx->queryId !== null) {
        return;  // context already has it
    }
    $ctx->queryId = ExternalApi::getQueryId(...);  // real API call
}
```

If the scenario does not pre-populate `queryId` in context, the fallback fires and hits production.

**Fix options:**

1. **Override the action** in `plan()` with a context-write proxy:

<!-- doctest-attr: ignore -->
```php
'matching_phone' => [
    MatchAndStoreAction::class => ['queryId' => 'SCENARIO-001'],
],
```

2. **Refactor the I/O** into a dedicated delegation state that scenarios can intercept cleanly. This is the preferred long-term fix — see [Action Design: Scenario-Friendly Design](/best-practices/action-design#scenario-friendly-design).

