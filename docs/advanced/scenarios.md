# Scenarios

Scenarios are pre-scripted event replay sequences that bring a machine to a desired state in staging or test environments. They enable product teams, QA, and non-technical stakeholders to test new features without manually navigating complex state flows.

## When to Use Scenarios

| Situation | Tool |
|-----------|------|
| Unit testing machine transitions | [TestMachine](/testing/test-machine) |
| Testing a behavior in isolation | [State::forTesting()](/testing/isolated-testing) |
| Getting a machine to a specific state in staging for manual testing | **Scenarios** |

## Enabling Scenarios

Scenarios are disabled by default. Enable them in your `.env`:

```
MACHINE_SCENARIOS_ENABLED=true
```

Configuration in `config/machine.php`:

<!-- doctest-attr: ignore -->
```php
'scenarios' => [
    'enabled' => env('MACHINE_SCENARIOS_ENABLED', false),
    'path'    => app_path('Machines/Scenarios'),
],
```

## Creating a Scenario

Extend `MachineScenario` and implement `machine()`, `description()`, and `steps()`:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;

class OrderReadyForPayment extends MachineScenario
{
    protected function machine(): string
    {
        return OrderMachine::class;
    }

    protected function description(): string
    {
        return 'Order approved and ready for payment';
    }

    protected function steps(): array
    {
        return [
            $this->send('ORDER_CREATED', ['amount' => 1000]),
            $this->send('ORDER_REVIEWED'),
            $this->send('ORDER_APPROVED', ['approved_by' => 'test_manager']),
        ];
    }
}
```

## Parametrization

Scenarios can accept parameters with defaults:

<!-- doctest-attr: ignore -->
```php
class OrderReadyForPayment extends MachineScenario
{
    protected function defaults(): array
    {
        return [
            'amount'      => 1000,
            'approved_by' => 'test_manager',
        ];
    }

    protected function steps(): array
    {
        return [
            $this->send('ORDER_CREATED', ['amount' => $this->param('amount')]),
            $this->send('ORDER_REVIEWED'),
            $this->send('ORDER_APPROVED', ['approved_by' => $this->param('approved_by')]),
        ];
    }
}
```

Override defaults at runtime:

<!-- doctest-attr: ignore -->
```php
OrderReadyForPayment::play(['amount' => 5000]);
```

## Stubbing External Dependencies (arrange)

Use `arrange()` to stub guards, actions, and services with predetermined responses:

<!-- doctest-attr: ignore -->
```php
protected function arrange(): array
{
    return [
        // Guards: return predetermined boolean
        IsStockAvailableGuard::class => true,

        // Actions: provide stub data instead of calling external API
        FetchCreditScoreAction::class => ['score' => 750],

        // Services: stub specific method return values
        PaymentGateway::class => [
            'charge'   => ['transaction_id' => 'TXN-001'],
            'validate' => true,
        ],
    ];
}
```

### ScenarioStubContract

For explicit control over how stub data maps to context, implement `ScenarioStubContract` on your action:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Scenarios\ScenarioStubContract;

class FetchCreditScoreAction extends ActionBehavior implements ScenarioStubContract
{
    public function __invoke(State $state): void
    {
        $score = $this->creditService->fetch($state->context->get('customer_id'));
        $state->context->set('credit_score', $score);
    }

    public function applyStub(State $state, array $data): void
    {
        $state->context->set('credit_score', $data['score']);
    }
}
```

If your action does NOT implement `ScenarioStubContract`, stub data keys are mapped directly to context keys as a convention-based fallback (e.g., `['score' => 750]` calls `$state->context->set('score', 750)`).

## Model Creation

Use `models()` to create Eloquent models before event replay:

<!-- doctest-attr: ignore -->
```php
protected function models(): array
{
    return [
        'customer' => Customer::factory()->state([
            'name' => $this->param('customer_name', 'Test Customer'),
        ]),
        'order' => Order::factory()->state(function (array $attributes) {
            return ['customer_id' => $this->model('customer')->id];
        }),
    ];
}
```

Access models in steps via `$this->model('name')`.

## Composition

Scenarios can extend other scenarios via `parent()`:

<!-- doctest-attr: ignore -->
```php
class OrderShipped extends MachineScenario
{
    protected function parent(): string
    {
        return OrderReadyForPayment::class;
    }

    protected function steps(): array
    {
        return [
            // Parent's steps run first, then:
            $this->send('PAYMENT_RECEIVED'),
            $this->send('ORDER_SHIPPED'),
        ];
    }
}
```

Merge rules:

| Aspect | Behavior |
|--------|----------|
| `machine()` | Must match across chain |
| `arrange()` | Merged — child overrides parent for same class key |
| `models()` | Parent models created first, child can add or override |
| `defaults()` | Merged — child overrides parent for same key |
| `steps()` | Sequential — parent first, then child |

## Child Machine Scenarios

When a machine delegates to child machines, use `$this->child()`:

<!-- doctest-attr: ignore -->
```php
protected function steps(): array
{
    return [
        $this->send('START_APPLICATION'),
        $this->send('CONSENT_GRANTED'),

        // Child machines spawned by the send() above
        $this->child(FindeksMachine::class)
            ->scenario(FindeksCompleted::class)
            ->with(['tckn' => $this->param('tckn')]),

        $this->child(TurmobMachine::class)
            ->scenario(TurmobVerified::class),
    ];
}
```

## Running Scenarios

### Artisan Command

```bash
# List all scenarios
php artisan machine:scenario --list

# List by machine
php artisan machine:scenario --list --machine=OrderMachine

# Play a scenario
php artisan machine:scenario OrderReadyForPayment

# Play with parameter overrides
php artisan machine:scenario OrderReadyForPayment --param="amount:5000"
```

### HTTP Endpoints

When scenarios are enabled, three endpoints are registered:

```
GET    /machine/scenarios                      → List scenarios
POST   /machine/scenarios/{slug}               → Play scenario
GET    /machine/scenarios/{slug}/describe       → Scenario details
```

### From Code

<!-- doctest-attr: ignore -->
```php
$result = OrderReadyForPayment::play(['amount' => 5000]);

$result->machineId;     // Machine ULID
$result->rootEventId;   // Root event for state restoration
$result->currentState;  // Current state name
$result->models;        // Created models
$result->stepsExecuted; // Number of steps played
$result->duration;      // Execution time in ms
$result->childResults;  // Results from child machine scenarios
```

## Error Handling

Scenarios throw specific exceptions:

| Exception | When |
|-----------|------|
| `ScenariosDisabledException` | Scenarios are not enabled (`MACHINE_SCENARIOS_ENABLED=false`) |
| `ScenarioFailedException` | A step fails during replay (guard rejection, invalid event). Includes `stepIndex`, `eventType`, `currentState`, and `rejectionReason`. |
| `ScenarioConfigurationException` | `machine()` mismatch in parent/child composition chain |

::: warning Production Safety
Never enable scenarios in production. The `MACHINE_SCENARIOS_ENABLED` flag defaults to `false`. When disabled, scenario routes are not registered and `ScenarioPlayer::play()` throws immediately — zero runtime overhead.
:::

## File Organization

```
app/Machines/Scenarios/
├── Orders/
│   ├── OrderReadyForPayment.php
│   └── OrderShipped.php
├── Payments/
│   └── PaymentCompleted.php
└── ...
```

## Caching

For production-like staging environments:

```bash
php artisan machine:scenario-cache
```
