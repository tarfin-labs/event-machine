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

## Mid-Flight Scenarios

Sometimes a machine is already running — a QA tester walked through the first few states via the real UI and now wants to fast-forward the rest. Mid-flight scenarios continue from an existing machine instead of creating a new one.

### Declaring the Expected State

Use `from()` to declare which state the machine must be in before the scenario replays:

<!-- doctest-attr: ignore -->
```php
class OrderFromPaymentToShipped extends MachineScenario
{
    protected function machine(): string
    {
        return OrderMachine::class;
    }

    protected function description(): string
    {
        return 'Fast-forward from awaiting payment to shipped';
    }

    protected function from(): ?string
    {
        return 'awaiting_payment';
    }

    protected function steps(): array
    {
        return [
            $this->send('PAYMENT_RECEIVED'),
            $this->send('ORDER_SHIPPED'),
        ];
    }
}
```

### Playing on an Existing Machine

Use `playOn()` instead of `play()`:

<!-- doctest-attr: ignore -->
```php
// From code
$result = OrderFromPaymentToShipped::playOn(machineId: $existingRootEventId);

// $result->currentState === 'shipped'
```

### `play()` vs `playOn()`

| Method | Machine | `from()` | `parent()` | `models()` |
|--------|---------|----------|------------|------------|
| `play()` | Creates new | Ignored | Resolved | Created |
| `playOn(machineId)` | Restores existing | Validated | Ignored | Skipped |

When `playOn()` is called:
- The machine is restored from the given `machineId`
- If `from()` is defined, the current state is validated — throws `ScenarioFailedException` on mismatch
- `parent()` is not used (the existing machine IS the starting point)
- `models()` is skipped (models already exist from the real flow)
- `arrange()` stubs still apply (external APIs still need stubbing)

### Running Mid-Flight Scenarios

```bash
# Artisan
php artisan machine:scenario OrderFromPaymentToShipped \
    --machine-id=evt_01HXYZ...

# With parameter overrides
php artisan machine:scenario OrderFromPaymentToShipped \
    --machine-id=evt_01HXYZ... \
    --param="tracking_number:TRK-001"
```

HTTP endpoint (under the machine's route prefix):

```
POST {prefix}/scenarios/{slug}/{machineId}

# Example (if machine registered at /api/orders):
POST /api/orders/scenarios/order-from-payment-to-shipped/evt_01HXYZ...
```

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

Scenario routes are registered **per-machine** under each machine's prefix via `MachineRouter::register()`. When `MACHINE_SCENARIOS_ENABLED=true` and the machine has scenarios, four routes are auto-registered:

```
GET    {prefix}/scenarios                           → List this machine's scenarios
POST   {prefix}/scenarios/{slug}                    → Play scenario (new machine)
POST   {prefix}/scenarios/{slug}/{machineId}        → Play on existing machine (mid-flight)
GET    {prefix}/scenarios/{slug}/describe            → Scenario details (includes from field)
```

Example (if machine registered at `/api/orders`):

```
GET    /api/orders/scenarios
POST   /api/orders/scenarios/order-ready-for-payment
POST   /api/orders/scenarios/order-ready-for-payment/evt_01HXYZ...
GET    /api/orders/scenarios/order-ready-for-payment/describe
```

::: info No Global Routes
There are no global `/machine/scenarios/` routes. Each machine owns its scenario endpoints under its own prefix. If a machine has no `MachineRouter::register()` call, it has no scenario endpoints.
:::

### From Code

<!-- doctest-attr: ignore -->
```php
// New machine
$result = OrderReadyForPayment::play(['amount' => 5000]);

// Existing machine (mid-flight)
$result = OrderFromPaymentToShipped::playOn(machineId: $rootEventId);

$result->machineId;     // Machine ULID
$result->rootEventId;   // Root event for state restoration
$result->currentState;  // Current state name
$result->models;        // Created models (empty for mid-flight)
$result->stepsExecuted; // Number of steps played
$result->duration;      // Execution time in ms
$result->childResults;  // Results from child machine scenarios
```

## Endpoint Integration

When scenarios are enabled, two features integrate scenarios with your regular machine endpoints.

### `available_scenarios` in Response

After any successful event transition, the response includes `available_scenarios` — a list of scenarios that can be played from the machine's **current state** (after the transition). This mirrors `available_events` which lists events the machine accepts.

```json
{
  "data": {
    "machine_id": "evt_01HXYZ...",
    "value": ["order.awaiting_payment"],
    "context": { "..." },
    "available_events": [
      { "type": "PAYMENT_RECEIVED", "source": "parent" }
    ],
    "available_scenarios": [
      {
        "slug": "order-from-payment-to-shipped",
        "description": "Fast-forward from payment to shipped",
        "from": "awaiting_payment"
      }
    ]
  }
}
```

When `MACHINE_SCENARIOS_ENABLED=false`, the `available_scenarios` key is omitted entirely.

### Scenario Continuation via `scenario` Field

When sending an event, include a `scenario` field to automatically play a scenario after the event:

```http
POST /api/orders/{orderId}/payment-received
Content-Type: application/json

{
  "type": "PAYMENT_RECEIVED",
  "payload": { "transaction_id": "TXN-001" },
  "scenario": "order-from-shipping-to-delivered"
}
```

The event processes normally first (machine transitions to the next state), then the scenario plays from the resulting state. Both complete in a single request.

- If `MACHINE_SCENARIOS_ENABLED=false`, the `scenario` field is silently ignored
- If the scenario's `from()` doesn't match the post-transition state, a 422 error is returned
- Use `scenarioParams` to pass parameter overrides to the scenario

## Error Handling

Scenarios throw specific exceptions:

| Exception | When |
|-----------|------|
| `ScenariosDisabledException` | Scenarios are not enabled (`MACHINE_SCENARIOS_ENABLED=false`) |
| `ScenarioFailedException` | A step fails during replay (guard rejection, invalid event). Includes `stepIndex`, `eventType`, `currentState`, and `rejectionReason`. Also thrown when `from()` state doesn't match actual machine state in mid-flight mode. |
| `ScenarioConfigurationException` | `machine()` mismatch in parent/child composition chain. Also thrown when a mid-flight scenario (`playOn()`) defines `parent()` — these are mutually exclusive. |

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
