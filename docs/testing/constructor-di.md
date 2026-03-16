# Testing with Constructor DI

Behaviors support constructor dependency injection. Services are resolved by the Laravel container, making them fully mockable in tests.

::: info Class-based behaviors only
Constructor DI applies only to class-based behaviors resolved through `App::make()`. Inline closures bypass the container and do not receive constructor injection. For testing inline behaviors, see [Inline Behavior Faking](/testing/fakeable-behaviors#inline-behavior-faking).
:::

## Two-Layer DI Architecture

EventMachine uses two separate injection layers. The constructor receives long-lived services (database repositories, API clients) resolved once by Laravel's container. The `__invoke` method receives per-transition state (context, event, history) injected by the engine. This separation makes behaviors easy to test: mock the services, provide test state.

| Layer | What | Where | Resolved By |
|-------|------|-------|-------------|
| Services | PaymentGateway, Logger, Repository | `__construct()` | Laravel container via `App::make()` |
| State | ContextManager, EventBehavior, State | `__invoke()` | `injectInvokableBehaviorParameters` |

## Mocking Injected Services

### With runWithState() — Isolated

Use `App::instance()` or Mockery to replace the service in Laravel's container before calling `runWithState()`. The container resolves the mock just like production code would.

<!-- doctest-attr: ignore -->
```php
it('calls payment gateway with correct amount', function () {
    $this->mock(PaymentGateway::class)
        ->shouldReceive('charge')->with(100)->once()
        ->andReturn(new PaymentResult(id: 'txn_123'));

    $state = State::forTesting(['amount' => 100]);
    ProcessPaymentAction::runWithState($state);

    expect($state->context->get('transaction_id'))->toBe('txn_123');
});
```

### With Machine::test() — Integration

In machine-level tests, mock the service the same way — the container binding is global. The difference is that the full machine lifecycle runs, so you're testing the behavior within its real transition context.

<!-- doctest-attr: ignore -->
```php
it('processes payment in the full machine', function () {
    $this->mock(PaymentGateway::class)
        ->shouldReceive('charge')->andReturn(new PaymentResult(id: 'txn_456'));

    OrderMachine::test(['amount' => 100])
        ->send('PROCESS_PAYMENT')
        ->assertState('paid')
        ->assertContext('transaction_id', 'txn_456');
});
```

## Before/After Comparison

Previously, behaviors that needed external services used the service locator pattern (calling `app()` inside `__invoke`). Constructor DI is cleaner: dependencies are explicit, testable, and visible in the class signature.

### Before — Service Locator (anti-pattern)

<!-- doctest-attr: ignore -->
```php
class ProcessPaymentAction extends ActionBehavior {
    public function __invoke(ContextManager $context): void {
        $gateway = app(PaymentGateway::class);  // hidden dependency
        $result = $gateway->charge($context->get('amount'));
    }
}
```

### After — Constructor DI

<!-- doctest-attr: ignore -->
```php
class ProcessPaymentAction extends ActionBehavior {
    public function __construct(
        private readonly PaymentGateway $gateway,
        ?Collection $eventQueue = null,
    ) {
        parent::__construct($eventQueue);
    }

    public function __invoke(ContextManager $context): void {
        $result = $this->gateway->charge($context->get('amount'));  // explicit
    }
}
```

## Decision Guide: Mock Service vs Mock Behavior

| Approach | When | Example |
|----------|------|---------|
| Mock the **service** | Test behavior logic with controlled service responses | `$this->mock(PaymentGateway::class)` |
| Mock the **behavior** | Test machine flow, skip behavior internals | `ProcessPaymentAction::fake()` |
| Mock **neither** | E2E with real services (or test doubles in ServiceProvider) | Full integration test |

::: tip Related
See [Isolated Testing](/testing/isolated-testing) for `runWithState()` details,
[Fakeable Behaviors](/testing/fakeable-behaviors) for the faking API,
[TestMachine](/testing/test-machine) for the fluent machine-level wrapper,
and [Migration Patterns](/getting-started/upgrading#testing-migration-patterns) for upgrading from legacy test patterns.

For DI patterns beyond testing, see [Dependency Injection](/advanced/dependency-injection).
:::
