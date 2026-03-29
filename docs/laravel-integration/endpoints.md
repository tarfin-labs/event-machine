# HTTP Endpoints

EventMachine can turn your machine events into HTTP endpoints automatically. Define endpoints in your machine, register routes with a single call, and let the framework handle controllers, request validation, and response serialization.

## Why Endpoints?

A typical Laravel application with state machines requires a controller and route for every event:

<!-- doctest-attr: ignore -->
```php
// routes/api.php — one route per event
Route::post('/orders/{order}/submit', [OrderController::class, 'submit']);
Route::post('/orders/{order}/approve', [OrderController::class, 'approve']);
Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
Route::post('/orders/{order}/ship', [OrderController::class, 'ship']);
```

<!-- doctest-attr: ignore -->
```php
// OrderController.php — repetitive boilerplate per method
public function submit(Request $request, Order $order): JsonResponse
{
    $event = OrderSubmittedEvent::validateAndCreate($request->all());
    $state = $order->order_mre->send(event: $event);

    return response()->json(['data' => [
        'id'    => $state->history->first()?->root_event_id,
        'state' => $state->value,
        'output' => $state->context->toArray(),
    ]]);
}

public function approve(Request $request, Order $order): JsonResponse
{
    // ... same pattern, different event ...
}

public function cancel(Request $request, Order $order): JsonResponse
{
    // ... same pattern, different event ...
}
```

Every method follows the same pattern: resolve event, send to machine, return state. With EventMachine endpoints, the machine definition becomes the single source of truth:

<!-- doctest-attr: ignore -->
```php
MachineDefinition::define(
    config: [...],
    behavior: [...],
    endpoints: [
        'SUBMIT',            // POST /submit (auto-generated)
        'APPROVE',           // POST /approve
        'CANCEL',            // POST /cancel
        'SHIP',              // POST /ship
    ],
);
```

One `MachineRouter::register()` call replaces all those routes and the entire controller:

<!-- doctest-attr: ignore -->
```php
MachineRouter::register(OrderMachine::class, [
    'prefix'    => 'orders',
    'model'     => Order::class,
    'attribute' => 'order_mre',
    'modelFor'  => ['SUBMIT', 'APPROVE', 'CANCEL', 'SHIP'],
]);
```

## Defining Endpoints

Endpoints are defined as the fourth parameter of `MachineDefinition::define()`:

<!-- doctest-attr: ignore -->
```php
MachineDefinition::define(
    config: [...],
    behavior: [...],
    scenarios: null,
    endpoints: [
        // ... endpoint definitions ...
    ],
);
```

### Definition Formats

EventMachine supports four formats for defining endpoints, from minimal to fully configured:

**1. List — auto-generate everything:**

```php ignore
'SUBMIT',
// POST /submit — URI and method auto-generated

SubmitEvent::class,
// POST /submit — resolves event type via getType()
```

**2. String — explicit URI:**

```php ignore
'SUBMIT' => '/custom-submit',
// POST /custom-submit — custom URI, default POST method
```

**3. Array — full configuration:**

```php ignore
'APPROVE' => [
    'uri'        => '/approve',           // optional — auto-generated if omitted
    'method'     => 'PATCH',              // optional — default: POST
    'action'     => ApproveEndpointAction::class,  // optional
    'output'     => 'approvalOutput',     // optional — inline key or FQCN
    'middleware'  => ['auth:admin'],       // optional — additive
    'status'     => 200,                  // optional — default: 200
],
```

```php ignore
// GET endpoint — query params wrapped into payload automatically
'STATUS_REQUESTED' => [
    'uri'    => '/status',
    'method' => 'GET',
],
```

**4. Event class key — use class instead of type string:**

```php ignore
SubmitEvent::class => '/custom-submit',
// Resolves to event type via getType(), explicit URI

SubmitEvent::class => ['method' => 'PATCH'],
// Resolves to event type via getType(), full config
```

All four formats can be mixed freely in the same `endpoints` array.

