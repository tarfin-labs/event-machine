# Eloquent Integration

EventMachine provides seamless integration with Laravel's Eloquent ORM through the `MachineCast` and `HasMachines` trait. This allows you to embed state machines directly into your Eloquent models and persist their state automatically.

## Overview

The integration system allows you to:
- Store machine state as model attributes
- Automatically initialize machines when models are created
- Persist state changes to the database
- Restore machines from any historical state
- Control when machines should be initialized

## Basic Setup

### Using MachineCast

Add machine casting to your Eloquent model:

```php
use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Casts\MachineCast;

class Order extends Model
{
    protected $casts = [
        'workflow' => OrderWorkflowMachine::class . ':order',
    ];
}
```

The cast syntax is: `{MachineClass}:{contextKey}`
- **MachineClass**: The machine definition class
- **contextKey**: The key used to inject the model into machine context

### Using HasMachines Trait

For more control over machine initialization:

```php
use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Traits\HasMachines;

class Order extends Model
{
    use HasMachines;
    
    protected $casts = [
        'workflow' => OrderWorkflowMachine::class . ':order',
        'payment_flow' => PaymentMachine::class . ':order',
    ];
    
    // Optional: Define machines via method
    public function machines(): array
    {
        return [
            'approval_workflow' => ApprovalMachine::class . ':order',
        ];
    }
    
    // Optional: Control when to initialize machines
    public function shouldInitializeMachine(): bool
    {
        return $this->status !== 'archived';
    }
}
```

## MachineCast Deep Dive

### How It Works

The `MachineCast` implements Laravel's `CastsAttributes` interface:

```php
// When retrieving from database
public function get(Model $model, string $key, mixed $value, array $attributes): ?Machine
{
    // Parse cast configuration
    [$machineClass, $contextKey] = explode(':', $model->getCasts()[$key]);
    
    // Create machine from stored state
    $machine = $machineClass::create(state: $value);
    
    // Inject model into machine context
    $machine->state->context->set($contextKey, $model);
    
    return $machine;
}

// When storing to database
public function set(Model $model, string $key, mixed $value, array $attributes): ?string
{
    if ($value === null) {
        return null;
    }
    
    // Store the root event ID for state restoration
    return $value->state->history->first()->root_event_id;
}
```

### State Persistence

The cast stores only the root event ID, allowing full state restoration:

```php
$order = new Order(['status' => 'pending']);
$order->save(); // Machine automatically initialized

// Access machine
$machine = $order->workflow;
$machine->send('PAYMENT_RECEIVED');

$order->save(); // New state persisted as root_event_id
```

## HasMachines Trait Deep Dive

### Automatic Initialization

The trait hooks into Eloquent's model creation process:

```php
protected static function bootHasMachines(): void
{
    static::creating(static function (Model $model): void {
        foreach ($model->getCasts() as $attribute => $cast) {
            if (
                !isset($model->attributes[$attribute]) &&
                is_subclass_of(explode(':', $cast)[0], Machine::class) &&
                $model->shouldInitializeMachine()
            ) {
                // Initialize machine and store initial state
                $machine = $model->$attribute;
                $model->attributes[$attribute] = $machine->state->history->first()->root_event_id;
            }
        }
    });
}
```

### Flexible Machine Configuration

You can define machines in multiple ways:

```php
class Order extends Model
{
    use HasMachines;
    
    // Method 1: Via casts property (most common)
    protected $casts = [
        'workflow' => OrderWorkflowMachine::class . ':order',
    ];
    
    // Method 2: Via machines() method (dynamic)
    public function machines(): array
    {
        return [
            'approval' => $this->needs_approval 
                ? ApprovalMachine::class . ':order'
                : SimpleApprovalMachine::class . ':order',
        ];
    }
    
    // Method 3: Via machines property (static)
    protected array $machines = [
        'notification' => NotificationMachine::class . ':order',
    ];
}
```

### Conditional Initialization

Control when machines should be initialized:

```php
public function shouldInitializeMachine(): bool
{
    // Don't initialize for archived orders
    if ($this->status === 'archived') {
        return false;
    }
    
    // Don't initialize during data imports
    if (app()->runningInConsole() && app('importing')) {
        return false;
    }
    
    // Don't initialize for read-only operations
    if ($this->exists && !$this->isDirty()) {
        return false;
    }
    
    return true;
}
```

## Usage Patterns

### Basic CRUD Operations

```php
// Create order with automatic machine initialization
$order = Order::create([
    'customer_id' => 123,
    'total' => 99.99,
]);

// Machine is automatically available
$workflow = $order->workflow; // OrderWorkflowMachine instance
echo $workflow->state->value; // 'pending'

// Send events to progress through workflow
$order->workflow->send('PAYMENT_RECEIVED');
$order->save(); // State persisted

// Retrieve later - machine restored from database
$order = Order::find(1);
echo $order->workflow->state->value; // 'paid'
```

### Multiple Machines per Model

