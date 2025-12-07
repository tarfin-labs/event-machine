---
layout: home

hero:
  name: EventMachine
  text: Event-Driven State Machines for Laravel
  tagline: Complex workflows, simple code, complete history.
  image:
    light: /logo-light.svg
    dark: /logo-dark.svg
    alt: EventMachine
  actions:
    - theme: brand
      text: Get Started
      link: /introduction/quick-start
    - theme: alt
      text: View on GitHub
      link: https://github.com/tarfin-labs/event-machine

features:
  - icon: 📝
    title: Full Event Sourcing
    details: Every state change is recorded as an event, enabling complete audit trails and state restoration from any point.
  - icon: 🔧
    title: Laravel Native
    details: Deep integration with Eloquent models, dependency injection, service providers, and Artisan commands.
  - icon: 🛡️
    title: Type-Safe Context
    details: Validated, type-safe context management with custom context classes powered by Spatie Laravel Data.
  - icon: 🎯
    title: Guards & Conditions
    details: Control transitions with guard conditions that validate context before allowing state changes.
  - icon: ⚡
    title: Actions & Side Effects
    details: Execute business logic during transitions with entry, exit, and transition actions.
  - icon: 🧮
    title: Calculators
    details: Transform context data before guard evaluation with dedicated calculator behaviors.
  - icon: 🏗️
    title: Hierarchical States
    details: Organize complex workflows with nested and parallel states for better structure.
  - icon: 🔄
    title: Eventless Transitions
    details: Automatic transitions with @always for immediate state changes based on conditions.
  - icon: 🧪
    title: Comprehensive Testing
    details: Built-in Fakeable trait for mocking behaviors with full assertion support.
  - icon: 📦
    title: Event Archival
    details: Automatic compression and archival of old events for optimal database performance.
  - icon: 🔗
    title: Eloquent Integration
    details: Attach state machines to models with the HasMachines trait and automatic casting.
  - icon: 🎭
    title: Scenarios
    details: Define alternative machine configurations for different environments or use cases.
---

## Quick Example

::: code-group

```php [OrderMachine.php]
<?php

namespace App\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use App\Machines\Order\OrderContext;
use App\Machines\Order\Guards\HasItemsGuard;
use App\Machines\Order\Actions\CalculateTotalAction;
use App\Machines\Order\Actions\NotifyCustomerAction;

class OrderMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'pending',
                'context' => OrderContext::class,
                'states' => [
                    'pending' => [
                        'on' => [
                            'SUBMIT' => [
                                'target'  => 'processing',
                                'guards'  => HasItemsGuard::class,
                                'actions' => CalculateTotalAction::class,
                            ],
                        ],
                    ],
                    'processing' => [
                        'on' => [
                            'COMPLETE' => [
                                'target'  => 'completed',
                                'actions' => NotifyCustomerAction::class,
                            ],
                            'CANCEL' => 'cancelled',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'cancelled' => ['type' => 'final'],
                ],
            ],
        );
    }
}
```

```php [OrderContext.php]
<?php

namespace App\Machines\Order;

use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\ContextManager;
use Spatie\LaravelData\Attributes\Validation\Min;

class OrderContext extends ContextManager
{
    public function __construct(
        public array|Optional $items = [],

        #[Min(0)]
        public int|Optional $total = 0,

        public ?string $customer_email = null,
    ) {
        parent::__construct();

        if ($this->items instanceof Optional) {
            $this->items = [];
        }

        if ($this->total instanceof Optional) {
            $this->total = 0;
        }
    }

    public function hasItems(): bool
    {
        return count($this->items) > 0;
    }
}
```

```php [HasItemsGuard.php]
<?php

namespace App\Machines\Order\Guards;

use Tarfinlabs\EventMachine\Behavior\GuardBehavior;
use App\Machines\Order\OrderContext;

class HasItemsGuard extends GuardBehavior
{
    public function __invoke(OrderContext $context): bool
    {
        return $context->hasItems();
    }
}
```

```php [CalculateTotalAction.php]
<?php

namespace App\Machines\Order\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use App\Machines\Order\OrderContext;

class CalculateTotalAction extends ActionBehavior
{
    public function __invoke(OrderContext $context): void
    {
        $context->total = collect($context->items)
            ->sum(fn($item) => $item['price'] * $item['quantity']);
    }
}
```

```php [NotifyCustomerAction.php]
<?php

namespace App\Machines\Order\Actions;

use Illuminate\Support\Facades\Mail;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use App\Machines\Order\OrderContext;

class NotifyCustomerAction extends ActionBehavior
{
    public function __invoke(OrderContext $context): void
    {
        if ($context->customer_email) {
            Mail::to($context->customer_email)
                ->queue(new OrderCompletedMail($context));
        }
    }
}
```

```php [Usage]
<?php

use App\Machines\OrderMachine;

// Create a new machine instance
$order = OrderMachine::create();

// Add items to context
$order->state->context->items = [
    ['name' => 'Widget', 'price' => 100, 'quantity' => 2],
    ['name' => 'Gadget', 'price' => 50, 'quantity' => 1],
];
$order->state->context->customer_email = 'customer@example.com';

// Submit the order
$order->send(['type' => 'SUBMIT']);

// Check state and total
echo $order->state->value;           // 'processing'
echo $order->state->context->total;  // 250

// Complete the order
$order->send(['type' => 'COMPLETE']);

echo $order->state->value;           // 'completed'
```

:::
