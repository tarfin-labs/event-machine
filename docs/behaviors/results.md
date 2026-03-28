# Results

## Why Results Exist

A state machine's **context** is its internal working memory — it accumulates data as transitions happen, actions fire, and calculators run. But context is a flat bag of everything the machine has ever needed: intermediate values, retry counters, error messages, IDs from external systems, flags for internal logic.

**Results solve a different problem: what does this machine _produce_ as its output?**

Think of it like a function: the function has local variables (context), but its `return` value (result) is what the caller sees. Results transform internal state into a clean, purposeful output.

```
Context (internal)              Result (output)
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

Without results, callers would need to know the machine's internal context structure — which keys exist, which are intermediate, which matter. Results provide a **contract** between the machine and its consumers.

## Two Places Results Are Used

### 1. `Machine::result()` — Programmatic Output

When code creates and runs a machine, `result()` returns the machine's output after it reaches a final state:

```php no_run
$machine = LoanApplicationMachine::create();
$machine->send(['type' => 'SUBMIT', 'payload' => ['amount' => 50000]]);
$machine->send(['type' => 'APPROVE']);

$result = $machine->result();
// → ['applicationId' => 'LA-123', 'status' => 'approved', 'monthlyPayment' => 1450.00]
```

The result is defined on the **final state**:

```php ignore
'approved' => [
    'type'   => 'final',
    'result' => ApprovalResult::class,
],
```

`result()` returns `null` if the machine is not in a final state or if no result behavior is defined.

### 2. Endpoint Results — HTTP Response Shaping

Endpoints can define a result behavior that transforms context into an API response. This runs on **any state**, not just final states — the endpoint decides what to return for each request:

```php ignore
endpoints: [
    'GET_STATUS' => [
        'uri'    => '/orders/{order}/status',
        'method' => 'GET',
        'result' => OrderStatusResult::class,
    ],
],
```

When no `result` is specified, the endpoint returns the default state serialization (`toResponseArray()` + machine metadata). When `result` IS specified, only the result behavior's return value is sent — wrapped in `{ "data": ... }`.

This is the most common use of results in practice: **controlling what an API endpoint returns.**

## Result vs `contextKeys` vs `toResponseArray()`

Three ways to control what data leaves the machine:

| Mechanism | Where | What It Does | When to Use |
|-----------|-------|-------------|-------------|
| `toResponseArray()` | ContextManager override | Returns all context properties | Default — when context shape IS the response |
| `contextKeys` | Endpoint config | Filters `toResponseArray()` to specific keys | Simple filtering — "only show these fields" |
| `result` | Final state or endpoint | Runs a behavior that computes output | Computed values, formatting, external lookups, hiding internals |

```php ignore
// contextKeys — simple filter, no logic
'GET_PRICE' => [
    'uri'         => '/orders/{order}/price',
    'method'      => 'GET',
    'contextKeys' => ['totalAmount', 'currency', 'installmentOptions'],
],

// result — computed output, full control
'GET_SUMMARY' => [
    'uri'    => '/orders/{order}/summary',
    'method' => 'GET',
    'result' => OrderSummaryResult::class,
],
```

**Rule of thumb:** If you're just picking fields from context, use `contextKeys`. If you need to compute, format, combine, or look up external data, use a result.

## Writing a Result

### Basic Result

```php
use Tarfinlabs\EventMachine\Behavior\ResultBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]

class OrderResultBehavior extends ResultBehavior
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

### Defining Results

Three ways to attach a result — class reference, inline key, or inline closure:

```php ignore
// 1. Direct class reference (preferred)
'completed' => [
    'type'   => 'final',
    'result' => OrderResultBehavior::class,
],

// 2. Inline key — resolved from behavior.results
'completed' => [
    'type'   => 'final',
    'result' => 'orderResult',
],
// ...
'results' => [
    'orderResult' => OrderResultBehavior::class,
],

// 3. Inline closure
'results' => [
    'orderResult' => fn(ContextManager $ctx) => [
        'orderId' => $ctx->orderId,
        'total'   => $ctx->total,
    ],
],
```

### Return Types

Results can return any type — the return value of `$machine->result()` matches whatever your result behavior returns:

