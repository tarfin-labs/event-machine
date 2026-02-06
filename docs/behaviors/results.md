# Results

Results define the output of a state machine when it reaches a final state. They compute and return values based on the final context.

## Basic Result

```php
use Tarfinlabs\EventMachine\Behavior\ResultBehavior;

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

## Defining Results

### In State Configuration

```php
'states' => [
    'processing' => [
        'on' => ['COMPLETE' => 'completed'],
    ],
    'completed' => [
        'type' => 'final',
        'result' => 'getOrderResult',
    ],
],

// In behavior
'results' => [
    'getOrderResult' => OrderResultBehavior::class,
],
```

### Direct Class Reference

```php
'completed' => [
    'type' => 'final',
    'result' => OrderResultBehavior::class,
],
```

### Inline Result

```php
'results' => [
    'getOrderResult' => fn(ContextManager $ctx) => [
        'orderId' => $ctx->orderId,
        'total' => $ctx->total,
    ],
],
```

### Return Types

Results can return any type:

```php
public function __invoke(ContextManager $context): array { ... }   // Array (most common)
public function __invoke(ContextManager $context): Order { ... }   // Eloquent Model
public function __invoke(ContextManager $context): int { ... }     // Scalar value
public function __invoke(ContextManager $context): mixed { ... }   // Any type
```

The return value of `$machine->result()` matches whatever your result behavior returns.

## Accessing Results

```php
$machine = OrderMachine::create();

// Process to final state
$machine->send(['type' => 'SUBMIT']);
$machine->send(['type' => 'COMPLETE']);

// Get result
$result = $machine->result();

// Result contains whatever the ResultBehavior returns
echo $result['orderId'];
echo $result['total'];
```

::: warning Result Availability
`result()` only returns a value when the machine is in a **final state** with a `result` behavior defined. If called before reaching a final state, or if the final state has no result defined, it returns `null`.

```php
// Safe pattern
if ($machine->state->currentStateDefinition->type === StateDefinitionType::FINAL) {
    $result = $machine->result();
}
```
:::

## When to Use Results

Results are useful when you need to:

1. **Format output** - Transform context into API responses or display formats
2. **Compute derived values** - Calculate values from final state without modifying context
3. **Different outputs per final state** - Return different data structures for success vs. failure states
4. **Hide implementation details** - Expose only relevant data, not the entire context

If you just need the context data as-is, access `$state->context` directly instead.

## Different Results for Different Final States

```php
'states' => [
    'processing' => [
        'on' => [
            'COMPLETE' => 'success',
            'CANCEL' => 'cancelled',
            'FAIL' => 'failed',
        ],
    ],
    'success' => [
        'type' => 'final',
        'result' => 'getSuccessResult',
    ],
    'cancelled' => [
        'type' => 'final',
        'result' => 'getCancelledResult',
    ],
    'failed' => [
        'type' => 'final',
        'result' => 'getFailedResult',
    ],
],

'results' => [
    'getSuccessResult' => fn($ctx) => [
        'status' => 'success',
        'orderId' => $ctx->orderId,
        'message' => 'Order completed successfully',
    ],
    'getCancelledResult' => fn($ctx) => [
        'status' => 'cancelled',
        'reason' => $ctx->cancellationReason,
    ],
    'getFailedResult' => fn($ctx) => [
        'status' => 'failed',
        'error' => $ctx->errorMessage,
        'retryable' => true,
    ],
],
```

## Result Parameters

Results receive injected parameters:

```php
class ComplexResultBehavior extends ResultBehavior
{
    public function __invoke(
        ContextManager $context,
        State $state,
        EventCollection $history,
    ): array {
        return [
            'orderId' => $context->orderId,
            'finalState' => $state->currentStateDefinition->id,
            'eventCount' => $history->count(),
            'duration' => $this->calculateDuration($history),
        ];
    }

    private function calculateDuration(EventCollection $history): int
    {
        $first = $history->first()->created_at;
        $last = $history->last()->created_at;
        return $first->diffInSeconds($last);
    }
}
```

## Dependency Injection

```php
class OrderResultBehavior extends ResultBehavior
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly ReceiptGenerator $receiptGenerator,
    ) {}

    public function __invoke(ContextManager $context): array
    {
        $order = $this->orderService->find($context->orderId);
        $receipt = $this->receiptGenerator->generate($order);

        return [
            'order' => $order->toArray(),
            'receiptUrl' => $receipt->url,
            'downloadUrl' => $receipt->downloadUrl,
        ];
    }
}
```

## Practical Examples

### Order Completion Result

```php
class OrderCompletedResult extends ResultBehavior
{
    public function __invoke(ContextManager $context): array
    {
        return [
            'orderId' => $context->orderId,
            'orderNumber' => $context->orderNumber,
            'items' => $context->items,
            'subtotal' => $context->subtotal,
            'tax' => $context->tax,
            'shipping' => $context->shipping,
            'total' => $context->total,
            'status' => 'completed',
            'completedAt' => now()->toIso8601String(),
            'estimatedDelivery' => $context->estimatedDelivery,
        ];
    }
}
```

### Loan Application Result

```php
class LoanApprovalResult extends ResultBehavior
{
    public function __invoke(ContextManager $context): array
    {
        return [
            'applicationId' => $context->applicationId,
            'status' => 'approved',
            'loanAmount' => $context->approvedAmount,
            'interestRate' => $context->interestRate,
            'termMonths' => $context->termMonths,
            'monthlyPayment' => $this->calculateMonthlyPayment($context),
            'approvedBy' => $context->approver,
            'approvedAt' => now()->toIso8601String(),
            'conditions' => $context->conditions ?? [],
        ];
    }

