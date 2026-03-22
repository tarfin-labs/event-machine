---
layout: home

hero:
  name: EventMachine
  text: State Machines That Compose
  tagline: Delegation. Timers. Event sourcing. Parallel execution. All declarative, all Laravel.
  image:
    light: /logo-light.svg
    dark: /logo-dark.svg
    alt: EventMachine
  actions:
    - theme: brand
      text: Get Started
      link: /getting-started/what-is-event-machine
    - theme: alt
      text: View on GitHub
      link: https://github.com/tarfin-labs/event-machine
---

<HomeFeatures>

<div class="feature-section">
<div class="feature-text">

## Declare Your States

**Define complex workflows in plain PHP arrays.** States, transitions, guards, actions - all in one declarative configuration.

No more scattered if/else chains. No more inconsistent state checks. Your business logic lives in one place.

[Build your first machine &rarr;](/getting-started/your-first-machine)

</div>
<div class="feature-code">

```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition; // [!code hide]
MachineDefinition::define(
    config: [
        'initial' => 'draft',
        'context' => ['items' => [], 'total' => 0],
        'states' => [
            'draft' => [
                'on' => ['SUBMIT' => 'review'],
            ],
            'review' => [
                'on' => [
                    'APPROVE' => 'approved',
                    'REJECT'  => 'draft',
                ],
            ],
            'approved' => ['type' => 'final'],
        ],
    ],
);
```

</div>
</div>

<div class="feature-section">
<div class="feature-text">

## Behaviors: Guards, Actions, Calculators

**Calculators compute. Guards validate. Actions execute.** Each transition runs through a pipeline: calculate derived values, check conditions, then execute side effects.

Every behavior is a single-responsibility class. Compose them freely to build complex workflows from simple, testable pieces.

[Explore behaviors &rarr;](/behaviors/introduction)

</div>
<div class="feature-code">

<!-- doctest-attr: ignore -->
```php
'CHECKOUT' => [
    'target'      => 'processing',
    'calculators' => PriceCalculator::class,  // Runs first
    'guards'      => MinimumOrderGuard::class, // Validates
    'actions'     => SendReceiptAction::class, // Executes
],
```

```php
use Tarfinlabs\EventMachine\Behavior\CalculatorBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

class PriceCalculator extends CalculatorBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('total', $context->get('quantity') * $context->get('price'));
    }
}
```

```php
use Tarfinlabs\EventMachine\Behavior\GuardBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

class MinimumOrderGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return $context->get('total') >= 100;
    }
}
```

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

class SendReceiptAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        Mail::to($context->get('email'))->send(new Receipt($context->get('total')));
    }
}
```

</div>
</div>

<div class="feature-section">
<div class="feature-text">

## Every Transition, Persisted

**Event sourcing built in.** Every state change becomes an immutable event in your database. Complete audit trail without extra code.

Know exactly what happened, when, and why. Compliance-ready from day one. Debug production issues by replaying history. Query by type, date range, or payload.

[Learn about persistence &rarr;](/laravel-integration/persistence)

</div>
<div class="feature-code">

<!-- doctest-attr: bootstrap="laravel,db" -->
```php
// [!code hide:start]
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

$order = Machine::create(
    definition: MachineDefinition::define(
        config: [
            'id' => 'order',
            'initial' => 'draft',
            'context' => [],
            'states' => [
                'draft'    => ['on' => ['SUBMIT' => 'review']],
                'review'   => ['on' => ['APPROVE' => 'approved']],
                'approved' => ['type' => 'final'],
            ],
        ],
    ),
);
// [!code hide:end]
// Send an event
$order->send(['type' => 'SUBMIT']);

// Every transition is recorded in machine_events table
// | id | type    | payload         | created_at          |
// |----|---------|-----------------|---------------------|
// | 1  | @init   | {}              | 2024-01-15 10:30:00 |
// | 2  | SUBMIT  | {"user_id": 5}  | 2024-01-15 10:30:01 |
// | 3  | APPROVE | {"by": "admin"} | 2024-01-15 11:45:00 |

// Restore full state from any point in history
$rootEventId = $order->state->history->first()->root_event_id;

MachineEvent::where('root_event_id', $rootEventId)
    ->oldest('sequence_number')
    ->get();
```

</div>
</div>

<div class="feature-section">
<div class="feature-text">

## Parallel States with True Parallel Dispatch

**Run concurrent workflows — truly in parallel.** Multiple independent processes execute simultaneously via Laravel queue workers. Two API calls that take 5s and 2s? Done in 5s, not 7s.

Enable parallel dispatch and your entry actions run as separate queue jobs. Context merges safely under database locks. When all regions complete, `@done` fires automatically. Zero code changes to your actions or guards.

[Learn parallel states &rarr;](/advanced/parallel-states/)

</div>
<div class="feature-code">

<!-- doctest-attr: ignore -->
```php
'processing' => [
    'type' => 'parallel',
    '@done' => 'fulfilled',  // When ALL regions complete
    'states' => [
        'payment' => [
            'initial' => 'pending',
            'states' => [
                'pending' => ['on' => ['PAID' => 'done']],
                'done' => ['type' => 'final'],
            ],
        ],
        'shipping' => [
            'initial' => 'preparing',
            'states' => [
                'preparing' => ['on' => ['SHIPPED' => 'done']],
                'done' => ['type' => 'final'],
            ],
        ],
    ],
],
```

<!-- doctest-attr: ignore -->
```php
// With parallel dispatch enabled: entry actions run as queue jobs
// Worker A: PaymentGateway::charge()  — 5s
// Worker B: ShippingAPI::createLabel() — 2s
// Total: 5s (max), not 7s (sum)

