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

The scaffolder generates a `plan()` with override entries for each intermediate state. Review the generated file, adjust guard/action overrides and delegation outcomes. See [MachineScenario Class](#machinescenario-class) below for the full class structure.

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

### Accessing Properties

Properties are set as `protected` class fields. Public getter methods (`machine()`, `source()`, `event()`, `target()`, `description()`) return these values. Override a getter when you need computed values:

<!-- doctest-attr: ignore -->
```php
// Default — property assignment (recommended)
protected string $machine = OrderMachine::class;

// Alternative — override the getter (when computation is needed)
public function machine(): string
{
    return config('machines.order.class');
}
```

The getters return the property value directly. Override the getter method when you need runtime computation.

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

## Next Steps

- **[Writing plan()](/advanced/scenario-plan)** — Behavior overrides, delegation outcomes, child scenarios, @continue, parallel states, parameters
- **[Endpoint Integration](/advanced/scenario-endpoints)** — QA workflow, request/response format, scenario routes, file organization
- **[Scaffold & Validation](/advanced/scenario-commands)** — `machine:scenario` and `machine:scenario-validate` commands
- **[Runtime & Engine](/advanced/scenario-runtime)** — Environment gating, async propagation, engine feature reference, error handling
