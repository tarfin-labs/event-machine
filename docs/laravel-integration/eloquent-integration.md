# Eloquent Integration

EventMachine integrates with Eloquent models through the `HasMachines` trait and `MachineCast`.

## `HasMachines` Trait

Add the trait to your model:

<!-- doctest-attr: ignore -->
```php
use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Traits\HasMachines;

class Order extends Model
{
    use HasMachines;
}
```

## Defining Machines

### Via `machines()` Method

<!-- doctest-attr: ignore -->
```php
class Order extends Model
{
    use HasMachines;

    protected function machines(): array
    {
        return [
            'status' => OrderStatusMachine::class . ':order',
            'payment' => PaymentMachine::class . ':order',
        ];
    }
}
```

### Via $machines Property

<!-- doctest-attr: ignore -->
```php
class Order extends Model
{
    use HasMachines;

    protected array $machines = [
        'status' => OrderStatusMachine::class . ':order',
    ];
}
```

### Via $casts Property

<!-- doctest-attr: ignore -->
```php
class Order extends Model
{
    use HasMachines;

    protected $casts = [
        'workflow' => WorkflowMachine::class . ':order',
    ];
}
```

## Context Key

The syntax `MachineClass::class . ':contextKey'` injects the model:

<!-- doctest-attr: ignore -->
```php
'status' => OrderStatusMachine::class . ':order',
//                                       ^^^^^^
//                           This becomes $context->order
```

In behaviors:

<!-- doctest-attr: ignore -->
```php
class ProcessOrderAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $order = $context->order; // The Order model
        $order->total = 100;
        $order->save();
    }
}
```

## Database Column

Each machine stores its `root_event_id` in a column:

<!-- doctest-attr: ignore -->
```php
// Migration
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->ulid('status')->nullable(); // Stores root_event_id
    $table->ulid('payment')->nullable();
    $table->timestamps();
});
```

## Using Machines

### Basic Usage

<!-- doctest-attr: ignore -->
```php
// Create order - machine automatically initializes
$order = Order::create(['name' => 'Order #1']);

// Access machine
$order->status->send(['type' => 'SUBMIT']);

// Check state
$order->status->state->matches('submitted'); // true

// Access context
$order->status->state->context->orderId;
```

### Machine Auto-Initialization

When a model is created, machines are automatically initialized:

<!-- doctest-attr: ignore -->
```php
$order = Order::create();

// status column now contains the root_event_id
echo $order->status; // ulid value

// Access the machine instance
$order->status->state->matches('pending'); // true
```

### Controlling Initialization

Override the `shouldInitializeMachine()` method to control when machines initialize:

<!-- doctest-attr: ignore -->
```php
class Order extends Model
{
    use HasMachines;

    protected function shouldInitializeMachine(): bool
    {
        // Only initialize for new orders
        return $this->type === 'new';
    }
}
```

## Machine State Restoration

When you access a machine attribute, it's automatically restored:

<!-- doctest-attr: ignore -->
```php
// First access
$order = Order::find(1);
$order->status->send(['type' => 'SUBMIT']);

// Later access (even after page refresh)
$order = Order::find(1);
$order->status->state->matches('submitted'); // true
```

## Multiple Machines

A model can have multiple machines:

<!-- doctest-attr: ignore -->
```php
class Order extends Model
{
    use HasMachines;

    protected function machines(): array
    {
        return [
            'order_status' => OrderStatusMachine::class . ':order',
            'payment_status' => PaymentMachine::class . ':order',
            'fulfillment' => FulfillmentMachine::class . ':order',
        ];
    }
}

// Migration
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->ulid('order_status')->nullable();
    $table->ulid('payment_status')->nullable();
    $table->ulid('fulfillment')->nullable();
    // ...
});

// Usage
$order->order_status->send(['type' => 'CONFIRM']);
$order->payment_status->send(['type' => 'CHARGE']);
$order->fulfillment->send(['type' => 'SHIP']);
```

## Practical Example

### Order Model