    private function calculateMonthlyPayment(ContextManager $context): float
    {
        $principal = $context->approvedAmount;
        $rate = $context->interestRate / 12 / 100;
        $months = $context->termMonths;

        return $principal * ($rate * pow(1 + $rate, $months))
            / (pow(1 + $rate, $months) - 1);
    }
}

class LoanRejectionResult extends ResultBehavior
{
    public function __invoke(ContextManager $context): array
    {
        return [
            'applicationId' => $context->applicationId,
            'status' => 'rejected',
            'reasons' => $context->rejectionReasons,
            'canReapply' => $context->canReapply,
            'reapplyAfter' => $context->reapplyAfter,
        ];
    }
}
```

### Workflow Result

```php
class WorkflowCompletedResult extends ResultBehavior
{
    public function __invoke(
        ContextManager $context,
        EventCollection $history,
    ): array {
        $approvals = $history
            ->filter(fn($e) => $e->type === 'APPROVE')
            ->map(fn($e) => [
                'approver' => $e->payload['approver'],
                'timestamp' => $e->created_at->toIso8601String(),
                'comment' => $e->payload['comment'] ?? null,
            ]);

        return [
            'requestId' => $context->requestId,
            'status' => 'approved',
            'approvals' => $approvals->toArray(),
            'totalApprovers' => $approvals->count(),
            'processingTime' => $this->getProcessingTime($history),
        ];
    }

    private function getProcessingTime(EventCollection $history): string
    {
        $start = $history->first()->created_at;
        $end = $history->last()->created_at;
        return $start->diffForHumans($end, true);
    }
}
```

### Quiz/Game Result

```php
class QuizResultBehavior extends ResultBehavior
{
    public function __invoke(ContextManager $context): array
    {
        $total = count($context->questions);
        $correct = $context->correctAnswers;
        $percentage = ($correct / $total) * 100;

        return [
            'score' => $correct,
            'total' => $total,
            'percentage' => round($percentage, 2),
            'grade' => $this->getGrade($percentage),
            'passed' => $percentage >= 70,
            'timeTaken' => $context->timeTaken,
            'answers' => $context->answers,
        ];
    }

    private function getGrade(float $percentage): string
    {
        return match (true) {
            $percentage >= 90 => 'A',
            $percentage >= 80 => 'B',
            $percentage >= 70 => 'C',
            $percentage >= 60 => 'D',
            default => 'F',
        };
    }
}
```

## Result Arguments

Pass arguments to results:

```php
'completed' => [
    'type' => 'final',
    'result' => 'formatResult:detailed',
],

'results' => [
    'formatResult' => function (
        ContextManager $context,
        array $arguments,
    ) {
        $format = $arguments[0] ?? 'simple';

        if ($format === 'detailed') {
            return [...detailed result...];
        }

        return [...simple result...];
    },
],
```

## Testing Results

```php
it('returns correct result when completed', function () {
    $machine = OrderMachine::create();

    $machine->send(['type' => 'ADD_ITEM', 'payload' => ['item' => [...]]]);
    $machine->send(['type' => 'CHECKOUT']);
    $machine->send(['type' => 'COMPLETE']);

    $result = $machine->result();

    expect($result)->toHaveKeys(['orderId', 'total', 'status'])
        ->and($result['status'])->toBe('completed')
        ->and($result['total'])->toBeGreaterThan(0);
});

it('returns different result when cancelled', function () {
    $machine = OrderMachine::create();

    $machine->send(['type' => 'ADD_ITEM', 'payload' => ['item' => [...]]]);
    $machine->send(['type' => 'CANCEL', 'payload' => ['reason' => 'Changed mind']]);

    $result = $machine->result();

    expect($result['status'])->toBe('cancelled')
        ->and($result['reason'])->toBe('Changed mind');
});
```

## Best Practices

### 1. Include All Relevant Data

```php
return [
    'orderId' => $context->orderId,
    'status' => 'completed',
    'total' => $context->total,
    'items' => $context->items,
    'createdAt' => $context->createdAt,
    'completedAt' => now()->toIso8601String(),
];
```

### 2. Format for API Response

```php
return [
    'data' => [
        'id' => $context->orderId,
        'attributes' => [...],
    ],
    'meta' => [
        'processingTime' => $duration,
    ],
];
```

### 3. Handle Missing Data

```php
return [
    'orderId' => $context->orderId ?? 'unknown',
    'total' => $context->total ?? 0,
    'notes' => $context->notes ?? [],
];
```

### 4. Use Different Results for Different Outcomes

```php
'success' => ['type' => 'final', 'result' => SuccessResult::class],
'failed' => ['type' => 'final', 'result' => FailureResult::class],
'cancelled' => ['type' => 'final', 'result' => CancelledResult::class],
```
