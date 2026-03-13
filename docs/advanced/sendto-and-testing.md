# sendTo, sendToParent & Testing

## Cross-Machine Communication

### `sendTo()` — Send Events to Any Machine

Send an event to another machine instance by class and root event ID:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class NotifyTargetAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // Sync: restores target machine and sends event immediately
        $this->sendTo(
            machineClass: TargetMachine::class,
            rootEventId: $context->get('target_machine_id'),
            event: ['type' => 'NOTIFICATION', 'payload' => ['message' => 'done']],
        );

        // Async: dispatches SendToMachineJob on queue
        $this->sendTo(
            machineClass: TargetMachine::class,
            rootEventId: $context->get('target_machine_id'),
            event: ['type' => 'NOTIFICATION'],
            async: true,
        );
    }
}
```

### `sendToParent()` — Child → Parent Communication

The primary use case for cross-machine messaging. A child reports progress or sends data to its parent:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class ReportProgressAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $this->sendToParent($context, [
            'type'    => 'CHILD_PROGRESS',
            'payload' => [
                'percent'   => 50,
                'processed' => 5,
                'total'     => 10,
            ],
        ]);
    }
}
```

`sendToParent()` uses `$context->parentMachineId()` and `$context->parentMachineClass()` internally. Throws `RuntimeException` if the machine was not invoked by a parent.

### Progress Reporting Pattern

Combine `sendToParent()` with the parent's `on` map for bidirectional communication:

<!-- doctest-attr: ignore -->
```php
// Parent config — receives progress updates while child runs
'batch_processing' => [
    'machine' => BatchProcessMachine::class,
    'with'    => ['items'],
    'queue'   => 'batch',
    '@done'   => 'completed',
    '@fail'   => 'failed',
    'on' => [
        'CHILD_PROGRESS' => ['actions' => 'updateProgressAction'],
        'CANCEL'         => 'cancelled',
    ],
],
```

Progress events arrive as regular events on the parent's `on` map. The parent stays in the same state — it just runs actions.

### raise() vs sendTo() vs sendToParent()

| Aspect | `raise()` | `sendTo()` | `sendToParent()` |
|--------|-----------|------------|------------------|
| Target | Same machine | Any machine | Parent machine |
| Mechanism | In-memory event queue | `send()` or job dispatch | Same as `sendTo()` |
| Timing | Same transition cycle | Immediate or queued | Immediate or queued |
| Data flow | Same context | Isolated contexts | Isolated contexts |
| Use case | Internal events | Peer messaging | Progress reporting |

## Testing Machine Delegation

### Machine Faking

Use `Machine::fake()` to short-circuit child machine execution in tests:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;

// Fake a successful child result
PaymentMachine::fake(result: ['payment_id' => 'pay_123']);

// Fake a failure
PaymentMachine::fake(fail: true, error: 'Insufficient funds');

// Fake with specific final state name
PaymentMachine::fake(result: ['status' => 'ok'], finalState: 'approved');
```

When a faked machine is invoked via the `machine` key, it short-circuits: no child machine is actually created. Instead, it records the invocation and immediately routes `@done` (or `@fail`) on the parent.

### Assertion Methods

After faking, verify invocations:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;

PaymentMachine::fake(result: ['payment_id' => 'pay_123']);

// ... run the parent machine ...

// Was the child invoked?
PaymentMachine::assertInvoked();

// How many times?
PaymentMachine::assertInvokedTimes(1);

// With specific context values?
PaymentMachine::assertInvokedWith(['order_id' => 'ORD-1']);

// Was it NOT invoked?
PaymentMachine::assertNotInvoked();

// Reset all fakes between tests
Machine::resetMachineFakes();
```

`assertInvokedWith()` checks that **at least one** invocation contains the expected key-value pairs (subset matching).

### Full Test Example

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;

it('processes order through payment', function (): void {
    // Arrange: fake the child machine
    PaymentMachine::fake(result: ['payment_id' => 'pay_456']);

    // Act: run the orchestrator
    $machine = OrderWorkflowMachine::create();
    $machine->send(['type' => 'START_ORDER', 'payload' => ['order_id' => 'ORD-1']]);

    // Assert: child was invoked with correct context
    PaymentMachine::assertInvoked();
    PaymentMachine::assertInvokedWith(['order_id' => 'ORD-1']);

    // Assert: parent received child result and transitioned
    expect($machine->state->context->get('payment_id'))->toBe('pay_456');

    // Cleanup
    Machine::resetMachineFakes();
});
```

### Testing sendTo / sendToParent

For `sendTo()` and `sendToParent()` with async mode, fake the queue:

<!-- doctest-attr: no_run -->
```php
use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;

it('dispatches progress to parent', function (): void {
    Queue::fake();

    // ... invoke action that calls sendToParent(async: true) ...

    Queue::assertPushed(SendToMachineJob::class, function (SendToMachineJob $job): bool {
        return $job->event['type'] === 'CHILD_PROGRESS'
            && $job->event['payload']['percent'] === 50;
    });
});
```

For sync mode, verify the target machine's state directly after the `sendTo()` call.
