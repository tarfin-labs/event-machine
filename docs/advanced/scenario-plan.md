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

### Same Behavior, Different Values Per State

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

**How it works:**
1. ScenarioPlayer intercepts delegation dispatch
2. Does NOT run the delegated machine/job
3. Simulates the completion by sending the declared outcome to the parent
4. Parent's `@done`/`@fail`/`@timeout` transition fires with the declared output

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