```php ignore
public function __invoke(ContextManager $context): array { ... }   // Array (most common)
public function __invoke(ContextManager $context): Order { ... }   // Eloquent Model
public function __invoke(ContextManager $context): int { ... }     // Scalar value
public function __invoke(ContextManager $context): mixed { ... }   // Any type
```

## Different Results for Different Final States

Each final state can have its own result behavior — the machine produces different output depending on how it ended:

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
        'result' => ApprovalResult::class,
    ],
    'rejected' => [
        'type'   => 'final',
        'result' => RejectionResult::class,
    ],
    'cancelled' => [
        'type'   => 'final',
        'result' => CancellationResult::class,
    ],
],
```

The caller doesn't need to check which final state the machine is in — `$machine->result()` returns the right shape automatically.

## Parameter Injection

Results use the same type-hint based parameter injection as actions, guards, and calculators. Available types:

| Type | What's Injected |
|------|----------------|
| `ContextManager` (or subclass) | Machine context |
| `EventBehavior` (or subclass) | The triggering event (the original external event, not internal lifecycle events) |
| `State` | Current state object |
| `EventCollection` | Full event history |
| `ForwardContext` | Child machine context (forwarded endpoints only) |

```php
use Tarfinlabs\EventMachine\Behavior\ResultBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
use Tarfinlabs\EventMachine\Actor\State; // [!code hide]
use Tarfinlabs\EventMachine\EventCollection; // [!code hide]

class AuditableResult extends ResultBehavior
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

Results support Laravel's service container for constructor dependencies — external services, repositories, API clients:

```php no_run
class OrderResultBehavior extends ResultBehavior
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
class OrderCompletedResult extends ResultBehavior
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
class LoanApprovalResult extends ResultBehavior
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

class LoanRejectionResult extends ResultBehavior
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
use Tarfinlabs\EventMachine\Behavior\ResultBehavior; // [!code hide]
use Tarfinlabs\EventMachine\ContextManager; // [!code hide]
use Tarfinlabs\EventMachine\EventCollection; // [!code hide]

class WorkflowCompletedResult extends ResultBehavior
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

## Testing Results

### Via Machine::test()

<!-- doctest-attr: ignore -->
```php
$test = OrderMachine::test(['orderId' => 'ord-123'])
    ->sendMany(['SUBMIT', 'PAY', 'SHIP', 'DELIVER'])
    ->assertFinished();

$result = $test->machine()->result();
expect($result)->toHaveKeys(['orderId', 'total', 'status']);
expect($result['status'])->toBe('completed');
```

### Isolated — Direct Invocation

<!-- doctest-attr: ignore -->
```php
$state = State::forTesting([
    'orderId' => 'ord-123',
    'total'   => 250,
]);

$result = OrderResultBehavior::runWithState($state);
expect($result['orderId'])->toBe('ord-123');
expect($result['total'])->toBe(250);
```

### With Constructor DI

<!-- doctest-attr: ignore -->
```php
it('generates receipt via injected service', function () {
    $this->mock(ReceiptGenerator::class)
        ->shouldReceive('generate')
        ->andReturn(new Receipt(url: 'https://example.com/receipt/123'));

    $state  = State::forTesting(['orderId' => 'ord-123']);
    $result = OrderResultBehavior::runWithState($state);

    expect($result['receiptUrl'])->toBe('https://example.com/receipt/123');
});
```

::: tip Full Testing Guide
See [TestMachine](/testing/test-machine) for `assertFinished()` and result access.
:::

## Best Practices

1. **Results are for consumers, context is for the machine.** Don't return raw context — shape the output for whoever calls `result()` or receives the endpoint response.

2. **Use `contextKeys` for simple filtering, results for computation.** If you're just picking fields, `contextKeys` is simpler. If you're computing, formatting, or combining data, use a result.

3. **Different final states → different results.** Don't build one result that checks which state the machine is in. Define separate result behaviors per final state.

4. **Keep results stateless.** Results should read from context and compute — not modify context or trigger side effects. That's what actions are for.

5. **Handle missing data gracefully.** Context may not have all values if the machine took a non-happy path:

```php ignore
return [
    'orderId' => $context->orderId ?? 'unknown',
    'total'   => $context->total ?? 0,
    'notes'   => $context->notes ?? [],
];
```
