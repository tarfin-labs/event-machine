# Inter-Machine Testing

Test child machine delegation and cross-machine communication without running actual child machines.

## Machine Faking

Use `Machine::fake()` to short-circuit child machine execution in tests:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;

// Fake a successful child result
PaymentMachine::fake(result: ['paymentId' => 'pay_123']);

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

PaymentMachine::fake(result: ['paymentId' => 'pay_123']);

// ... run the parent machine ...

// Was the child invoked?
PaymentMachine::assertInvoked();

// How many times?
PaymentMachine::assertInvokedTimes(1);

// With specific context values?
PaymentMachine::assertInvokedWith(['orderId' => 'ORD-1']);

// Was it NOT invoked?
PaymentMachine::assertNotInvoked();
```

`assertInvokedWith()` checks that **at least one** invocation contains the expected key-value pairs (subset matching).

## TestMachine Fluent API for Delegation

The `TestMachine` fluent API integrates child delegation testing directly into the chain — no separate static calls needed:

<!-- doctest-attr: ignore -->
```php
OrderMachine::test()
    ->fakingChild(PaymentMachine::class, result: ['id' => 'pay_1'], finalState: 'approved')
    ->send('PLACE_ORDER')
    ->assertState('completed')
    ->assertChildInvoked(PaymentMachine::class)
    ->assertChildInvokedWith(PaymentMachine::class, ['orderId' => 'ORD-1'])
    ->assertRoutedViaDoneState('approved');
```

This is the **recommended approach** for delegation testing. It handles cleanup automatically via `resetFakes()`.

## Choosing the Right Test Level

Child delegation can be tested at three levels. Pick the one that matches what you're verifying:

| Level | Tool | What It Tests | DB Needed |
|-------|------|---------------|-----------|
| Unit | `forTesting()` + `runWithState()` | Single guard/action logic in isolation | No |
| Focused | `startingAt()` + `fakingAllActions(except:)` | Specific state behavior without path replay | No |
| Integration | `simulateChildDone/Fail` | Machine & job delegation routing, guard chains, state flow | No |
| E2E | `Machine::fake()` + `create()` | Full delegation pipeline with persistence | Yes |

::: info simulateChild* is DB-free
`simulateChildDone()`, `simulateChildFail()`, and `simulateChildTimeout()` do **not** touch the database. They route events entirely through definition-level logic (`routeChildDoneEvent`/`routeChildFailEvent`), so they work with `TestMachine::define()`, `Machine::test()`, and `Machine::startingAt()` equally well.
:::

For async simulation (parent already waiting for child):

<!-- doctest-attr: ignore -->
```php
OrderMachine::test()
    ->send('START_ASYNC_PAYMENT')
    ->assertState('awaiting_payment')
    ->simulateChildDone(PaymentMachine::class, result: ['id' => 'pay_1'], finalState: 'approved')
    ->assertState('completed');
```

For failure simulation with structured error data:

<!-- doctest-attr: ignore -->
```php
VerificationMachine::test()
    ->send('PROCESS_PAYMENT')
    ->assertState('processing_payment')
    ->simulateChildFail(
        ProcessPaymentJob::class,
        errorMessage: 'Payment declined',
        errorCode: 311,
        output: ['errorCode' => 'E311', 'retryable' => true],
    )
    ->assertState('awaiting_pin'); // guard routed to retry branch
```

See [TestMachine — Child Delegation Assertions](/testing/test-machine#child-delegation-assertions) and [TestMachine — Async Simulation](/testing/test-machine#async-simulation) for the full API reference.

## Faking Standalone Machines

When testing controllers or services that use `Machine::create()`, you can fake the machine to isolate your test from the machine pipeline:

<!-- doctest-attr: ignore -->
```php
// Without fake: controller triggers full machine restore + transition + persist
// With fake: Machine::create() returns a stub — send/persist are no-ops

OrderMachine::fake();

$response = $this->postJson("/consent/{$hash}/approve");

$response->assertOk();
OrderMachine::assertCreated();
OrderMachine::assertSent('ORDER_SUBMITTED');
// No resetMachineFakes() needed — InteractsWithMachines handles it
```

::: info Instance-Level No-Ops
Only instances created via the fake intercept are no-ops. Real instances of the same class (e.g., child delegation via `withDefinition()`) work normally.
:::

::: info Separate Tracking
`assertCreated()` and `assertSent()` use separate tracking from `assertInvoked()`. Child delegation and standalone usage don't interfere with each other.
:::

## Testing Per-Final-State Routing

When a child machine has multiple final states, use `Machine::fake(finalState: ...)` to test which `@done.{state}` route fires:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;

// Child finished in 'approved' → parent follows @done.approved route
PaymentMachine::fake(finalState: 'approved');
$machine = OrderWorkflowMachine::create();
$machine->send(['type' => 'START']);
expect($machine->state->currentStateDefinition->id)->toContain('completed');

// Child finished in 'declined' → parent follows @done.declined route
Machine::resetMachineFakes();
PaymentMachine::fake(finalState: 'declined');
$machine = OrderWorkflowMachine::create();
$machine->send(['type' => 'START']);
expect($machine->state->currentStateDefinition->id)->toContain('payment_failed');

// No finalState → falls through to @done catch-all
Machine::resetMachineFakes();
PaymentMachine::fake();
$machine = OrderWorkflowMachine::create();
$machine->send(['type' => 'START']);
expect($machine->state->currentStateDefinition->id)->toContain('fallback');
```

::: tip finalState is routing-relevant
The `finalState` parameter on `Machine::fake()` determines which `@done.{state}` route fires on the parent. When omitted (or `null`), the event has no final state info and falls through to the `@done` catch-all.
:::