<!-- doctest-attr: ignore -->
```php
namespace App\Models;

use App\Machines\OrderStatusMachine;
use App\Machines\PaymentMachine;
use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Traits\HasMachines;

class Order extends Model
{
    use HasMachines;

    protected $fillable = [
        'customer_id',
        'items',
        'total',
        'status',
        'payment_status',
    ];

    protected $casts = [
        'items' => 'array',
        'total' => 'decimal:2',
    ];

    protected function machines(): array
    {
        return [
            'status' => OrderStatusMachine::class . ':order',
            'payment_status' => PaymentMachine::class . ':order',
        ];
    }

    // Helper methods
    public function isCompleted(): bool
    {
        return $this->status->state->matches('completed');
    }

    public function isPaid(): bool
    {
        return $this->payment_status->state->matches('paid');
    }

    public function canShip(): bool
    {
        return $this->isCompleted() && $this->isPaid();
    }
}
```

### Order Machine

<!-- doctest-attr: ignore -->
```php
namespace App\Machines;

use App\Models\Order;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class OrderStatusMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id' => 'order_status',
                'initial' => 'pending',
                'states' => [
                    'pending' => [
                        'on' => [
                            'SUBMIT' => [
                                'target' => 'processing',
                                'actions' => 'markAsSubmitted',
                            ],
                        ],
                    ],
                    'processing' => [
                        'on' => [
                            'COMPLETE' => 'completed',
                            'CANCEL' => 'cancelled',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'cancelled' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'markAsSubmitted' => function ($context) {
                        $order = $context->order;
                        $order->submitted_at = now();
                        $order->save();
                    },
                ],
            ],
        );
    }
}
```

### Controller Usage

<!-- doctest-attr: ignore -->
```php
namespace App\Http\Controllers;

use App\Models\Order;

class OrderController extends Controller
{
    public function submit(Order $order)
    {
        $order->status->send(['type' => 'SUBMIT']);

        return redirect()->route('orders.show', $order);
    }

    public function complete(Order $order)
    {
        $order->status->send(['type' => 'COMPLETE']);

        return redirect()->route('orders.show', $order);
    }

    public function show(Order $order)
    {
        return view('orders.show', [
            'order' => $order,
            'currentState' => $order->status->state->currentStateDefinition->id,
            'canComplete' => $order->status->state->matches('processing'),
        ]);
    }
}
```

## MachineCast

For more control, use `MachineCast` directly:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Casts\MachineCast;

class Order extends Model
{
    protected $casts = [
        'status' => MachineCast::class . ':' . OrderStatusMachine::class . ',order',
    ];
}
```

## Querying by State

Since state is stored as a `root_event_id`, you need to join with `machine_events`:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Models\MachineEvent;

// Get orders in 'processing' state
$processingOrders = Order::whereHas('machineEvents', function ($query) {
    $query->where('machine_value', 'like', '%processing%');
})->get();

// Or via raw query
$orders = Order::whereIn('status', function ($query) {
    $query->select('root_event_id')
        ->from('machine_events')
        ->where('machine_value', 'like', '%processing%');
})->get();
```

## Best Practices

### 1. Use Descriptive Attribute Names

<!-- doctest-attr: ignore -->
```php
'order_status' => OrderStatusMachine::class,  // Clear
'status' => OrderStatusMachine::class,        // OK
's' => OrderStatusMachine::class,             // Avoid
```

### 2. Create Helper Methods

<!-- doctest-attr: ignore -->
```php
class Order extends Model
{
    public function isEditable(): bool
    {
        return $this->status->state->matches('draft');
    }

    public function canCancel(): bool
    {
        return in_array(
            $this->status->state->currentStateDefinition->key,
            ['pending', 'processing']
        );
    }
}
```

### 3. Handle Machine Errors in Controllers

<!-- doctest-attr: ignore -->
```php
public function submit(Order $order)
{
    try {
        $order->status->send(['type' => 'SUBMIT']);
    } catch (MachineValidationException $e) {
        return back()->withErrors(['status' => $e->getMessage()]);
    } catch (NoTransitionDefinitionFoundException $e) {
        return back()->withErrors(['status' => 'Cannot submit from current state']);
    }

    return redirect()->route('orders.show', $order);
}
```