$machine->send(['type' => 'START_PROCESSING']);
// → dispatches 2 ParallelRegionJobs
// → returns immediately

// Each job completes independently, merges context under lock
// Last job detects all regions final → @done → 'fulfilled'
```

<!-- doctest-attr: ignore -->
```php
// Or use actor-driven parallelism without dispatch:
$machine->send(['type' => 'PAID']);    // payment → done
$machine->send(['type' => 'SHIPPED']); // shipping → done
// All final → auto-transitions to 'fulfilled'
```

</div>
</div>

<div class="feature-section">
<div class="feature-text">

## Machine Delegation

**Break complex workflows into composable machines.** A parent state delegates work to a child machine. When the child completes, `@done` fires. When it fails, `@fail` fires. Sync or async — your choice.

Run children inline for simple cases, or dispatch to a queue for external I/O and webhooks. Fake child machines in tests with `Machine::fake()`. No child actually runs — assertions verify the invocation.

[Machine delegation &rarr;](/advanced/machine-delegation)

</div>
<div class="feature-code">

<!-- doctest-attr: ignore -->
```php
'processing_payment' => [
    'machine' => PaymentMachine::class,
    'with'    => ['order_id', 'total_amount'],
    'queue'   => 'payments',
    '@done'   => [
        'target'  => 'shipping',
        'actions' => 'storePaymentResultAction',
    ],
    '@fail'    => 'payment_failed',
    '@timeout' => [
        'after'  => 300,
        'target' => 'payment_timed_out',
    ],
],
```

<!-- doctest-attr: ignore -->
```php
// Test without running the real child machine
PaymentMachine::fake(result: ['payment_id' => 'pay_123']);

$machine = OrderWorkflowMachine::create();
$machine->send(['type' => 'START']);

PaymentMachine::assertInvoked();
PaymentMachine::assertInvokedWith(['order_id' => 'ORD-1']);

Machine::resetMachineFakes();
```

</div>
</div>

<div class="feature-section">
<div class="feature-text">

## Test Everything, Fluently

**From unit tests to full workflows — one expressive API.** Test individual behaviors in isolation with `runWithState()`, or chain entire machine lifecycles with `Machine::test()` and 21+ assertion methods.

Fake behaviors, assert guards, verify paths, check context — all with contextual failure messages. No database needed.

[Testing guide &rarr;](/testing/overview)

</div>
<div class="feature-code">

<!-- doctest-attr: ignore -->
```php
// Machine-level: fluent lifecycle testing
OrderMachine::test(['amount' => 100])
    ->withoutPersistence()
    ->faking([SendEmailAction::class])
    ->send('SUBMIT')
    ->assertState('awaiting_payment')
    ->send('PAY')
    ->assertState('preparing')
    ->assertBehaviorRan(SendEmailAction::class)
    ->send('DELIVER')
    ->assertState('delivered')
    ->assertFinished();
```

<!-- doctest-attr: ignore -->
```php
// Unit-level: isolated behavior testing
$state = State::forTesting(['total' => 50]);
expect(MinimumOrderGuard::runWithState($state))->toBeFalse();

// Guard and path assertions
OrderMachine::test(['amount' => 0])
    ->assertGuarded('SUBMIT')
    ->assertGuardedBy('SUBMIT', MinimumAmountGuard::class);
```

</div>
</div>

<div class="feature-section">
<div class="feature-text">

## Time-Based Events

**Declarative timers on transitions.** Define `after` (one-shot) and `every` (recurring) timers directly in your machine config. Auto-discovered, auto-scheduled — no Kernel.php setup needed.

[Time-Based Events &rarr;](/advanced/time-based-events)

</div>
<div class="feature-code">

<!-- doctest-attr: ignore -->
```php
'awaiting_payment' => [
    'on' => [
        'PAY'           => 'processing',
        'ORDER_EXPIRED' => ['target' => 'cancelled', 'after' => Timer::days(7)],
        'REMINDER'      => ['actions' => 'sendReminderAction', 'every' => Timer::days(1)],
    ],
],
```

</div>
</div>

<div class="feature-section">
<div class="feature-text">

## Scheduled Events

**Cron-based batch operations for machines.** Define `schedules` in your machine definition, register timing in `routes/console.php`. Resolvers query your models, EventMachine dispatches to all matching instances.

[Scheduled Events &rarr;](/advanced/scheduled-events)

</div>
<div class="feature-code">

<!-- doctest-attr: ignore -->
```php
MachineDefinition::define(
    config: [...],
    schedules: [
        'CHECK_EXPIRY' => ExpiredApplicationsResolver::class,
        'DAILY_REPORT' => null,  // auto-detect
    ],
)

