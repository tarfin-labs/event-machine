# Eloquent Integration

EventMachine integrates with Eloquent models through the `HasMachines` trait and Laravel's native casting system. Machines use PHP 8.4 lazy proxies — they are never restored from the database until you explicitly access a property or call a method on them.

## `HasMachines` Trait

Add the trait to your model and define machines in `casts()`:

<!-- doctest-attr: ignore -->
```php
use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Traits\HasMachines;

class Order extends Model
{
    use HasMachines;

    protected function casts(): array
    {
        return [
            'status' => OrderStatusMachine::class . ':order',
        ];
    }
}
```

## Defining Machines

Machines are defined exclusively via the `casts()` method. The syntax is `MachineClass::class . ':contextKey'`:

<!-- doctest-attr: ignore -->
```php
class Order extends Model
{
    use HasMachines;

    protected function casts(): array
    {
        return [
            'status'  => OrderStatusMachine::class . ':order',
            'payment' => PaymentMachine::class . ':order',
        ];
    }
}
```

### Context Key

The context key injects the Eloquent model into the machine's context, making it accessible inside behaviors:

<!-- doctest-attr: ignore -->
```php
'status' => OrderStatusMachine::class . ':order',
//                                       ^^^^^^
//                           This becomes $context->order
```

In behaviors:

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
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

## Polymorphic Machines

Use `PolymorphicMachineCast` when the machine class must be resolved at runtime — for example, when different countries or sales channels use different machine definitions for the same column.

### Method-Based Resolver

Define a method on your model that returns the machine FQCN. The resolver method should be cheap — read raw attributes, no DB queries:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Casts\PolymorphicMachineCast;

class Application extends Model
{
    use HasMachines;

    protected function casts(): array
    {
        return [
            'application_mre' => PolymorphicMachineCast::class . ':machineResolver,application',
            //                                                    ^^^^^^^^^^^^^^^ ^^^^^^^^^^^^^
            //                                                    resolver method  context key
        ];
    }

    /**
     * Resolve machine class based on sales channel and country.
     */
    public function machineResolver(): string
    {
        return match ($this->getRawOriginal('sales_channel_id')) {
            SalesChannelType::ConsumerGoods->value => ConsumerGoodsApplicationMachine::class,
            default => match (CountryEnum::getCurrent()) {
                CountryEnum::TR => ApplicationMachine::class,
                CountryEnum::RO => RomaniaApplicationMachine::class,
            }
        };
    }
}
```

### Column-Based Resolver

If a database column stores the machine class FQCN directly, reference that column name as the resolver key:

<!-- doctest-attr: ignore -->
```php
class Order extends Model
{
    use HasMachines;

    protected function casts(): array
    {
        return [
            // 'machine_type' column stores the FQCN directly
            'status_mre' => PolymorphicMachineCast::class . ':machine_type,order',
        ];
    }
}
```

## Database Column

Each machine stores its `root_event_id` in a ULID column:

<!-- doctest-attr: ignore -->
```php
// Migration
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->ulid('status')->nullable();  // Stores root_event_id
    $table->ulid('payment')->nullable();
    $table->timestamps();
});
```

## Using Machines

### Basic Usage

<!-- doctest-attr: ignore -->
```php
// Create order — machine auto-initializes via bootHasMachines()
$order = Order::create(['name' => 'Order #1']);

// Access machine — first property/method access triggers DB restore
$order->status->send(['type' => 'SUBMIT']);

// Check state
$order->status->state->matches('submitted'); // true

// Access context
$order->status->state->context->order; // The Order model
```

### Sending Events

<!-- doctest-attr: ignore -->
```php
$order = Order::find(1);

// Send an event to the machine
$order->status->send(['type' => 'SUBMIT']);