## Full Test Example

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;

it('processes order through payment', function (): void {
    // Arrange: fake the child machine
    PaymentMachine::fake(result: ['paymentId' => 'pay_456']);

    // Act: run the orchestrator
    $machine = OrderWorkflowMachine::create();
    $machine->send(['type' => 'START_ORDER', 'payload' => ['orderId' => 'ORD-1']]);

    // Assert: child was invoked with correct context
    PaymentMachine::assertInvoked();
    PaymentMachine::assertInvokedWith(['orderId' => 'ORD-1']);

    // Assert: parent received child result and transitioned
    expect($machine->state->context->get('paymentId'))->toBe('pay_456');
    // No cleanup needed — InteractsWithMachines handles it
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

## Testing Job Actors

Job actors dispatch `ChildJobJob` internally. Use `Queue::fake()` to verify:

<!-- doctest-attr: no_run -->
```php
use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Jobs\ChildJobJob;

it('dispatches job actor', function (): void {
    Queue::fake();

    // ... transition to a state with job key ...

    Queue::assertPushed(ChildJobJob::class, function (ChildJobJob $job): bool {
        return $job->jobClass === SendEmailJob::class
            && $job->jobData === ['email' => 'test@example.com'];
    });
});
```

For fire-and-forget jobs, verify the parent transitions immediately:

<!-- doctest-attr: no_run -->
```php
it('fire-and-forget job transitions parent immediately', function (): void {
    Queue::fake();

    // ... transition to a state with job + target ...

    // Parent should be at target state, not waiting
    expect($state->value)->toBe(['parent.next_state']);

    // Job was dispatched
    Queue::assertPushed(ChildJobJob::class);
});
```

### Simulating Managed Job Completion

Managed jobs (with `@done`/`@fail`) support the same `simulateChild*` methods as machine children — the routing infrastructure is identical. Use `Queue::fake()` to capture the dispatch, then simulate completion:

<!-- doctest-attr: no_run -->
```php
Queue::fake();

VerificationMachine::test()
    ->withoutPersistence()
    ->faking([StoreItemsAction::class, ValidateOrderAction::class])
    ->assertState('processing_items')
    ->simulateChildDone(ProcessItemsJob::class, result: [
        'phones' => [['itemId' => 'ITEM-1', 'quantity' => 2]],
    ])
    ->assertState('awaiting_confirmation');
```

This tests the `@done` routing logic without running the actual job. For dispatch verification, use `Queue::assertPushed(ChildJobJob::class)`.

## Testing Fire-and-Forget Machine Delegation

For fire-and-forget machine delegation (`machine` + `queue`, no `@done`), use `Machine::fake()` to verify the child was invoked and the parent stayed in state (or transitioned):

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;

it('fire-and-forget machine delegation stays in state', function (): void {
    AuditMachine::fake(result: []);

    $machine = AccountMachine::create();
    $machine->send(['type' => 'SUSPEND']);

    // Parent stays in the delegating state
    expect($machine->state->currentStateDefinition->id)->toContain('suspended');

    // Child was invoked
    AuditMachine::assertInvoked();
    AuditMachine::assertInvokedWith(['userId' => 'usr_123']);
    // No cleanup needed — InteractsWithMachines handles it
});
```

Without faking, use `Queue::fake()` to verify the `ChildMachineJob` dispatch:

<!-- doctest-attr: no_run -->
```php
use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Jobs\ChildMachineJob;

it('dispatches fire-and-forget ChildMachineJob', function (): void {
    Queue::fake();

    $machine = AccountMachine::create();
    $machine->send(['type' => 'SUSPEND']);

    Queue::assertPushed(ChildMachineJob::class, function (ChildMachineJob $job): bool {
        return $job->childMachineClass === AuditMachine::class
            && $job->fireAndForget === true;
    });
});
```

## Testing Forward Endpoints

Forward endpoints let a parent machine proxy HTTP events to a running async child. In feature tests, you POST to the parent's forwarded URI and assert the child's state changed.

### Basic Forward Test

<!-- doctest-attr: no_run -->
```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Routing\MachineRouter;

uses(RefreshDatabase::class);

it('forwards PROVIDE_CARD to child via parent endpoint', function (): void {
    // 1. Register routes for the parent machine (includes forwarded endpoints)
    MachineRouter::register(OrderMachine::class, 'orders', 'order_mre');

    // 2. Create parent and transition to the delegating state
    $order   = Order::create(['status' => 'pending']);
    $machine = $order->order_mre;
    $machine->send(['type' => 'START']);

    // 3. POST to the parent's forwarded endpoint
    $response = $this->postJson("/orders/{$order->id}/provide-card", [
        'card_number' => '4111111111111111',
    ]);

    // 4. Assert the response includes child state
    $response->assertOk()
        ->assertJsonPath('data.child.value.0', 'payment_child.card_provided');

    // 5. Assert parent is still in delegating state
    $response->assertJsonPath('data.value.0', 'order.processing');
});
```

### Advanced Forward Patterns

For detailed forward endpoint testing patterns including response formats, error cases, endpoint customization, ForwardContext, available_events introspection, and FQCN forward keys, see the [Endpoints](/laravel-integration/endpoints) reference.

::: tip Related
See [Cross-Machine Messaging](/advanced/sendto) for the API reference,
[Job Actors](/advanced/job-actors) for configuration,
[Machine Delegation](/advanced/machine-delegation) for delegation configuration,
and [Recipes — Child Machine Faking](/testing/recipes#recipe-child-machine-faking) for more examples.
:::