### Array Configuration Options

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `uri` | `string` | Auto-generated | URI path for the endpoint |
| `method` | `string` | `'POST'` | HTTP method. For `GET`, query parameters are automatically normalized into `payload` — see [GET Endpoints](#get-endpoints) |
| `action` | `string` | `null` | `MachineEndpointAction` subclass FQCN |
| `output` | `string` | `null` | OutputBehavior inline key or FQCN |
| `middleware` | `array` | `[]` | Per-event middleware (additive) |
| `status` | `int` | `200` | HTTP status code |
| `available_events` | `bool` | `true` | Include `available_events` in the default response |

### GET Endpoints

When an endpoint uses `'method' => 'GET'`, request data comes from query parameters instead of a JSON body. EventMachine automatically wraps query parameters into the `payload` key so your validation rules work the same way for both GET and POST:

```
GET /status?dealer_code=ABC123&plate_number=34XY
```

The query parameters are normalized to:

```php ignore
['payload' => ['dealer_code' => 'ABC123', 'plate_number' => '34XY']]
```

This means your `EventBehavior` validation rules target `payload.*` regardless of HTTP method:

<!-- doctest-attr: ignore -->
```php
class StatusRequestedEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'STATUS_REQUESTED';
    }

    public static function rules(): array
    {
        return [
            'payload.dealer_code'  => ['required', 'string'],
            'payload.plate_number' => ['required', 'string'],
        ];
    }
}
```

**When to use GET endpoints:** Read-only queries, status lookups, and search endpoints where data is passed via query parameters.

::: tip Query Parameter Types
Query parameter values are always strings. Use validation rules like `'numeric'` or `'integer'` when you need numeric values — Laravel's validator handles string-to-number coercion.
:::

If a GET request explicitly uses the `payload[]` bracket syntax (`?payload[key]=value`), the automatic wrapping is skipped to avoid double-nesting.

### URI Auto-Generation

When no URI is specified, EventMachine converts the event type from `SCREAMING_SNAKE_CASE` to `kebab-case`. If the event type ends with `_EVENT`, that suffix is automatically stripped:

| Event Type | Generated URI |
|------------|--------------|
| `SUBMIT` | `/submit` |
| `FARMER_SAVED` | `/farmer-saved` |
| `APPROVED_WITH_INITIATIVE` | `/approved-with-initiative` |
| `CONSENT_GRANTED_EVENT` | `/consent-granted` |

## Route Registration

Register machine endpoints in your `routes/api.php` (or a dedicated route file):

```php no_run
use Tarfinlabs\EventMachine\Routing\MachineRouter;

MachineRouter::register(OrderMachine::class, [
    'prefix'       => 'orders',
    'model'        => Order::class,
    'attribute'    => 'order_mre',
    'create'       => true,
    'machineIdFor' => ['START'],
    'modelFor'     => ['SUBMIT', 'APPROVE'],
    'middleware'    => ['auth:api'],
    'name'         => 'machines.order',
]);
```

### Router Options

| Option | Type | Required | Default | Description |
|--------|------|----------|---------|-------------|
| `prefix` | `string` | Yes | — | URL prefix for all endpoints |
| `model` | `string` | No | `null` | Eloquent model class (required when `modelFor` is set) |
| `attribute` | `string` | No | `null` | `HasMachines` property name on the model (required when `modelFor` is set) |
| `create` | `bool` | No | `false` | Enable `POST /create` endpoint |
| `machineIdFor` | `array` | No | `[]` | Event types routed by machine ID |
| `modelFor` | `array` | No | `[]` | Event types routed by Eloquent model binding |
| `middleware` | `array` | No | `[]` | Middleware applied to all endpoints |
| `name` | `string` | No | Machine ID | Route name prefix |
| `only` | `array` | No | `null` | Register only these event endpoints (whitelist) |
| `except` | `array` | No | `null` | Register all except these event endpoints (blacklist) |

Both `machineIdFor` and `modelFor` accept event type strings (`'SUBMIT'`) or event class references (`SubmitEvent::class`). Events not listed in either array are routed as stateless. `only` and `except` are mutually exclusive and accept the same formats. See [Endpoint Filtering](#endpoint-filtering) for details.

### Generated Routes

Given the registration above, EventMachine generates these routes:

| Method | URI | Handler | Route Name |
|--------|-----|---------|------------|
| POST | `/orders/create` | handleCreate | `machines.order.create` |
| POST | `/orders/{machineId}/start` | handleMachineIdBound | `machines.order.start` |
| POST | `/orders/{order}/submit` | handleModelBound | `machines.order.submit` |
| POST | `/orders/{order}/approve` | handleModelBound | `machines.order.approve` |

### Three Handler Types

Each endpoint is routed to a handler based on your `machineIdFor` and `modelFor` configuration:

| Handler | When |
|---------|------|
| `handleMachineIdBound` | Event is in `machineIdFor` |
| `handleModelBound` | Event is in `modelFor` |
| `handleStateless` | Event is in neither |

**handleModelBound** resolves the Eloquent model via route model binding, loads the machine from the model attribute, and sends the event.

**handleMachineIdBound** loads the machine directly from a `root_event_id` parameter in the URL. Use this for events that happen before a model exists (e.g., the first step in a workflow).

**handleStateless** creates a fresh machine for every request with no persistence. The machine processes the event, returns the output, and is garbage collected. Ideal for computation endpoints like price calculators.

## Default Response

When no `output` is specified, the endpoint returns the machine state as JSON:

<!-- doctest-attr: ignore -->
```json
{
    "data": {
        "id": "01JARX5Z8KQVN...",
        "state": ["submitted"],
        "output": {
            "totalAmount": 15000,
            "customerEmail": "user@example.com"
        },
        "availableEvents": [
            { "type": "APPROVE", "source": "parent" },
            { "type": "CANCEL", "source": "parent" }
        ],
        "isProcessing": false
    }
}
```

If your context class overrides `computedContext()`, computed values are automatically included in the `context` object alongside regular properties. See [Exposing Computed Values](/advanced/custom-context#exposing-computed-values-in-api-responses) for details.

The `availableEvents` array uses HATEOAS-style discoverability — the response tells the consumer which events the machine can accept in its current state. Each entry includes a `type` (the event name to send) and a `source` (`parent` for direct events, `forward` for forwarded child events). See the [Available Events](./available-events) page for full details.

By default `availableEvents` is included in every response. To opt out for a specific endpoint, set `available_events` to `false` in its array config.

For parallel states, `state` contains multiple active state paths and each available event includes a `region` key:

<!-- doctest-attr: ignore -->
```json
{
    "data": {
        "id": "01JARX5Z8KQVN...",
        "state": [
            "fulfillment.payment.pending",
            "fulfillment.shipping.preparing",
            "fulfillment.documents.awaiting"
        ],
        "output": {},
        "availableEvents": [
            { "type": "PAY", "source": "parent", "region": "payment" },
            { "type": "SHIP", "source": "parent", "region": "shipping" },
            { "type": "UPLOAD_DOC", "source": "parent", "region": "documents" }
        ],
        "isProcessing": false
    }
}
```

## Custom Responses with OutputBehavior

Override the default response by referencing an `OutputBehavior` in your endpoint definition. This reuses the existing behavior system — no new concepts needed.

```php no_run
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\ContextManager;

class OrderDetailEndpointOutput extends OutputBehavior
{
    public function __invoke(ContextManager $context): array
    {
        $order = $context->get('order');

        return [
            'id'     => $order->id,
            'status' => $order->status,
            'items'  => $order->items->toArray(),
            'total'  => $context->get('totalAmount'),
        ];
    }
}
```

Reference the output in your endpoint definition by inline key or FQCN:

<!-- doctest-attr: ignore -->
```php
// By inline key (must be registered in behavior.outputs)
'ORDER_SUBMITTED' => [
    'output' => 'orderDetailEndpointOutput',
],

// By FQCN (resolved directly)
'ORDER_SUBMITTED' => [
    'output' => OrderDetailEndpointOutput::class,
],
```

::: tip Reusing OutputBehavior
Endpoint outputs extend the same `OutputBehavior` base class used by `$machine->output()`. If you already have an output behavior for your machine, you can reference it directly in your endpoint definition — no duplication needed.
:::

The `__invoke()` method supports dependency injection. You can type-hint `ContextManager`, `State`, or any service from Laravel's container:

```php no_run
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\ContextManager;

class InvoiceEndpointOutput extends OutputBehavior
{
    public function __construct(
        private InvoiceService $invoices,
    ) {}

    public function __invoke(ContextManager $context): array
    {
        return $this->invoices->generateSummary(
            $context->get('orderId'),
        );
    }
}
```

## EndpointAction Lifecycle

`MachineEndpointAction` provides lifecycle hooks that run in the HTTP layer, outside the machine's internal transition pipeline. This is the right place for concerns like cache locks, authorization, and exception handling.

```
HTTP Request
|
+-- Route middleware
+-- Model binding / Machine loading
+-- Event resolution + validation
|
+-- === action.before() ===
|       $this->state = pre-transition state
|
+-- try {
|       $machine->send($event)
|       Guards -> Actions -> Context changes -> State transition
|   }
|
+-- catch (MachineAlreadyRunningException) {
|       GET  → 200 + state snapshot + isProcessing: true
|       POST → 423 + state snapshot + isProcessing: true
|       action.after() and action.onException() are NOT called
|   }
|
+-- catch (Throwable $e) {
|       === action.onException($e) ===
|       null -> exception re-thrown
|       JsonResponse -> returned as HTTP response
|   }
|
+-- === action.after() ===
|       $this->state = post-transition state
|
+-- OutputBehavior (if defined) or State::toArray()
|
+-- JSON Response
```

### before()

Runs **before** `$machine->send()`. Access `$this->machine` and `$this->state` (pre-transition). Use for authorization checks, cache lock acquisition, or pre-send validation. Call `abort()` to stop the request.

### after()

Runs **after** `$machine->send()` completes successfully. `$this->state` is updated to the post-transition state. Use for lock release, logging, or post-transition side effects.

### onException()

Runs when `$machine->send()` throws an exception. Return `null` to re-throw the exception, or return a `JsonResponse` to handle it gracefully.

### Cache Lock Example

```php no_run
use Illuminate\Support\Facades\Cache;
use Illuminate\Cache\Lock;
use Illuminate\Http\JsonResponse;
use Tarfinlabs\EventMachine\Routing\MachineEndpointAction;

class StartEndpointAction extends MachineEndpointAction
{
    private Lock $lock;

    public function before(): void
    {
        $nin = request()->input('nin');
        $this->lock = Cache::lock("application:{$nin}", 10);
        abort_unless($this->lock->block(5), 409, 'Resource is locked.');
    }

    public function after(): void
    {
        $this->lock->release();
    }

    public function onException(\Throwable $e): ?JsonResponse
    {
        $this->lock?->release();

        return null; // re-throw the exception
    }
}
```

Reference the action in your endpoint definition:

<!-- doctest-attr: ignore -->
```php
'START' => [
    'action' => StartEndpointAction::class,
],
```

## Lock Contention Handling

A race condition occurs when the machine is already processing an event (lock held) and another HTTP request arrives for the same machine instance. The most common scenario: a `BroadcastStateAction` fires during state entry, the frontend receives the broadcast and immediately calls `GET /status`, but the lock is still held because the macrostep has not finished.

When `$machine->send()` fails to acquire the lock, EventMachine catches the `MachineAlreadyRunningException` and returns a response based on the HTTP method:

- **GET** endpoints: return HTTP **200** with the last committed state and `isProcessing: true`.
- **POST / PUT / DELETE** endpoints: return HTTP **423 Locked** with the last committed state and `isProcessing: true`. The event was **not** processed.

The `isProcessing` field is always present in every endpoint response. It is `false` on the normal path and `true` when the response was served from contention handling.

The returned state is internally consistent. `$machine->send()` restores the machine from the database before attempting to acquire the lock, so `$machine->state` holds the latest committed snapshot even when the lock cannot be acquired.

<!-- doctest-attr: ignore -->
```json
{
    "data": {
        "id": "01JARX5Z8KQVN...",
        "state": ["submitted"],
        "output": {
            "totalAmount": 15000
        },
        "availableEvents": [
            { "type": "APPROVE", "source": "parent" }
        ],
        "isProcessing": true
    }
}
```

During contention, `action.after()` and `action.onException()` lifecycle hooks are **not** called. The response is returned directly from the exception handler.

**Frontend pattern:** use `isProcessing` to show a loading spinner and wait for the next broadcast event. Once the machine finishes processing, it will broadcast the updated state, and the frontend can refresh.

## Create Endpoint

Enable a `POST /create` endpoint to bootstrap a new machine instance:

<!-- doctest-attr: ignore -->
```php
MachineRouter::register(OrderMachine::class, [
    'prefix'    => 'orders',
    'model'     => Order::class,
    'attribute' => 'order_mre',
    'modelFor'  => ['SUBMIT', 'APPROVE'],
    'create'    => true,   // Enables POST /orders/create
]);
```

The create endpoint:
1. Instantiates a fresh machine
2. Persists the initial state
3. Returns a `201 Created` response with the machine ID

<!-- doctest-attr: ignore -->
```json
{
    "data": {
        "id": "01JARX5Z8KQVN...",
        "state": ["idle"],
        "output": {
            "totalAmount": 0,
            "items": []
        }
    }
}
```

Use the returned `id` in subsequent requests to send events to this machine instance via `machineIdFor` endpoints.

## Route Registration Patterns

`MachineRouter::register()` supports four routing patterns. Each endpoint is routed to a specific handler based on the `machineIdFor` and `modelFor` options. Any pattern can be combined with [`only`/`except`](#endpoint-filtering) to register a subset of endpoints:

| Condition | Handler | URI Pattern |
|-----------|---------|-------------|
| Event is in `machineIdFor` | `handleMachineIdBound` | `/{machineId}{uri}` |
| Event is in `modelFor` | `handleModelBound` | `/{model}{uri}` |
| Neither | `handleStateless` | `{uri}` |
| Forwarded (model-bound parent) | `handleForwardedModelBound` | `/{model}{uri}` |
| Forwarded (machineId-bound parent) | `handleForwardedMachineIdBound` | `/{machineId}{uri}` |

::: tip Forwarded Routes
Forwarded routes from the `forward` config appear in the route table alongside explicit endpoints. They are auto-discovered at definition time — no `endpoints` entry is needed for forwarded events.
:::

### Pattern 1: Stateless

For machines that don't need persistence (e.g., calculators, validators). Every request creates a fresh machine, processes the event, returns the output, and discards the machine. Omit both `machineIdFor` and `modelFor`:

```php no_run
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Actor\Machine;

class PriceCalculatorMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'price_calculator',
                'initial'        => 'idle',
                'should_persist' => false,
                'states'         => [
                    'idle'       => ['on' => ['CALCULATE' => 'calculated']],
                    'calculated' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'CALCULATE' => CalculateEvent::class,
                ],
                'outputs' => [
                    'priceEndpointOutput' => PriceEndpointOutput::class,
                ],
            ],
            endpoints: [
                'CALCULATE' => [
                    'output' => 'priceEndpointOutput',
                ],
            ],
        );
    }
}
```

Register without `machineIdFor`, `modelFor`, or `create`:

<!-- doctest-attr: ignore -->
```php
MachineRouter::register(PriceCalculatorMachine::class, [
    'prefix'     => 'calculator',
    'middleware'  => ['auth:api'],
]);
// POST /calculator/calculate -> fresh machine -> send -> output -> GC
```

### Pattern 2: MachineId-Bound (Without Model)

For workflows that need state persistence but don't require an Eloquent model. Use `create` to bootstrap a machine and `machineIdFor` to route events by machine ID:

<!-- doctest-attr: ignore -->
```php
MachineRouter::register(OrderMachine::class, [
    'prefix'       => 'orders',
    'create'       => true,
    'machineIdFor' => ['SUBMIT_ORDER', 'APPROVE_ORDER', 'CANCEL_ORDER'],
]);
```

This generates:

| Method | URI | Handler |
|--------|-----|---------|
| POST | `/orders/create` | handleCreate |
| POST | `/orders/{machineId}/submit-order` | handleMachineIdBound |
| POST | `/orders/{machineId}/approve-order` | handleMachineIdBound |
| POST | `/orders/{machineId}/cancel-order` | handleMachineIdBound |

The typical flow:
1. `POST /orders/create` — returns `machine_id` with `201 Created`
2. `POST /orders/{machineId}/submit-order` — restores machine from DB, sends event
3. `POST /orders/{machineId}/approve-order` — continues the workflow

This is ideal when your API is machine-centric rather than model-centric — the client tracks the `machine_id` returned from `create` and uses it in all subsequent requests.

### Pattern 3: Model-Bound

For machines tied to an Eloquent model. Use `modelFor` to specify which events are routed by model binding. The model's attribute stores the machine's root event ID, and the `MachineCast` restores the machine automatically:

<!-- doctest-attr: ignore -->
```php
MachineRouter::register(InvoiceMachine::class, [
    'prefix'    => 'invoices',
    'model'     => Invoice::class,
    'attribute' => 'invoice_mre',
    'modelFor'  => ['SEND', 'PAY'],
]);
```

This generates:

| Method | URI | Handler |
|--------|-----|---------|
| POST | `/invoices/{invoice}/send` | handleModelBound |
| POST | `/invoices/{invoice}/pay` | handleModelBound |

The model must use the machine cast so `handleModelBound` can access the machine instance:

<!-- doctest-attr: ignore -->
```php
class Invoice extends Model
{
    use HasMachines;

    protected $casts = [
        'invoice_mre' => InvoiceMachine::class.':invoice',
    ];
}
```

### Pattern 4: Hybrid (machineIdFor + modelFor)

Some workflows require sending events before an Eloquent model exists. For example, the first step might create the model as a side effect. Use `machineIdFor` for pre-model events and `modelFor` for model-bound events:

<!-- doctest-attr: ignore -->
```php
MachineRouter::register(ApplicationMachine::class, [
    'prefix'       => 'machines/application',
    'model'        => Application::class,
    'attribute'    => 'application_mre',
    'create'       => true,
    'machineIdFor' => ['START'],
    'modelFor'     => ['FARMER_SAVED', 'CANCEL'],
    'middleware'    => ['auth:api'],
]);
```

This generates:

| Method | URI | Handler |
|--------|-----|---------|
| POST | `/machines/application/create` | handleCreate |
| POST | `/machines/application/{machineId}/start` | handleMachineIdBound |
| POST | `/machines/application/{application}/farmer-saved` | handleModelBound |

The typical flow:
1. `POST /create` — returns `machine_id`
2. `POST /{machineId}/start` — sends START event, which creates the model as a side effect
3. `POST /{application}/farmer-saved` — model now exists, uses model binding

## Endpoint Filtering

When the same machine serves multiple consumer groups with different authentication requirements, use `only` or `except` to control which endpoints are registered per route group.

### Splitting Endpoints Between Consumer Groups

```php no_run
use Tarfinlabs\EventMachine\Routing\MachineRouter;

// Public endpoints (no auth) — customer-facing
MachineRouter::register(CarSalesMachine::class, [
    'prefix'       => 'car-sales',
    'only'         => [
        ConsentGrantedEvent::class,
        PersonalInfoSubmittedEvent::class,
    ],
    'machineIdFor' => [
        ConsentGrantedEvent::class,
        PersonalInfoSubmittedEvent::class,
    ],
    'name' => 'car-sales.public',
]);

// Protected endpoints (auth:retailer) — dealer panel
MachineRouter::register(CarSalesMachine::class, [
    'prefix'       => 'machines/car-sales',
    'middleware'    => ['auth:retailer'],
    'except'       => [
        ConsentGrantedEvent::class,
        PersonalInfoSubmittedEvent::class,
    ],
    'create'       => true,
    'machineIdFor' => [
        VehicleSubmittedEvent::class,
        AllocationApprovedEvent::class,
    ],
    'name' => 'machines.car-sales',
]);
```

`only` registers **only** the listed event endpoints (whitelist). `except` registers all endpoints **except** the listed ones (blacklist). They are mutually exclusive — using both throws an `InvalidRouterConfigException`.

Both accept event type strings (`'SUBMIT'`) or event class references (`SubmitEvent::class`), same as `machineIdFor` and `modelFor`.

### `create` is Independent

The `create` endpoint is controlled by the `create: bool` option, not by `only`/`except`. This allows create-only registrations:

```php no_run
use Tarfinlabs\EventMachine\Routing\MachineRouter;

// Create-only registration: no event endpoints
MachineRouter::register(OrderMachine::class, [
    'prefix' => 'admin/orders',
    'create' => true,
    'only'   => [],     // no event endpoints, only POST /create
]);
```

### Forwarded Endpoints

Forwarded endpoints are filtered by their **parent-facing event type** — the same identifier used in route names and URIs:

```php no_run
use Tarfinlabs\EventMachine\Routing\MachineRouter;

// Only forward PROVIDE_CARD, exclude CONFIRM_PAYMENT
MachineRouter::register(OrderMachine::class, [
    'prefix' => 'orders',
    'only'   => ['START', 'PROVIDE_CARD'],
    'machineIdFor' => ['START'],
]);
```

Forwarded endpoints **cannot** appear in `machineIdFor` or `modelFor` — they inherit binding mode from the parent's global model config.

### Router Validation

`MachineRouter::register()` validates all options at route registration time. Violations throw `InvalidRouterConfigException`:

| Rule | Throws When |
|------|-------------|
| `only` + `except` mutually exclusive | Both are provided in the same registration |
| Orphaned `machineIdFor` refs | A `machineIdFor` entry references a filtered-out or nonexistent event |
| Orphaned `modelFor` refs | A `modelFor` entry references a filtered-out or nonexistent event |
| Forwarded events in `machineIdFor`/`modelFor` | Forwarded endpoints inherit binding from parent config |
| Unknown event types in `only`/`except` | An event type doesn't match any defined endpoint |

Endpoint definitions are also validated at definition time. `InvalidEndpointDefinitionException` is thrown when an endpoint references an undefined event type, a missing output behavior, an invalid action, or has forward event conflicts (e.g., a forwarded event collides with a behavior-defined event or another forward).

## Per-Event Middleware

Endpoint-level middleware is **additive** — it stacks on top of the router-level middleware:

<!-- doctest-attr: ignore -->
```php
MachineRouter::register(OrderMachine::class, [
    'prefix'     => 'orders',
    'model'      => Order::class,
    'attribute'  => 'order_mre',
    'modelFor'   => ['SUBMIT', 'APPROVE'],
    'middleware'  => ['auth:api'],         // Applied to all endpoints
]);
```

<!-- doctest-attr: ignore -->
```php
// In your machine definition
endpoints: [
    'SUBMIT',                              // auth:api only
    'APPROVE' => [
        'middleware' => ['auth:admin'],     // auth:api + auth:admin
    ],
],
```

The `APPROVE` endpoint gets both `auth:api` (from the router) and `auth:admin` (from the endpoint definition).

## Exception Handling

EventMachine automatically converts known exceptions to appropriate HTTP responses:

| Exception | HTTP Status | When |
|-----------|------------|------|
| `MachineValidationException` | 422 Unprocessable Entity | Validation guard fails |
| `MachineAlreadyRunningException` | 409 Conflict | Concurrent event processing |
| Any other `Throwable` | 500 (or custom via `onException`) | Unexpected errors |

`MachineValidationException` is automatically caught and converted to a 422 response:

<!-- doctest-attr: ignore -->
```json
{
    "message": "The given data was invalid.",
    "errors": {
        "amount": ["The amount must be at least 100."]
    }
}
```

For other exceptions, use `EndpointAction::onException()` to handle them gracefully:

```php no_run
use Illuminate\Http\JsonResponse;
use Tarfinlabs\EventMachine\Routing\MachineEndpointAction;

class CancelEndpointAction extends MachineEndpointAction
{
    public function before(): void
    {
        $application = $this->state->context->get('application');

        abort_unless(
            $application->isCancellable(),
            422,
            'Application cannot be cancelled in current state.',
        );
    }

    public function onException(\Throwable $e): ?JsonResponse
    {
        if ($e instanceof PreventionException) {
            $e->saveLog();

            return response()->json([
                'message' => 'Operation prevented.',
                'reason'  => $e->getMessage(),
            ], 403);
        }

        return null; // re-throw other exceptions
    }
}
```

## File Organization

Organize endpoint-related classes in a dedicated `Endpoints/` directory within your machine folder:

```
app/MachineDefinitions/
└── OrderWorkflow/
    ├── OrderWorkflowMachine.php
    ├── OrderWorkflowContext.php
    ├── Actions/
    │   └── SendConfirmationEmailAction.php
    ├── Guards/
    │   └── IsPaymentValidGuard.php
    ├── Events/
    │   ├── OrderSubmittedEvent.php
    │   └── PaymentReceivedEvent.php
    ├── Outputs/
    │   └── OrderConfirmationOutput.php
    └── Endpoints/
        ├── Actions/
        │   ├── CancelEndpointAction.php
        │   └── StartEndpointAction.php
        └── Outputs/
            └── OrderDetailEndpointOutput.php
```

Machine-level behaviors (Actions, Guards, Events) live at the top level. Endpoint-specific actions and outputs live under `Endpoints/`. This separation makes it clear which classes handle HTTP concerns versus machine internals.

## Complete Example

Here is a full machine definition with endpoints, route registration, an endpoint action, and a custom output:

### Machine Definition

```php no_run
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Actor\Machine;

class ApplicationMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'application',
                'initial' => 'idle',
                'context' => ['application' => null],
                'states'  => [
                    'idle'            => ['on' => ['START' => 'started']],
                    'started'         => ['on' => ['FARMER_SAVED' => 'farmer_saved']],
                    'farmer_saved'    => ['on' => [
                        'CANCEL'          => 'cancelled',
                        'GUARANTOR_SAVED' => 'guarantor_saved',
                    ]],
                    'guarantor_saved' => ['on' => ['APPROVED_WITH_INITIATIVE' => 'approved']],
                    'approved'        => ['type' => 'final'],
                    'cancelled'       => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START'                    => ApplicationStartedEvent::class,
                    'FARMER_SAVED'             => FarmerSavedEvent::class,
                    'CANCEL'                   => ApplicationCancelEvent::class,
                    'GUARANTOR_SAVED'          => GuarantorSavedEvent::class,
                    'APPROVED_WITH_INITIATIVE' => ApprovedWithInitiativeEvent::class,
                ],
                'outputs' => [
                    'guarantorSavedEndpointOutput'         => GuarantorSavedEndpointOutput::class,
                    'approvedWithInitiativeEndpointOutput'  => ApprovedWithInitiativeEndpointOutput::class,
                ],
            ],
            endpoints: [
                'START' => [
                    'action' => StartEndpointAction::class,
                ],
                'FARMER_SAVED',
                'CANCEL'          => [
                    'action' => CancelEndpointAction::class,
                ],
                'GUARANTOR_SAVED' => [
                    'output' => 'guarantorSavedEndpointOutput',
                ],
                'APPROVED_WITH_INITIATIVE' => [
                    'method'     => 'PATCH',
                    'middleware'  => ['auth:admin'],
                    'output'     => 'approvedWithInitiativeEndpointOutput',
                ],
            ],
        );
    }
}
```

### Route Registration

```php no_run
use Tarfinlabs\EventMachine\Routing\MachineRouter;

// routes/api.php
MachineRouter::register(ApplicationMachine::class, [
    'prefix'       => 'machines/application',
    'model'        => Application::class,
    'attribute'    => 'application_mre',
    'create'       => true,
    'machineIdFor' => ['START'],
    'modelFor'     => ['FARMER_SAVED', 'CANCEL', 'GUARANTOR_SAVED', 'APPROVED_WITH_INITIATIVE'],
    'middleware'    => ['auth:retailer'],
    'name'         => 'machines.application',
]);
```

### Generated Routes

| Method | URI | Handler | Route Name |
|--------|-----|---------|------------|
| POST | `/machines/application/create` | handleCreate | `machines.application.create` |
| POST | `/machines/application/{machineId}/start` | handleMachineIdBound | `machines.application.start` |
| POST | `/machines/application/{application}/farmer-saved` | handleModelBound | `machines.application.farmer_saved` |
| POST | `/machines/application/{application}/cancel` | handleModelBound | `machines.application.cancel` |
| POST | `/machines/application/{application}/guarantor-saved` | handleModelBound | `machines.application.guarantor_saved` |
| PATCH | `/machines/application/{application}/approved-with-initiative` | handleModelBound | `machines.application.approved_with_initiative` |

### Endpoint Action

```php no_run
use Illuminate\Support\Facades\Cache;
use Illuminate\Cache\Lock;
use Illuminate\Http\JsonResponse;
use Tarfinlabs\EventMachine\Routing\MachineEndpointAction;

class StartEndpointAction extends MachineEndpointAction
{
    private Lock $lock;

    public function before(): void
    {
        $nin = request()->input('nin');
        $this->lock = Cache::lock("application:{$nin}", 10);
        abort_unless($this->lock->block(5), 409, 'Resource is locked.');
    }

    public function after(): void
    {
        $this->lock->release();
    }

    public function onException(\Throwable $e): ?JsonResponse
    {
        $this->lock?->release();

        return null;
    }
}
```

### Endpoint Output

```php no_run
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\ContextManager;

class GuarantorSavedEndpointOutput extends OutputBehavior
{
    public function __invoke(ContextManager $context): array
    {
        return [
            'application' => $context->get('application')
                ->refresh()
                ->loadMissing('guarantors')
                ->toArray(),
        ];
    }
}
```

## Forward-Aware Endpoints

When a parent machine delegates to an async child machine, the child may need user input (e.g., card details for a payment child). Normally you would declare a separate endpoint on the child machine and wire up routing manually. With forward-aware endpoints, the parent machine automatically exposes forwarded events as its own endpoints — no duplicate declarations needed.

Forward events are defined in the `forward` key of a delegating state's `machine` config. EventMachine parses them at definition time and registers routes alongside the parent's explicit endpoints.

### Forward Syntax

EventMachine supports three forward formats, from minimal to fully configured:

**Format 1 — Plain (same event type):**

```php ignore
'forward' => ['PROVIDE_CARD'],
// Parent receives PROVIDE_CARD, forwards as PROVIDE_CARD to child
```

You can also use an `EventBehavior` class reference:

```php ignore
'forward' => [ProvideCardEvent::class],
// Resolves to the event type via getType()
```

**Format 2 — Rename (different parent/child event types):**

```php ignore
'forward' => ['CANCEL_ORDER' => 'ABORT'],
// Parent receives CANCEL_ORDER, forwards as ABORT to child
```

**Format 3 — Full array (endpoint customization):**

```php ignore
'forward' => [
    'PROVIDE_CARD' => [
        'child_event'      => 'SUBMIT_CARD',      // optional — defaults to parent event type
        'uri'              => '/card',             // optional — auto-generated if omitted
        'method'           => 'PATCH',             // optional — default: POST
        'middleware'        => ['auth:customer'],   // optional — additive
        'action'           => CardEndpointAction::class,   // optional — parent-level action
        'output'           => 'cardSubmittedOutput',       // optional — OutputBehavior key, FQCN, or array of context keys
        'status'           => 200,                 // optional — default: 200
        'available_events' => true,                // optional — include available_events in response
    ],
],
```

#### Format 3 Configuration Options

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `child_event` | `string` | Parent event type | Child event type to forward to |
| `uri` | `string` | Auto-generated | URI path for the endpoint |
| `method` | `string` | `'POST'` | HTTP method |
| `middleware` | `array` | `[]` | Per-event middleware (additive) |
| `action` | `string` | `null` | `MachineEndpointAction` subclass FQCN |
| `output` | `string\|array` | `null` | OutputBehavior inline key/FQCN, or array of context keys to filter |
| `status` | `int` | `200` | HTTP status code |
| `available_events` | `bool` | `null` | Include `available_events` in response |

### Example: Payment Delegation with Forwarding

```php no_run
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Actor\Machine;

class OrderMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'order',
                'initial' => 'created',
                'context' => ['orderId' => null],
                'states'  => [
                    'created' => ['on' => ['SUBMIT' => 'processing_payment']],
                    'processing_payment' => [
                        'machine'  => PaymentMachine::class,
                        'queue'    => 'payments',
                        'with'     => ['orderId'],
                        'forward'  => ['PROVIDE_CARD', 'CANCEL_ORDER' => 'ABORT'],
                        'on'       => [
                            '@done' => 'paid',
                            '@fail' => 'payment_failed',
                        ],
                    ],
                    'paid'           => ['type' => 'final'],
                    'payment_failed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'SUBMIT' => SubmitEvent::class,
                ],
            ],
            endpoints: [
                'SUBMIT',
            ],
        );
    }
}
```

In this example, `PROVIDE_CARD` and `CANCEL_ORDER` are automatically registered as endpoints on the parent machine. No explicit `endpoints` entry is needed for them — the `forward` config is the single source of truth.

### Forwarded Endpoint Response

When no `output` is specified, forwarded endpoints return both parent and child state:

<!-- doctest-attr: ignore -->
```json
{
    "data": {
        "id": "01JARX5Z8KQVN...",
        "state": ["processing_payment"],
        "child": {
            "state": ["awaiting_verification"],
            "output": {
                "cardToken": "tok_abc123",
                "lastFour": "4242"
            }
        }
    }
}
```

Use `output` with an array in Format 3 to filter which child context keys appear in the response. When `output` is `null` (the default), all child context keys are included.

### Route Registration for Forwarded Endpoints

Forwarded routes are registered automatically by `MachineRouter::register()`. The router determines the handler based on whether the parent uses model binding or machine ID binding:

- **Model-bound parent**: forwarded routes use `/{model}/{uri}` and `handleForwardedModelBound`
- **MachineId-bound parent**: forwarded routes use `/{machineId}/{uri}` and `handleForwardedMachineIdBound`

Forwarded routes appear alongside explicit endpoints in the route table. No extra registration is needed.

Forwarded endpoints are included in [`only`/`except`](#endpoint-filtering) filtering by their parent-facing event type. They **cannot** appear in `machineIdFor`/`modelFor` — their binding mode is always inherited from the parent's global model config.

### How It Works

```
1. Parent machine definition includes:
   forward: ['PROVIDE_CARD']

2. MachineDefinition::define() parses forward config
   → creates ForwardedEndpointDefinition objects
   → discovers child's EventBehavior class

3. MachineRouter::register() auto-registers forwarded routes
   → POST /orders/{order}/provide-card

4. HTTP request hits forwarded endpoint

5. MachineController::handleForwardedModelBound()
   → Validates with child's EventBehavior class
   → Runs parent-level EndpointAction lifecycle
   → Sends event to parent machine
   → Parent internally forwards to child (tryForwardEventToChild)
   → Returns combined parent + child state
```

## ForwardContext

When a forwarded endpoint has a custom `OutputBehavior`, you often need access to the child machine's context and state. Type-hint `ForwardContext` in your output's `__invoke()` method to receive it automatically:

```php no_run
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Routing\ForwardContext;

class CardSubmittedOutput extends OutputBehavior
{
    public function __invoke(ContextManager $context, ForwardContext $forwardContext): array
    {
        return [
            'orderId'     => $context->get('orderId'),
            'cardStatus'  => $forwardContext->childContext->get('status'),
            'childState' => $forwardContext->childState->value,
        ];
    }
}
```

`ForwardContext` is a value object with two properties:

- `childContext` (`ContextManager`) — the child machine's context after the forwarded event
- `childState` (`State`) — the child machine's full state after the forwarded event

`ForwardContext` is only injected for forwarded endpoints. In regular endpoints, it is not available. The injection uses the same `InvokableBehavior` parameter resolution that all behaviors use — no special setup is needed.

## Migration Guide

Moving from traditional controllers to machine endpoints in six steps:

### Step 1: Identify Eligible Endpoints

Look for controller methods that follow the pattern: resolve event, send to machine, return response. These are candidates for endpoint migration.

### Step 2: Add Endpoint Definitions

Add the `endpoints` parameter to your machine definition:

<!-- doctest-attr: ignore -->
```php
endpoints: [
    'SUBMIT',
    'APPROVE',
    // ... one entry per event that has a controller method
],
```

### Step 3: Move Pre/Post Logic to EndpointActions

If your controller has logic before or after `$machine->send()` (cache locks, authorization, logging), create an `EndpointAction`:

<!-- doctest-attr: ignore -->
```php
// Before: in controller
public function submit(Request $request, Order $order): JsonResponse
{
    $lock = Cache::lock("order:{$order->id}", 10);
    abort_unless($lock->block(5), 409);

    $state = $order->order_mre->send(event: $event);

    $lock->release();

    return response()->json([...]);
}

// After: in EndpointAction
'SUBMIT' => [
    'action' => SubmitEndpointAction::class,
],
```

### Step 4: Move Response Customization to OutputBehavior

If your controller returns something other than the default state JSON, create an `OutputBehavior`:

<!-- doctest-attr: ignore -->
```php
// Before: in controller
return new OrderResource($order->refresh()->loadMissing('items'));

// After: in OutputBehavior
'SUBMIT' => [
    'output' => 'orderDetailEndpointOutput',
],
```

### Step 5: Register Routes

Replace your manual route definitions with `MachineRouter::register()`:

<!-- doctest-attr: ignore -->
```php
// Before: manual routes
Route::post('/orders/{order}/submit', [OrderController::class, 'submit']);
Route::post('/orders/{order}/approve', [OrderController::class, 'approve']);
Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);

// After: single registration
MachineRouter::register(OrderMachine::class, [
    'prefix'    => 'orders',
    'model'     => Order::class,
    'attribute' => 'order_mre',
    'modelFor'  => ['SUBMIT', 'APPROVE', 'CANCEL'],
]);
```

### Step 6: Remove Old Controllers

Once all routes are migrated and tests pass, delete the old controller classes and their route definitions.

::: tip Incremental Migration
You don't have to migrate all events at once. Only events listed in the `endpoints` array get auto-generated routes. Keep your existing controllers for events you haven't migrated yet, and move them one at a time.
:::

## Testing Endpoints

<!-- doctest-attr: ignore -->
```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Routing\MachineRouter;

uses(RefreshDatabase::class);

it('accepts event via endpoint', function (): void {
    MachineRouter::register(OrderMachine::class, 'orders', 'order_mre');
    $order = Order::create(['status' => 'pending']);

    $response = $this->postJson("/orders/{$order->id}/submit", [
        'paymentMethod' => 'card',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.value.0', 'order.submitted');
});
```

When testing a subset of endpoints, use `only` to register only the routes your test needs:

<!-- doctest-attr: ignore -->
```php
MachineRouter::register(OrderMachine::class, [
    'prefix'       => 'orders',
    'only'         => ['SUBMIT'],
    'machineIdFor' => ['SUBMIT'],
    'name'         => 'test.orders',
]);
```

::: tip Full Testing Guide
For comprehensive endpoint testing patterns, see [Testing Recipes](/testing/recipes).
:::
