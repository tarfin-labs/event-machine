---
layout: home

hero:
  name: EventMachine
  text: State Machines with Complete History
  tagline: Define states. Transition safely. Track everything. Restore anytime.
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

## Every Transition, Persisted

**Event sourcing built in.** Every state change becomes an immutable event in your database. Complete audit trail without extra code.

Know exactly what happened, when, and why. Compliance-ready from day one. Debug production issues by replaying history.

[Learn about persistence &rarr;](/laravel-integration/persistence)

</div>
<div class="feature-code">

```php
// Send an event
$order->send(['type' => 'SUBMIT']);

// Every transition is recorded in machine_events table
// | id | type    | payload         | created_at          |
// |----|---------|-----------------|---------------------|
// | 1  | @init   | {}              | 2024-01-15 10:30:00 |
// | 2  | SUBMIT  | {"user_id": 5}  | 2024-01-15 10:30:01 |
// | 3  | APPROVE | {"by": "admin"} | 2024-01-15 11:45:00 |

// Query event history by root_event_id
$rootEventId = $order->state->history->first()->root_event_id;

MachineEvent::where('root_event_id', $rootEventId)
    ->oldest('sequence_number')
    ->get();
```

</div>
</div>

<div class="feature-section">
<div class="feature-text">

## Complete Audit Trail

**Compliance-ready history at your fingertips.** Filter events by type, date range, or payload. Know who did what and when - with evidence.

Regulatory audit? Legal discovery? Customer dispute? Your machine history is queryable, filterable, and legally defensible.

[Query your history &rarr;](/laravel-integration/persistence)

</div>
<div class="feature-code">

```php
// Find all approval events in date range
MachineEvent::where('root_event_id', $rootEventId)
    ->where('type', 'APPROVE')
    ->whereBetween('created_at', [$start, $end])
    ->get();

// Get full state at any point in history
$machine = OrderMachine::create(state: $rootEventId);
$machine->state->history->each(function ($event) {
    echo "{$event->type} at {$event->created_at}\n";
    echo "Context: " . json_encode($event->context) . "\n";
});

// Who approved this order?
$approval = $machine->state->history
    ->where('type', 'APPROVE')
    ->first();
// {"by": "admin", "approved_at": "2024-01-15 11:45:00"}
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

```php
'CHECKOUT' => [
    'target'      => 'processing',
    'calculators' => PriceCalculator::class,  // Runs first
    'guards'      => MinimumOrderGuard::class, // Validates
    'actions'     => SendReceiptAction::class, // Executes
],
```

```php
class PriceCalculator extends CalculatorBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('total', $context->get('quantity') * $context->get('price'));
    }
}
```

```php
class MinimumOrderGuard extends GuardBehavior
{
    public function __invoke(ContextManager $context): bool
    {
        return $context->get('total') >= 100;
    }
}
```

```php
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

## Testable Behaviors

**Mock, assert, verify.** Every behavior supports faking for isolated unit tests. No more integration tests for simple business logic.

Use `shouldReturn()` to mock guards, `shouldRun()` to verify actions. Assert behaviors ran or didn't. Full Mockery integration built-in.

[Testing behaviors &rarr;](/testing/fakeable-behaviors)

</div>
<div class="feature-code">

```php
it('blocks checkout with insufficient total', function () {
    $context = new ContextManager(['total' => 50]);

    expect(MinimumOrderGuard::run($context))->toBeFalse();
});
```

```php
it('sends receipt on checkout', function () {
    SendReceiptAction::shouldRun()->once();

    $machine->send(['type' => 'CHECKOUT']);

    SendReceiptAction::assertRan();
});
```

```php
it('can mock guard to always pass', function () {
    MinimumOrderGuard::shouldReturn(true);

    $machine->send(['type' => 'CHECKOUT']);

    expect($machine->state->matches('processing'))->toBeTrue();
});
```

</div>
</div>

<div class="feature-section">
<div class="feature-text">

## Archive Millions, Restore Any

**Enterprise-grade event management.** Completed machines pile up? Archive them. Events compressed to a fraction of their size, but fully restorable when needed.

Six months later, compliance asks about order #12847? One line brings the entire machine back with full context and history.

[Archival & restoration &rarr;](/laravel-integration/archival-compression)

</div>
<div class="feature-code">

```bash
# Archive inactive machines (30+ days by default)
php artisan machine:archive-events

# Events compressed: 847 events → 1 archived record
# Storage: 2.3 MB → 127 KB
```

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

<div class="feature-section">
<div class="feature-text">

## Type-Safe Context

**Validated data at every step.** Context classes powered by Spatie Laravel Data give you typed properties, validation rules, and transformations.

No more `$context['total']` typos. No more missing validation. IDE autocompletion everywhere.

[Working with context &rarr;](/building/working-with-context)

</div>
<div class="feature-code">

```php
class OrderContext extends ContextManager
{
    public function __construct(
        public array $items = [],

        #[Min(0)]
        public int $total = 0,

        #[Email]
        public ?string $customerEmail = null,

        public OrderStatus $status = OrderStatus::Draft,
    ) {
        parent::__construct();
    }

    public function itemCount(): int
    {
        return count($this->items);
    }
}
```

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

</HomeFeatures>
