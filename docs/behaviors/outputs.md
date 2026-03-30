# Outputs

## Why Outputs Exist

A state machine's **context** is its internal working memory — it accumulates data as transitions happen, actions fire, and calculators run. But context is a flat bag of everything the machine has ever needed: intermediate values, retry counters, error messages, IDs from external systems, flags for internal logic.

**Outputs solve a different problem: what does this machine _produce_ as its output?**

Think of it like a function: the function has local variables (context), but its `return` value (output) is what the caller sees. Outputs transform internal state into a clean, purposeful output.

```
Context (internal)              Output
├── orderId                     ├── orderId
├── retryCount          ──►     ├── total
├── lastError                   ├── status: 'completed'
├── items[]                     └── estimatedDelivery
├── subtotal
├── tax
├── shipping
├── total
├── estimatedDelivery
└── internalFlags
```

Without outputs, callers would need to know the machine's internal context structure — which keys exist, which are intermediate, which matter. Outputs provide a **contract** between the machine and its consumers.

## Two Places Outputs Are Used

### 1. `$machine->output()` — Programmatic Output

When code creates and runs a machine, `output()` returns the machine's output after it reaches a final state:

```php no_run
$machine = LoanApplicationMachine::create();
$machine->send(['type' => 'SUBMIT', 'payload' => ['amount' => 50000]]);
$machine->send(['type' => 'APPROVE']);

$output = $machine->output();
// → ['applicationId' => 'LA-123', 'status' => 'approved', 'monthlyPayment' => 1450.00]
```

The output is defined on the **final state**:

```php ignore
'approved' => [
    'type'   => 'final',
    'output' => ApprovalOutput::class,
],
```

`output()` returns `null` if the machine is not in a final state or if no output behavior is defined.

### 2. Endpoint Outputs — HTTP Response Shaping

Endpoints can define an output behavior that transforms context into an API response. This runs on **any state**, not just final states — the endpoint decides what to return for each request:

```php ignore
endpoints: [
    'GET_STATUS' => [
        'uri'    => '/orders/{order}/status',
        'method' => 'GET',
        'output' => OrderStatusOutput::class,
    ],
],
```

When no `output` is specified, the endpoint returns the default state serialization (`toResponseArray()` + machine metadata). When `output` IS specified, only the output behavior's return value is sent — wrapped in `{ "data": ... }`.

This is the most common use of outputs in practice: **controlling what an API endpoint returns.**

## Output vs `output` (array filter) vs `toResponseArray()`

Three ways to control what data leaves the machine:

| Mechanism | Where | What It Does | When to Use |
|-----------|-------|-------------|-------------|
| `toResponseArray()` | ContextManager override | Returns all context properties | Default — when context shape IS the response |
| `output` (array) | Endpoint config | Filters `toResponseArray()` to specific keys | Simple filtering — "only show these fields" |
| `output` (class) | Final state or endpoint | Runs a behavior that computes output | Computed values, formatting, external lookups, hiding internals |

```php ignore
// output array — simple filter, no logic
'GET_PRICE' => [
    'uri'    => '/orders/{order}/price',
    'method' => 'GET',
    'output' => ['totalAmount', 'currency', 'installmentOptions'],
],

// output class — computed output, full control
'GET_SUMMARY' => [
    'uri'    => '/orders/{order}/summary',
    'method' => 'GET',
    'output' => OrderSummaryOutput::class,
],
```

**Rule of thumb:** If you're just picking fields from context, use `output` with an array. If you need to compute, format, combine, or look up external data, use an output behavior class.

## Writing an Output

### Basic Output

```php
use Tarfinlabs\EventMachine\Behavior\OutputBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

class OrderOutputBehavior extends OutputBehavior
{
    public function __invoke(ContextManager $context): array
    {
        return [
            'orderId' => $context->orderId,
            'total' => $context->total,
            'status' => 'completed',
        ];
    }
}
```

### Defining Outputs

Three ways to attach an output — class reference, inline key, or inline closure:

```php ignore
// 1. Direct class reference (preferred)
'completed' => [
    'type'   => 'final',
    'output' => OrderOutputBehavior::class,
],

// 2. Inline key — resolved from behavior.outputs
'completed' => [
    'type'   => 'final',
    'output' => 'orderOutput',
],
// ...
'outputs' => [
    'orderOutput' => OrderOutputBehavior::class,
],

// 3. Inline closure
'outputs' => [
    'orderOutput' => fn(ContextManager $ctx) => [
        'orderId' => $ctx->orderId,
        'total'   => $ctx->total,
    ],
],
```