```php
class Order extends Model
{
    use HasMachines;
    
    protected $casts = [
        'order_workflow' => OrderWorkflowMachine::class . ':order',
        'payment_flow' => PaymentMachine::class . ':order',
        'shipping_flow' => ShippingMachine::class . ':order',
    ];
}

// Each machine operates independently
$order->order_workflow->send('CONFIRMED');
$order->payment_flow->send('PAYMENT_RECEIVED');
$order->shipping_flow->send('LABEL_CREATED');

$order->save(); // All machine states persisted
```

### Context Injection

The model is automatically injected into machine context:

```php
class OrderWorkflowMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id' => 'order_workflow',
                'initial' => 'pending',
                'context' => [
                    'order' => null, // Will be injected automatically
                ],
                'states' => [
                    'pending' => [
                        'on' => [
                            'PAYMENT_RECEIVED' => [
                                'target' => 'paid',
                                'actions' => [SendConfirmationEmail::class],
                            ],
                        ],
                    ],
                    'paid' => [],
                ],
            ],
            behavior: [
                'actions' => [
                    SendConfirmationEmail::class => function (ContextManager $context) {
                        $order = $context->get('order'); // Model automatically available
                        Mail::to($order->customer->email)
                            ->send(new OrderConfirmation($order));
                    },
                ],
            ]
        );
    }
}
```

## Advanced Patterns

### Polymorphic Machine Integration

```php
class Workflow extends Model
{
    use HasMachines;
    
    protected $casts = [
        'workflowable_type' => 'string',
        'workflowable_id' => 'integer',
    ];
    
    public function workflowable()
    {
        return $this->morphTo();
    }
    
    public function machines(): array
    {
        // Select machine based on workflowable type
        return match ($this->workflowable_type) {
            Order::class => ['state_machine' => OrderWorkflowMachine::class . ':workflow'],
            Invoice::class => ['state_machine' => InvoiceWorkflowMachine::class . ':workflow'],
            default => [],
        };
    }
}
```

### Machine State Queries

```php
// Query based on machine state
$pendingOrders = Order::whereHas('machineEvents', function ($query) {
    $query->where('state', 'pending');
})->get();

// Custom query scopes
class Order extends Model
{
    public function scopePending($query)
    {
        return $query->whereHas('machineEvents', function ($q) {
            $q->where('state', 'pending');
        });
    }
    
    public function scopeInState($query, string $state)
    {
        return $query->whereHas('machineEvents', function ($q) use ($state) {
            $q->where('state', $state);
        });
    }
}

// Usage
$orders = Order::inState('processing')->get();
```

### Event Listeners

```php
// Listen for machine state changes
class OrderWorkflowMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id' => 'order_workflow',
                'states' => [
                    'paid' => [
                        'entry' => [UpdateOrderStatus::class],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    UpdateOrderStatus::class => function (ContextManager $context) {
                        $order = $context->get('order');
                        $order->update(['status' => 'paid']);
                        
                        // Trigger Laravel events
                        event(new OrderPaid($order));
                    },
                ],
            ]
        );
    }
}
```

## Performance Considerations

### Lazy Loading

Machines are loaded on-demand when accessed:

```php
$orders = Order::all(); // No machines loaded yet

foreach ($orders as $order) {
    if ($order->needs_processing) {
        $state = $order->workflow->state->value; // Machine loaded here
    }
}
```

### Eager Loading

For bulk operations, consider machine state queries instead:

```php
// Instead of loading all machines
$orders = Order::with('machineEvents')->get();
foreach ($orders as $order) {
    if ($order->workflow->state->value === 'pending') {
        // Process...
    }
}

// Use database queries
$pendingOrders = Order::whereHas('machineEvents', function ($query) {
    $query->where('state', 'pending');
})->get();
```

### Memory Management

```php
public function shouldInitializeMachine(): bool
{
    // Skip initialization for API serialization
    if (request()->is('api/*') && request()->isMethod('GET')) {
        return false;
    }
    
    return true;
}
```

## Testing

### Factory Integration

```php
class OrderFactory extends Factory
{
    public function definition()
    {
        return [
            'customer_id' => Customer::factory(),
            'total' => $this->faker->randomFloat(2, 10, 1000),
        ];
    }
    
    public function paid()
    {
        return $this->afterCreating(function (Order $order) {
            $order->workflow->send('PAYMENT_RECEIVED');
            $order->save();
        });
    }
    
    public function shipped()
    {
        return $this->paid()->afterCreating(function (Order $order) {
            $order->workflow->send('SHIPPED');
            $order->save();
        });
    }
}

// Usage in tests
$paidOrder = Order::factory()->paid()->create();
$shippedOrder = Order::factory()->shipped()->create();
```

### Assertion Helpers

```php
class OrderTest extends TestCase
{
    public function test_order_workflow()
    {
        $order = Order::factory()->create();
        
        $this->assertEquals('pending', $order->workflow->state->value);
        
        $order->workflow->send('PAYMENT_RECEIVED');
        $order->save();
        
        $this->assertEquals('paid', $order->fresh()->workflow->state->value);
    }
}
```

The Eloquent integration provides a seamless way to embed EventMachine state machines into your Laravel applications while maintaining all the benefits of Eloquent's ORM features.