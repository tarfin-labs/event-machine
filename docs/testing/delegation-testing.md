# Inter-Machine Testing

Test child machine delegation and cross-machine communication without running actual child machines.

## Machine Faking

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

**`Machine::fake()` options:**

| Option | Type | Description |
|--------|------|-------------|
| `result` | `array` | The result the child "returns" via `@done` |
| `fail` | `bool` | When `true`, triggers `@fail` instead of `@done` |
| `error` | `string` | Error message passed to `ChildMachineFailEvent` |
| `finalState` | `string` | Override the final state name reported to the parent |

## Assertion Methods

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

## Full Test Example

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

## Testing dispatchTo / dispatchToParent

For async messaging via `dispatchTo()` and `dispatchToParent()`, fake the queue:

<!-- doctest-attr: no_run -->
```php
use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;

it('dispatches progress to parent', function (): void {
    Queue::fake();

    // ... invoke action that calls dispatchToParent() ...

    Queue::assertPushed(SendToMachineJob::class, function (SendToMachineJob $job): bool {
        return $job->event['type'] === 'CHILD_PROGRESS'
            && $job->event['payload']['percent'] === 50;
    });
});
```

## Testing sendTo / sendToParent

For sync messaging, verify the target machine's state directly after the call:

<!-- doctest-attr: no_run -->
```php
it('sends event synchronously to target', function (): void {
    $target = TargetMachine::create();
    $target->persist();

    // ... invoke action that calls sendTo() ...

    $restored = TargetMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('target.completed');
});
```

::: tip Related
See [Cross-Machine Messaging](/advanced/sendto) for the API reference,
[Machine Delegation](/advanced/machine-delegation) for delegation configuration,
and [Recipes — Child Machine Faking](/testing/recipes#recipe-child-machine-faking) for more examples.
:::