// Send with payload
$order->status->send([
    'type'    => 'SUBMIT',
    'payload' => ['priority' => 'high'],
]);
```

### Checking State

<!-- doctest-attr: ignore -->
```php
$order->status->state->matches('processing'); // true or false
$order->status->state->currentStateDefinition->id; // 'processing'
```

## Lazy Loading & Caching

Machines use PHP 8.4 lazy proxies. The machine instance is never restored from the database until you access a property or call a method on it. Once restored, it is cached for the remainder of the request via Laravel's `$classCastCache`.

| Operation | DB Query? | Proxy Initialized? | Notes |
|---|---|---|---|
| `Model::find(1)` | No | — | Normal Eloquent load |
| `$model->name` | No | — | Non-machine attribute, no overhead |
| `$model->status` | No | No | Returns lazy proxy from `$classCastCache` |
| `$model->status->state` | **Yes** | **Yes** | First property access triggers restore |
| `$model->status->send(...)` | **Yes** | **Yes** | First method call triggers restore |
| `$model->status` (2nd access) | No | Already done | `$classCastCache` returns same proxy |
| `$model->toArray()` | No | No | `serialize()` reads `$attributes` directly |
| `$model->toJson()` | No | No | Same — goes through `serialize()` |
| `$model->hasMachine('status')` | No | No | Reads raw attribute |
| `$model->getMachineId('status')` | No | No | Returns raw ULID string |
| `$model->refreshMachine('status')` | No | No | Clears cache, returns new lazy proxy |
| `$model->refreshMachine('status')->state` | **Yes** | **Yes** | Clears cache + forces restore via property access |
| `$machine->refresh()` | **Yes** | Already done | Re-restores state in place from DB |

::: tip Zero-Cost Serialization
Calling `$model->toArray()` or `$model->toJson()` on a model with machine attributes will **never** trigger a database query, even if you have multiple machines. The raw ULID string is returned directly from the model's attributes.
:::

## Serialization

`toArray()` and `toJson()` return the raw `root_event_id` string for machine attributes. They never trigger machine restoration:

<!-- doctest-attr: ignore -->
```php
$order = Order::find(1);

$order->toArray();
// ['id' => 1, 'status' => '01HX5N3K...', 'name' => 'Order #1', ...]

$order->toJson();
// {"id":1,"status":"01HX5N3K...","name":"Order #1",...}
```

This is safe even after the machine has been accessed and restored — `serialize()` always reads from the raw `$attributes`, not from the proxy:

<!-- doctest-attr: ignore -->
```php
$order->status->send(['type' => 'SUBMIT']); // Machine restored and used
$order->toArray(); // Still returns raw ULID string, no additional queries
```

## Machine Helpers

The `HasMachines` trait provides helper methods that do **not** trigger machine restoration:

### `hasMachine(string $attribute): bool`

Check if a machine attribute has been initialized (has a `root_event_id`):

<!-- doctest-attr: ignore -->
```php
if ($order->hasMachine('status')) {
    $order->status->send(['type' => 'SUBMIT']);
}
```

### `getMachineId(string $attribute): ?string`

Get the raw `root_event_id` without triggering restore:

<!-- doctest-attr: ignore -->
```php
$rootEventId = $order->getMachineId('status');
// '01HX5N3KZQP8YJ2...'
```

### `refreshMachine(string $attribute): Machine`

Force re-restore a machine from the database. Clears the cached lazy proxy so the next property/method access triggers a fresh DB query:

<!-- doctest-attr: ignore -->
```php
// Clear cache — next access will re-restore from DB
$order->refreshMachine('status');

// Or chain directly — triggers immediate restore
$order->refreshMachine('status')->state->matches('processing');
```

### `Machine::refresh(): self`

Re-restore state from the database on an already-initialized machine instance:

<!-- doctest-attr: ignore -->
```php
$machine = $order->status; // Lazy proxy
$machine->send(['type' => 'SUBMIT']); // Proxy initialized

// Later — re-read state from DB (e.g., after external changes)
$machine->refresh();
$machine->state->matches('submitted'); // Reflects latest DB state
```

## Auto-Initialization

When a model is created, `bootHasMachines()` automatically initializes all static (non-polymorphic) machine casts:

<!-- doctest-attr: ignore -->
```php
$order = Order::create(['name' => 'Order #1']);

// status column now contains the root_event_id
$order->getMachineId('status'); // '01HX5N3K...'

