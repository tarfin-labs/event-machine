# Scenario Behaviors

> Reusable, class-based behavior overrides for scenarios — when bool/array/closure shorthand isn't enough.

## When to Use

Most scenario overrides are simple enough for inline forms:

<!-- doctest-attr: ignore -->
```php
'eligibility_check' => [
    IsFarmerNotEligibleGuard::class => false,                    // bool
    StoreApplicationAction::class => ['applicationId' => 'APP'], // array
],
```

Use class-based scenario behaviors when you need:

- **Complex logic** — multi-step conditionals, branching based on context
- **Reuse** — the same override logic used in multiple scenarios
- **Full DI** — inject services, models, or other dependencies
- **Testability** — unit-test the override behavior independently

## Base Classes

Four abstract classes, one per behavior type:

| Base class | Extends | Replaces |
|-----------|---------|----------|
| `GuardScenarioBehavior` | `GuardBehavior` | Guards — must return `bool` |
| `ActionScenarioBehavior` | `ActionBehavior` | Actions — `void` (mutates context) |
| `CalculatorScenarioBehavior` | `CalculatorBehavior` | Calculators — `void` (pre-computes context) |
| `OutputScenarioBehavior` | `OutputBehavior` | Outputs — returns output data |

All extend `InvokableBehavior`, so they inherit full parameter injection support.

## DI and Type Compatibility

Scenario behaviors are **type-compatible** with the original behavior they replace. When ScenarioPlayer registers overrides, it uses `App::bind()` to swap the original class with the scenario version in the container.

<!-- doctest-attr: ignore -->
```php
// In plan():
'routing' => [
    CustomerContextCalculator::class => CustomerContextCalculatorScenario::class,
],

// What happens:
// App::bind(CustomerContextCalculator::class, fn () => new CustomerContextCalculatorScenario());
// When the engine resolves CustomerContextCalculator, it gets the scenario version.
```

The scenario behavior receives the same injected parameters as the original — `ContextManager`, `EventBehavior`, `State`, etc.

## Examples

### Guard — Complex Eligibility Logic

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Scenarios\GuardScenarioBehavior;

class IsCustomerInfoCompleteGuardScenario extends GuardScenarioBehavior
{
    public function __invoke(ContextManager $ctx): bool
    {
        // Only pass if farmer has both phone and email
        $farmer = $ctx->get('farmer');

        return $farmer !== null
            && $farmer->phone !== null
            && $farmer->email !== null;
    }
}
```

### Action — Mock External Service

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Scenarios\ActionScenarioBehavior;

class StoreApplicationActionScenario extends ActionScenarioBehavior
{
    public function __invoke(ContextManager $ctx): void
    {
        // Skip real API call, write mock data to context
        $ctx->set('applicationId', 'APP-SCENARIO-' . now()->timestamp);
        $ctx->set('applicationStatus', 'submitted');
        $ctx->set('submittedAt', now()->toISOString());
    }
}
```

### Calculator — Pre-Set Context Data

<!-- doctest-attr: ignore -->
```php
use App\Models\Farmer;
use App\Models\Retailer;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Scenarios\CalculatorScenarioBehavior;

class CustomerContextCalculatorScenario extends CalculatorScenarioBehavior
{
    public function __invoke(ContextManager $ctx): void
    {
        $ctx->set('farmer', Farmer::find(42));
        $ctx->set('retailer', Retailer::find(7));
    }
}
```

### Output — Fixed Output Data

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Scenarios\OutputScenarioBehavior;

class OrderSummaryOutputScenario extends OutputScenarioBehavior
{
    public function __invoke(ContextManager $ctx): array
    {
        return [
            'orderId'    => $ctx->get('orderId', 'ORD-SCENARIO'),
            'status'     => 'approved',
            'totalAmount' => 15000,
        ];
    }
}
```

## Naming Convention

Scenario behavior names mirror the original behavior name with `Scenario` suffix:

| Original | Scenario version |
|----------|-----------------|
| `HasConsentGuard` | `HasConsentGuardScenario` |
| `StoreApplicationAction` | `StoreApplicationActionScenario` |
| `CustomerContextCalculator` | `CustomerContextCalculatorScenario` |
| `OrderSummaryOutput` | `OrderSummaryOutputScenario` |

This enables search: `HasConsent` finds both `HasConsentGuard` and `HasConsentGuardScenario` side by side.

## File Organization

Place scenario behaviors next to the scenario that uses them in a subdirectory named after the scenario. For simple scenarios (all inline overrides), a single file is sufficient. See [Scenario Endpoints — File Organization](/advanced/scenario-endpoints#file-organization) for the full directory structure.

## Inline vs Class-Based Overrides

The override mechanism differs based on whether the behavior key is a class FQCN or a camelCase inline key:

| Key format | Example | Override mechanism |
|-----------|---------|-------------------|
| **FQCN** (class-based) | `IsEligibleGuard::class => false` | `App::bind()` — container resolution swapped |
| **camelCase** (inline) | `'isEligibleGuard' => false` | `InlineBehaviorFake::fake()` — inline interception |

Both support the same value forms (bool, array, closure, class). The engine resolves the correct mechanism automatically based on the key format.

## Override Form Comparison

| Form | Best for | Example |
|------|----------|---------|
| **Bool** | Simple guard pass/fail | `Guard::class => false` |
| **Array** | Context data injection | `Action::class => ['key' => 'value']` |
| **Closure** | One-off inline logic | `Guard::class => fn (ContextManager $ctx): bool => ...` |
| **Class** | Reusable, complex, testable | `Guard::class => GuardScenario::class` |
