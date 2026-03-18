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

Machine::resetMachineFakes();
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
    AuditMachine::assertInvokedWith(['user_id' => 'usr_123']);

    Machine::resetMachineFakes();
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

### Testing Forward Response Formats

The default response includes both parent and child state. You can customize it with `contextKeys`, `result`, and `available_events`:

<!-- doctest-attr: no_run -->
```php
it('returns only specified child context keys', function (): void {
    // forward config: 'PROVIDE_CARD' => ['contextKeys' => ['card_token']]
    $response = $this->postJson("/orders/{$order->id}/provide-card", [
        'card_number' => '4111111111111111',
    ]);

    // Only card_token is in child context, not the full context
    $response->assertOk()
        ->assertJsonPath('data.child.context.card_token', 'tok_abc')
        ->assertJsonMissing(['internal_reference']);
});

it('returns custom result from ResultBehavior', function (): void {
    // forward config: 'PROVIDE_CARD' => ['result' => 'cardResultBehavior']
    $response = $this->postJson("/orders/{$order->id}/provide-card", [
        'card_number' => '4111111111111111',
    ]);

    // Custom result replaces the default parent+child structure
    $response->assertOk()
        ->assertJsonPath('data.confirmation_code', 'CONF-12345');
});
```

### Testing Error Cases

When the parent is not in a delegating state, the forwarded event cannot be processed:

<!-- doctest-attr: no_run -->
```php
it('rejects forward when parent is not in delegating state', function (): void {
    MachineRouter::register(OrderMachine::class, 'orders', 'order_mre');

    $order = Order::create(['status' => 'pending']);
    // Parent is in 'idle' — not delegating, no child is running

    $response = $this->postJson("/orders/{$order->id}/provide-card", [
        'card_number' => '4111111111111111',
    ]);

    // The machine rejects the event because it has no valid transition in 'idle'
    $response->assertStatus(422);
});
```

### Testing Endpoint Customization

Forward entries support the same customization as regular endpoints — custom URI, method, middleware, and action:

<!-- doctest-attr: ignore -->
```php
'forward' => [
    'PROVIDE_CARD' => [
        'uri'        => '/card',
        'method'     => 'PUT',
        'middleware'  => ['auth:sanctum', 'verified'],
        'action'     => LogForwardAction::class,
        'result'     => 'cardResultBehavior',
        'contextKeys' => ['card_token', 'last_four'],
        'status'     => 201,
        'available_events' => false,
    ],
],
```

<!-- doctest-attr: no_run -->
```php
it('uses custom URI and method for forwarded endpoint', function (): void {
    MachineRouter::register(OrderMachine::class, 'orders', 'order_mre');

    $order   = Order::create(['status' => 'pending']);
    $machine = $order->order_mre;
    $machine->send(['type' => 'START']);

    // Custom URI '/card' and PUT method
    $response = $this->putJson("/orders/{$order->id}/card", [
        'card_number' => '4111111111111111',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.child.context.card_token', 'tok_abc');
});
```

### Testing ForwardContext in ResultBehavior

When a forward endpoint has a `result` key, the parent's `ResultBehavior` receives a `ForwardContext` object via dependency injection. This gives the result behavior access to the child's context and state:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Routing\ForwardContext;
use Tarfinlabs\EventMachine\Behavior\ResultBehavior;

class CardForwardResult extends ResultBehavior
{
    public function __invoke(
        ContextManager $context,
        State $state,
        ForwardContext $forwardContext,
    ): array {
        return [
            'parent_state'  => $state->value,
            'child_state'   => $forwardContext->childState->value,
            'card_token'    => $forwardContext->childContext->get('card_token'),
            'order_id'      => $context->get('order_id'),
        ];
    }
}
```

<!-- doctest-attr: no_run -->
```php
it('injects ForwardContext into result behavior', function (): void {
    MachineRouter::register(OrderMachine::class, 'orders', 'order_mre');

    $order   = Order::create(['status' => 'pending']);
    $machine = $order->order_mre;
    $machine->send(['type' => 'START']);

    // forward config: 'PROVIDE_CARD' => ['result' => CardForwardResult::class]
    $response = $this->postJson("/orders/{$order->id}/provide-card", [
        'card_number' => '4111111111111111',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.parent_state.0', 'order.processing')
        ->assertJsonPath('data.child_state.0', 'payment_child.card_provided')
        ->assertJsonPath('data.card_token', 'tok_abc');
});
```

### Testing available_events in Forward Responses

Forward endpoint responses include `available_events` by default, showing which events the parent can currently accept (including other forwarded events). Use `TestMachine` assertions to verify:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Testing\TestMachine;

it('includes forward events in available_events', function (): void {
    $testMachine = TestMachine::create(OrderMachine::class)
        ->send(['type' => 'START']);

    // Assert a forwarded event appears with source: forward
    $testMachine->assertForwardAvailable('PROVIDE_CARD');

    // Assert a regular parent event is also available
    $testMachine->assertAvailableEvent('CANCEL');

    // Assert exact set of available events
    $testMachine->assertAvailableEvents(['PROVIDE_CARD', 'CANCEL']);
});

it('reports no available events in final state', function (): void {
    $testMachine = TestMachine::create(OrderMachine::class)
        ->send(['type' => 'START'])
        ->send(['type' => 'COMPLETE']);

    $testMachine->assertNoAvailableEvents();
});
```

To opt out of `available_events` in a specific forward endpoint response, set `available_events: false` in the forward config:

<!-- doctest-attr: ignore -->
```php
'forward' => [
    'PROVIDE_CARD' => [
        'available_events' => false,
    ],
],
```

### Testing FQCN Forward Keys

Forward entries can use EventBehavior class FQCNs instead of string event types. The FQCN is resolved to its `SCREAMING_SNAKE_CASE` type during definition parsing:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class ProvideCardEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'PROVIDE_CARD';
    }
}
```

<!-- doctest-attr: no_run -->
```php
it('resolves FQCN forward key to event type', function (): void {
    // forward config uses FQCN: [ProvideCardEvent::class]
    // or: [ProvideCardEvent::class => ['uri' => '/card']]
    // or: ['PARENT_CARD' => ['child_event' => ProvideCardEvent::class]]

    $definition = OrderMachine::definition();
    $endpoints  = $definition->forwardedEndpoints;

    // FQCN is resolved to SCREAMING_SNAKE_CASE
    expect($endpoints)->toHaveKey('PROVIDE_CARD')
        ->and($endpoints['PROVIDE_CARD']->childEventClass)
        ->toBe(ProvideCardEvent::class);
});
```

::: tip Unit vs E2E
Use `Machine::fake()` to skip child dispatch in unit tests. For E2E forward testing, use LocalQA with real Horizon — see [LocalQA setup](/testing/recipes#localqa-setup) for instructions.
:::

::: tip Related
See [Cross-Machine Messaging](/advanced/sendto) for the API reference,
[Job Actors](/advanced/job-actors) for configuration,
[Machine Delegation](/advanced/machine-delegation) for delegation configuration,
and [Recipes — Child Machine Faking](/testing/recipes#recipe-child-machine-faking) for more examples.
:::