All three formats use the same resolution mechanism described in [Behavior Resolution](/behaviors/introduction#behavior-resolution). Inline keys and class references work interchangeably in every context where an output can be defined: `$machine->output()`, endpoint responses, child machine outputs, and forwarded endpoint outputs.

### Output Parameters

Outputs support named parameters via tuple syntax, the same as guards and actions:

```php ignore
// In behavior.outputs map (final states)
'outputs' => ['completed' => [[FormatOutput::class, 'format' => 'detailed']]],

// In state-level output config (any state)
'output' => [[FormatOutput::class, 'format' => 'detailed']],

// Context key filter (unchanged) — plain array of strings
'output' => ['orderId', 'totalAmount'],
```

**Inner-array rule:** A parameterized output is always an inner array (tuple), just like guards and actions. A plain array of strings is a context key filter. The framework disambiguates by checking whether the first element is a class/key string with named keys — if yes, it's a tuple; if the array contains only string values without named keys, it's a filter.

```php ignore
class FormatOutput extends OutputBehavior
{
    public function __invoke(ContextManager $context, string $format = 'summary'): array
    {
        return match ($format) {
            'detailed' => ['orderId' => $context->orderId, 'items' => $context->items, 'total' => $context->total],
            default    => ['orderId' => $context->orderId, 'total' => $context->total],
        };
    }
}
```

### Return Types

Outputs can return any type — the return value of `$machine->output()` matches whatever your output behavior returns:

```php ignore
public function __invoke(ContextManager $context): array { ... }   // Array (most common)
public function __invoke(ContextManager $context): Order { ... }   // Eloquent Model
public function __invoke(ContextManager $context): int { ... }     // Scalar value
public function __invoke(ContextManager $context): mixed { ... }   // Any type
```

## Different Outputs for Different Final States

Each final state can have its own output behavior — the machine produces different output depending on how it ended:

```php ignore
'states' => [
    'processing' => [
        'on' => [
            'APPROVE' => 'approved',
            'REJECT'  => 'rejected',
            'CANCEL'  => 'cancelled',
        ],
    ],
    'approved' => [
        'type'   => 'final',
        'output' => ApprovalOutput::class,
    ],
    'rejected' => [
        'type'   => 'final',
        'output' => RejectionOutput::class,
    ],
    'cancelled' => [
        'type'   => 'final',
        'output' => CancellationOutput::class,
    ],
],
```

The caller doesn't need to check which final state the machine is in — `$machine->output()` returns the right shape automatically.

## Parameter Injection

Outputs use the same type-hint based parameter injection as actions, guards, and calculators. Available types:

| Type | What's Injected |
|------|----------------|
| `ContextManager` (or subclass) | Machine context |
| `EventBehavior` (or subclass) | The triggering event (the original external event, not internal lifecycle events) |
| `State` | Current state object |
| `EventCollection` | Full event history |
| `ForwardContext` | Child machine context (forwarded endpoints only) |

```php
use Tarfinlabs\EventMachine\Behavior\OutputBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
use Tarfinlabs\EventMachine\Actor\State; // [!code hide]
use Tarfinlabs\EventMachine\EventCollection; // [!code hide]

class AuditableOutput extends OutputBehavior
{
    public function __invoke(
        ContextManager $context,
        State $state,
        EventCollection $history,
    ): array {
        return [
            'orderId'        => $context->orderId,
            'finalState'     => $state->currentStateDefinition->id,
            'eventCount'     => $history->count(),
            'processingTime' => $history->first()->created_at
                ->diffForHumans($history->last()->created_at, true),
        ];
    }
}
```

## Constructor Dependency Injection

Outputs support Laravel's service container for constructor dependencies — external services, repositories, API clients:

```php no_run
class OrderOutputBehavior extends OutputBehavior
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly ReceiptGenerator $receiptGenerator,
    ) {}

    public function __invoke(ContextManager $context): array
    {
        $order   = $this->orderService->find($context->orderId);
        $receipt = $this->receiptGenerator->generate($order);

        return [
            'order'       => $order->toArray(),
            'receiptUrl'  => $receipt->url,
            'downloadUrl' => $receipt->downloadUrl,
        ];
    }
}
```

## Practical Examples

### Order Completion

```php no_run
class OrderCompletedOutput extends OutputBehavior
{
    public function __invoke(ContextManager $context): array
    {
        return [
            'orderId'           => $context->orderId,
            'orderNumber'       => $context->orderNumber,
            'items'             => $context->items,
            'subtotal'          => $context->subtotal,
            'tax'               => $context->tax,
            'shipping'          => $context->shipping,
            'total'             => $context->total,
            'status'            => 'completed',
            'completedAt'       => now()->toIso8601String(),
            'estimatedDelivery' => $context->estimatedDelivery,
        ];
    }
}
```

### Loan Approval vs Rejection

```php no_run
class LoanApprovalOutput extends OutputBehavior
{
    public function __invoke(ContextManager $context): array
    {
        $principal = $context->approvedAmount;
        $rate      = $context->interestRate / 12 / 100;
        $months    = $context->termMonths;

        return [
            'applicationId'  => $context->applicationId,
            'status'         => 'approved',
            'loanAmount'     => $principal,
            'interestRate'   => $context->interestRate,
            'termMonths'     => $months,
            'monthlyPayment' => round(
                $principal * ($rate * pow(1 + $rate, $months)) / (pow(1 + $rate, $months) - 1),
                2
            ),
            'approvedAt'     => now()->toIso8601String(),
            'conditions'     => $context->conditions ?? [],
        ];
    }
}

class LoanRejectionOutput extends OutputBehavior
{
    public function __invoke(ContextManager $context): array
    {
        return [
            'applicationId' => $context->applicationId,
            'status'        => 'rejected',
            'reasons'       => $context->rejectionReasons,
            'canReapply'    => $context->canReapply,
            'reapplyAfter'  => $context->reapplyAfter,
        ];
    }
}
```

### Workflow with Audit Trail

```php
use Tarfinlabs\EventMachine\Behavior\OutputBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
use Tarfinlabs\EventMachine\EventCollection; // [!code hide]

class WorkflowCompletedOutput extends OutputBehavior
{
    public function __invoke(
        ContextManager $context,
        EventCollection $history,
    ): array {
        $approvals = $history
            ->filter(fn($e) => $e->type === 'APPROVE')
            ->map(fn($e) => [
                'approver'  => $e->payload['approver'],
                'timestamp' => $e->created_at->toIso8601String(),
                'comment'   => $e->payload['comment'] ?? null,
            ]);

        return [
            'requestId'      => $context->requestId,
            'status'         => 'approved',
            'approvals'      => $approvals->toArray(),
            'totalApprovers' => $approvals->count(),
        ];
    }
}
```

## Testing Outputs

### Via Machine::test()

<!-- doctest-attr: ignore -->
```php
$test = OrderMachine::test(['orderId' => 'ord-123'])
    ->sendMany(['SUBMIT', 'PAY', 'SHIP', 'DELIVER'])
    ->assertFinished();

$output = $test->machine()->output();
expect($output)->toHaveKeys(['orderId', 'total', 'status']);
expect($output['status'])->toBe('completed');
```

### Isolated — Direct Invocation

<!-- doctest-attr: ignore -->
```php
$state = State::forTesting([
    'orderId' => 'ord-123',
    'total'   => 250,
]);

$output = OrderOutputBehavior::runWithState($state);
expect($output['orderId'])->toBe('ord-123');
expect($output['total'])->toBe(250);
```

### With Constructor DI

<!-- doctest-attr: ignore -->
```php
it('generates receipt via injected service', function () {
    $this->mock(ReceiptGenerator::class)
        ->shouldReceive('generate')
        ->andReturn(new Receipt(url: 'https://example.com/receipt/123'));

    $state  = State::forTesting(['orderId' => 'ord-123']);
    $output = OrderOutputBehavior::runWithState($state);

    expect($output['receiptUrl'])->toBe('https://example.com/receipt/123');
});
```

::: tip Full Testing Guide
See [TestMachine](/testing/test-machine) for `assertFinished()` and output access.
:::

## Output Placement Rules

Not every state can have an `output` definition. `InvalidOutputDefinitionException` is thrown when output is defined on:

- **Transient states** — states with `@always` transitions are routing nodes, not resting states. Output would never be accessible since the machine immediately leaves.
- **Parallel region states** — individual regions within a parallel state cannot define output. Only the parent parallel state (or its `@done` target) can produce output.

Output is valid on:
- Final states (`type: 'final'`) — the primary use case for `$machine->output()`
- Any state referenced by an endpoint `output` key — for HTTP response shaping

## Best Practices

1. **Outputs are for consumers, context is for the machine.** Don't return raw context — shape the output for whoever calls `output()` or receives the endpoint response.

2. **Use `output` array for simple filtering, output class for computation.** If you're just picking fields, an array is simpler. If you're computing, formatting, or combining data, use an output behavior class.

3. **Different final states → different outputs.** Don't build one output that checks which state the machine is in. Define separate output behaviors per final state.

4. **Keep outputs stateless.** Outputs should read from context and compute — not modify context or trigger side effects. That's what actions are for.

5. **Handle missing data gracefully.** Context may not have all values if the machine took a non-happy path:

```php ignore
return [
    'orderId' => $context->orderId ?? 'unknown',
    'total'   => $context->total ?? 0,
    'notes'   => $context->notes ?? [],
];
```