// routes/console.php
MachineScheduler::register(AppMachine::class, 'CHECK_EXPIRY')
    ->dailyAt('00:10')->onOneServer();
```

</div>
</div>

<div class="feature-section">
<div class="feature-text">

## Type-Safe Context

**Validated data at every step.** Typed context classes give you properties, validation rules, and auto-casting for models, enums, and dates.

No more `$context['total']` typos. No more missing validation. IDE autocompletion everywhere.

[Working with context &rarr;](/building/working-with-context)

</div>
<div class="feature-code">

```php
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
enum OrderStatus { case Draft; } // [!code hide]

class OrderContext extends ContextManager
{
    public function __construct(
        public array $items = [],
        public int $total = 0,
        public ?string $customerEmail = null,
        public OrderStatus $status = OrderStatus::Draft,
    ) {
        parent::__construct();
    }

    public static function rules(): array
    {
        return [
            'total'         => ['integer', 'min:0'],
            'customerEmail' => ['nullable', 'email'],
        ];
    }

    public function itemCount(): int
    {
        return count($this->items);
    }
}
```

<!-- doctest-attr: ignore -->
```php
// Type-safe access everywhere
$order->state->context->total;        // int
$order->state->context->itemCount();  // method calls work
$order->state->context->status;       // enum
```

</div>
</div>

<div class="feature-section">
<div class="feature-text">

## Laravel Native

**Built for Laravel, not bolted on.** Eloquent integration, dependency injection, service providers, Artisan commands - everything you expect.

Attach machines to models. Inject services into behaviors. Validate with Artisan. Test with Pest.

[Laravel integration &rarr;](/laravel-integration/overview)

</div>
<div class="feature-code">

```php
use Illuminate\Database\Eloquent\Model; // [!code hide]
use Tarfinlabs\EventMachine\Traits\HasMachines; // [!code hide]
use Tarfinlabs\EventMachine\Casts\MachineCast; // [!code hide]

// Attach to Eloquent models
class Order extends Model
{
    use HasMachines;

    protected $casts = [
        'machine' => MachineCast::class.':'.OrderMachine::class,
    ];
}
```

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]

// Dependency injection in behaviors
class ProcessPaymentAction extends ActionBehavior
{
    public function __construct(
        private PaymentGateway $gateway,
        private OrderRepository $orders,
    ) {}

    public function __invoke(OrderContext $context): void
    {
        $this->gateway->charge($context->total);
        $this->orders->markPaid($context->orderId);
    }
}
```

</div>
</div>

<div class="feature-section">
<div class="feature-text">

## Zero-Boilerplate Endpoints

**Define endpoints in your machine, skip the controllers.** Each event becomes an HTTP endpoint automatically. One `MachineRouter::register()` call replaces dozens of routes and controllers.

Pre-send validation? Post-send cleanup? Exception handling? EndpointActions give you lifecycle hooks without touching machine internals.

[HTTP Endpoints &rarr;](/laravel-integration/endpoints)

</div>
<div class="feature-code">

<!-- doctest-attr: ignore -->
```php
MachineDefinition::define(
    config: [...],
    behavior: [...],
    endpoints: [
        'SUBMIT',                       // POST /submit
        'APPROVE' => [
            'method'     => 'PATCH',
            'middleware'  => ['auth:admin'],
            'result'     => 'approvalResult',
        ],
        'CANCEL'  => [
            'action' => CancelEndpointAction::class,
        ],
    ],
);
```

<!-- doctest-attr: ignore -->
```php
// One call generates all routes
MachineRouter::register(OrderMachine::class, [
    'prefix'    => 'orders',
    'model'     => Order::class,
    'attribute' => 'order_mre',
    'create'    => true,
    'modelFor'  => ['SUBMIT', 'APPROVE', 'CANCEL'],
]);
// POST   /orders/create
// POST   /orders/{order}/submit
// PATCH  /orders/{order}/approve
// POST   /orders/{order}/cancel
```

</div>
</div>

<div class="feature-section">
<div class="feature-text">

## Archive Millions, Restore Any

**Enterprise-grade event management.** Completed machines pile up? Archive them. Events compressed to a fraction of their size, but fully restorable when needed.

Six months later, compliance asks about order #12847? One line brings the entire machine back with full context and history.

[Archival & restoration &rarr;](/laravel-integration/archival)

</div>
<div class="feature-code">

```bash
# Archive inactive machines (30+ days by default)
php artisan machine:archive-events

# Events compressed: 847 events → 1 archived record
# Storage: 2.3 MB → 127 KB
```

<!-- doctest-attr: ignore -->
```php
// Months later: restore the entire machine
$archive = MachineEventArchive::where(
    'root_event_id', $rootEventId
)->first();

// Restore automatically decompresses events
$order = OrderMachine::create(state: $archive->root_event_id);

// Full machine restored with complete history
$order->state->matches('completed');       // true
$order->state->context->total;             // 15000
$order->state->history->count();           // 847
```

</div>
</div>

</HomeFeatures>