// Access the machine
$order->status->state->matches('pending'); // true
```

If a machine attribute already has a value (e.g., passed explicitly during creation), auto-initialization is skipped for that attribute:

<!-- doctest-attr: ignore -->
```php
$order = Order::create([
    'name'   => 'Order #1',
    'status' => $existingRootEventId, // Preserved — not overwritten
]);
```

::: warning Polymorphic Machines
`PolymorphicMachineCast` machines are **not** auto-initialized during model creation. The resolver may depend on other attributes that are not yet set during the `creating` event. Initialize polymorphic machines explicitly after creation.
:::

## Multiple Machines

A model can have multiple independent machines. Each is lazily loaded and cached independently — accessing one machine does **not** trigger restoration of the others:

<!-- doctest-attr: ignore -->
```php
class Order extends Model
{
    use HasMachines;

    protected function casts(): array
    {
        return [
            'order_status' => OrderStatusMachine::class . ':order',
            'payment'      => PaymentMachine::class . ':order',
            'fulfillment'  => FulfillmentMachine::class . ':order',
        ];
    }
}

// Migration
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->ulid('order_status')->nullable();
    $table->ulid('payment')->nullable();
    $table->ulid('fulfillment')->nullable();
    // ...
});

// Usage — only the accessed machine is restored
$order->order_status->send(['type' => 'CONFIRM']);  // Restores order_status only
$order->payment->send(['type' => 'CHARGE']);         // Restores payment only
$order->fulfillment->send(['type' => 'SHIP']);       // Restores fulfillment only
```

## Querying by State

Since state is stored as a `root_event_id`, you need to join with `machine_events` to query by state:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Models\MachineEvent;

// Get orders in 'processing' state
$processingOrders = Order::whereHas('machineEvents', function ($query) {
    $query->where('machine_value', 'like', '%processing%');
})->get();

// Or via subquery
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
'order_status' => OrderStatusMachine::class . ':order',  // Clear
'status'       => OrderStatusMachine::class . ':order',  // OK
's'            => OrderStatusMachine::class . ':order',  // Avoid
```

### 2. Create Helper Methods

```php
use Illuminate\Database\Eloquent\Model; // [!code hide]
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

### 3. Use `hasMachine()` for Optional Machines

<!-- doctest-attr: ignore -->
```php
// Check before accessing — avoids working with a null value
if ($quotation->hasMachine('quotation_mre')) {
    $quotation->quotation_mre->send($event);
}
```

### 4. Handle Machine Errors in Controllers

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

### 5. Avoid Unnecessary Restoration

<!-- doctest-attr: ignore -->
```php
// Bad — triggers restore just to get the ID
$id = $order->status->state->history->first()->root_event_id;

// Good — reads raw attribute, zero queries
$id = $order->getMachineId('status');
```

## Testing Eloquent Integration

<!-- doctest-attr: ignore -->
```php
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists machine state via Eloquent', function (): void {
    $order = Order::create(['name' => 'Order #1']);

    // Access machine via cast
    $order->status->send(['type' => 'SUBMIT']);

    // Refresh and verify
    $order->refresh();
    expect($order->status->state->matches('submitted'))->toBeTrue();
});

it('does not trigger machine restore during serialization', function (): void {
    $order = Order::create(['name' => 'Order #1']);

    DB::enableQueryLog();
    $array = $order->toArray();
    $queries = DB::getQueryLog();

    // toArray() should not query machine_events
    expect(collect($queries)->filter(
        fn ($q) => str_contains($q['query'], 'machine_events')
    ))->toBeEmpty();

    // status should be raw ULID string
    expect($array['status'])->toBeString();
});

it('lazily restores machine on first access', function (): void {
    $order = Order::create(['name' => 'Order #1']);
    $order = Order::find($order->id); // Fresh load

    DB::enableQueryLog();
    $order->status->state->matches('pending');
    $queries = DB::getQueryLog();

    // Exactly one machine_events query
    expect(collect($queries)->filter(
        fn ($q) => str_contains($q['query'], 'machine_events')
    ))->toHaveCount(1);
});
```

::: tip Full Testing Guide
See [Persistence Testing](https://eventmachine.dev/testing/persistence-testing) for more examples.
:::

::: tip Detailed Guide
For comprehensive design guidelines with Do/Don't examples, see [Best Practices Overview](https://eventmachine.dev/best-practices/).
:::
