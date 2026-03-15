# Testing Recipes

Common real-world testing patterns combining isolated, faked, and machine-level techniques.

## Recipe: External API Action

Two strategies for testing actions that call external APIs. Strategy 1 mocks the service to test the action's error handling logic. Strategy 2 fakes the entire action to test the machine's flow without the API dependency. Choose Strategy 1 when the action's internal logic matters, Strategy 2 when only the machine flow matters.

<!-- doctest-attr: ignore -->
```php
// Strategy 1: Mock the service (test real action logic)
it('handles API failure gracefully', function () {
    $this->mock(PaymentGateway::class)
        ->shouldReceive('charge')
        ->andThrow(new ApiTimeoutException());

    $state = State::forTesting(['amount' => 100]);
    ProcessPaymentAction::runWithState($state);

    expect($state->context->get('api_error'))->toBe('Payment API timeout');
});

// Strategy 2: Fake the action (test machine flow)
it('continues flow after payment', function () {
    ProcessPaymentAction::shouldRun()
        ->andReturnUsing(fn($ctx) => $ctx->set('paid', true));

    OrderMachine::test()->send('PAY')->assertState('preparing');
});
```

## Recipe: Guard Chain (Multiple Guards)

When a transition has multiple guards, ALL must return true for the transition to proceed. Guards are evaluated in declaration order — the first guard that returns false blocks the transition, and remaining guards are not evaluated.

<!-- doctest-attr: ignore -->
```php
it('requires all guards to pass', function () {
    HasItemsGuard::shouldReturn(true);
    IsValidAmountGuard::shouldReturn(true);
    HasPermissionGuard::shouldReturn(false);  // third guard fails

    OrderMachine::test()->assertGuarded('SUBMIT');
});

it('first failing guard blocks', function () {
    HasItemsGuard::shouldReturn(false);  // first guard fails
    // remaining guards never evaluated

    OrderMachine::test()->assertGuarded('SUBMIT');
});
```

## Recipe: Calculator Ordering

Calculators run in declaration order before guards. When calculators depend on each other's output (e.g., subtotal → discount → tax), test them in sequence to verify the pipeline produces correct values.

<!-- doctest-attr: ignore -->
```php
it('calculators run in declared order', function () {
    // subtotal → discount → tax → total
    $state = State::forTesting([
        'items' => [['price' => 100, 'qty' => 2]],
        'discount_rate' => 0.10,
        'tax_rate' => 0.18,
    ]);

    CalculateSubtotalCalculator::runWithState($state);
    expect($state->context->get('subtotal'))->toBe(200);

    ApplyDiscountCalculator::runWithState($state);
    expect($state->context->get('discounted'))->toBe(180);

    CalculateTaxCalculator::runWithState($state);
    expect($state->context->get('total'))->toBe(212.40);
});
```

## Recipe: Raised Event Flow

Actions can call `$this->raise(['type' => 'EVENT_NAME'])` to push events onto the internal queue. After the current transition completes, raised events are processed as if they were sent externally — triggering further transitions.

<!-- doctest-attr: ignore -->
```php
it('action raises event that triggers further transition', function () {
    OrderMachine::test(['items' => [['id' => 1]]])
        ->send('VALIDATE')
        ->assertState('validated')
        ->assertHistoryContains('VALIDATION_PASSED');
});

it('raised event failure path', function () {
    OrderMachine::test(['items' => []])
        ->send('VALIDATE')
        ->assertState('validation_failed')
        ->assertHistoryContains('VALIDATION_FAILED');
});
```

## Recipe: Multi-Step Lifecycle with Selective Faking

<!-- doctest-attr: ignore -->
```php
it('completes full order lifecycle', function () {
    // Fake notifications but run everything else real
    OrderMachine::test(['order_id' => 1])
        ->faking([SendEmailAction::class, SendSmsAction::class])
        ->assertPath([
            ['event' => 'SUBMIT',  'state' => 'awaiting_payment'],
            ['event' => 'PAY',     'state' => 'preparing',       'context' => ['paid' => true]],
            ['event' => 'SHIP',    'state' => 'shipped'],
            ['event' => 'DELIVER', 'state' => 'delivered'],
        ])
        ->assertBehaviorRan(SendEmailAction::class)
        ->assertBehaviorRan(SendSmsAction::class);
});
```

## Recipe: End-to-End State Flow

Test the complete machine lifecycle without touching the database:

<!-- doctest-attr: ignore -->
```php
it('completes full order flow', function () {
    OrderMachine::test()
        ->withoutPersistence()
        ->send('SUBMIT')
        ->assertState('awaiting_payment')
        ->send('PAY')
        ->assertState('preparing')
        ->send('SHIP')
        ->assertState('shipped')
        ->send('DELIVER')
        ->assertState('delivered')
        ->assertFinished();
});

// With EventBehavior factory
it('processes order with typed event', function () {
    OrderMachine::test()
        ->withoutPersistence()
        ->send(SubmitOrderEvent::forTesting(['payload' => ['rush' => true]]))
        ->assertState('awaiting_payment')
        ->assertContext('rush', true);
});
```

## Recipe: Notification / Queue / Mail Integration

Combine Laravel's `::fake()` with TestMachine for side-effect assertions:

<!-- doctest-attr: ignore -->
```php
it('sends approval notification on approve', function () {
    Notification::fake();

    OrderMachine::test()
        ->withoutPersistence()
        ->faking([SendApprovalNotificationAction::class])
        ->send('APPROVE')
        ->assertState('approved')
        ->assertBehaviorRan(SendApprovalNotificationAction::class);
});

it('dispatches processing job', function () {
    Queue::fake();

    OrderMachine::test()
        ->withoutPersistence()
        ->send('PROCESS')
        ->assertState('processing');

    Queue::assertPushed(ProcessOrderJob::class);
});

it('sends receipt email', function () {
    Mail::fake();

    OrderMachine::test()
        ->withoutPersistence()
        ->send('COMPLETE')
        ->assertState('completed');

    Mail::assertSent(OrderReceiptMail::class);
});
```

## Recipe: Parametric Guard Testing

Test guards that accept arguments (e.g., `guardName:param1,param2`):

<!-- doctest-attr: ignore -->
```php
// Guard definition: 'guards' => 'checkDaysAfterCompletionGuard:7'
// The engine passes ['7'] as the $arguments parameter

it('blocks before 7 days', function () {
    $state = State::forTesting([
        'completed_at' => now()->subDays(3),
    ]);

    // Third parameter = guard arguments
    expect(CheckDaysAfterCompletionGuard::runWithState($state, null, ['7']))->toBeFalse();
});

it('passes after 7 days', function () {
    $state = State::forTesting([
        'completed_at' => now()->subDays(10),
    ]);

    expect(CheckDaysAfterCompletionGuard::runWithState($state, null, ['7']))->toBeTrue();
});

// Test inline parametric guard via Machine::getGuard()
it('tests inline parametric guard', function () {
    $guard = OrderMachine::getGuard('checkMinimumAmountGuard');
    $context = new ContextManager(['amount' => 50]);

    // Invoke with arguments
    expect($guard($context, ['100']))->toBeFalse();
    expect($guard($context, ['25']))->toBeTrue();
});
```

## Recipe: Side-Effect Assertions with tap()

Use `tap()` to assert side-effects (notifications, DB changes) mid-chain:

<!-- doctest-attr: ignore -->
```php
it('sends notification and updates DB on approve', function () {
    Notification::fake();

    OrderMachine::test()
        ->withoutPersistence()
        ->faking([SendApprovalNotificationAction::class])
        ->send('APPROVE')
        ->assertState('approved')
        ->tap(fn () => Notification::assertSentTo($user, ApprovalNotification::class))
        ->assertBehaviorRan(SendApprovalNotificationAction::class);
});
```

## Recipe: Scenario Testing

Test different machine scenarios with `withScenario()` (sets the `scenarioType` context key). See [Scenarios](/advanced/scenarios#testing-with-scenarios) for the production payload-based approach.

<!-- doctest-attr: ignore -->
```php
it('follows default flow without scenario', function () {
    OrderMachine::test()
        ->send('SUBMIT')
        ->assertState('review');
});

it('follows rush scenario', function () {
    OrderMachine::test()
        ->withScenario('rush')
        ->send('SUBMIT')
        ->assertState('processing');  // skips review in rush scenario
});
```

## Recipe: Entry/Exit Action Assertions

Verify entry and exit actions ran during transitions using `faking()` + `assertBehaviorRan()`:

<!-- doctest-attr: ignore -->
```php
it('runs entry action when entering state', function () {
    OrderMachine::test()
        ->faking([InitializeOrderAction::class])
        ->send('SUBMIT')
        ->assertState('awaiting_payment')
        ->assertBehaviorRan(InitializeOrderAction::class);
});

it('runs exit action when leaving state', function () {
    OrderMachine::test()
        ->faking([CleanupDraftAction::class])
        ->send('SUBMIT')
        ->assertBehaviorRan(CleanupDraftAction::class);  // exit action on 'draft' state
});

it('runs both entry and exit actions on transition', function () {
    OrderMachine::test()
        ->faking([CleanupDraftAction::class, InitializeOrderAction::class])
        ->send('SUBMIT')
        ->assertBehaviorRan(CleanupDraftAction::class)       // exit 'draft'
        ->assertBehaviorRan(InitializeOrderAction::class);    // entry 'awaiting_payment'
});
```

## Recipe: Entry Action Testing with withContext()

When initial state has entry actions that depend on context, use `withContext()` to inject values before the machine starts:

<!-- doctest-attr: ignore -->
```php
// test() applies context AFTER start — entry actions see default context
OrderMachine::test(['order_id' => 1])  // entry action already ran with null order_id

// withContext() applies context BEFORE start — entry actions see injected values
OrderMachine::withContext(['order_id' => 1])  // entry action sees order_id = 1
    ->assertState('processing')
    ->assertContextHas('order_loaded');
```

## Recipe: Testing State Restoration

<!-- doctest-attr: ignore -->
```php
it('machine continues correctly after restore', function () {
    $machine = OrderMachine::create();
    $machine->send(['type' => 'SUBMIT']);

    $rootId = $machine->state->history->first()->root_event_id;

    // Restore from DB and continue
    $restored = OrderMachine::create(state: $rootId);
    expect($restored->state->matches('awaiting_payment'))->toBeTrue();

    $restored->send(['type' => 'PAY']);
    expect($restored->state->matches('preparing'))->toBeTrue();
});
```

## Recipe: Machine Configuration Validation in Tests

Use `machine:validate` in your test suite to catch configuration errors early:

<!-- doctest-attr: ignore -->
```php
it('has valid machine configuration', function () {
    $this->artisan('machine:validate', [
        'machine' => OrderMachine::class,
    ])->assertSuccessful();
});

it('validates all machines in project', function () {
    $this->artisan('machine:validate', ['--all' => true])
        ->assertSuccessful();
});
```

::: tip CI Pipeline
Add `php artisan machine:validate --all` to your CI pipeline alongside tests and static analysis. See [Artisan Commands](/laravel-integration/artisan-commands#machine-validate) for what it checks.
:::

## Recipe: Inline Behavior Testing

Three strategies for testing inline closures defined in the `behavior` array.

<!-- doctest-attr: ignore -->
```php
// Strategy 1: Fake inline action, test machine flow
it('completes flow with faked inline action', function () {
    OrderMachine::test()
        ->faking(['broadcastAction'])
        ->send('SUBMIT')
        ->assertState('awaiting_payment')
        ->assertBehaviorRan('broadcastAction');
});

// Strategy 2: Spy on inline action + assert side effects
it('broadcasts event during transition', function () {
    Event::fake();
    InlineBehaviorFake::spy('broadcastAction');

    OrderMachine::test()
        ->send('SUBMIT')
        ->assertState('awaiting_payment');

    InlineBehaviorFake::assertRan('broadcastAction');
    Event::assertDispatched(OrderSubmitted::class);
});

// Strategy 3: Fake inline guard to control transition path
it('blocks transition when guard faked to false', function () {
    OrderMachine::test()
        ->faking(['isValidGuard' => false])
        ->assertGuarded('SUBMIT');
});
```

## Recipe: Child Machine Faking

Short-circuit child machines with `Machine::fake()` — no child actually runs. Test the parent's flow in isolation:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;

it('routes @done when child succeeds', function () {
    PaymentMachine::fake(result: ['payment_id' => 'pay_123']);

    $machine = OrderWorkflowMachine::create();
    $machine->send(['type' => 'START']);

    expect($machine->state->matches('shipping'))->toBeTrue()
        ->and($machine->state->context->get('payment_id'))->toBe('pay_123');

    PaymentMachine::assertInvoked();
    PaymentMachine::assertInvokedWith(['order_id' => 'ORD-1']);

    Machine::resetMachineFakes();
});

it('routes @fail when child fails', function () {
    PaymentMachine::fake(fail: true, error: 'Insufficient funds');

    $machine = OrderWorkflowMachine::create();
    $machine->send(['type' => 'START']);

    expect($machine->state->matches('payment_failed'))->toBeTrue()
        ->and($machine->state->context->get('error'))->toBe('Insufficient funds');

    Machine::resetMachineFakes();
});

it('child not invoked when transition is guarded', function () {
    PaymentMachine::fake(result: []);

    $machine = OrderWorkflowMachine::create();
    // Don't send START — child should NOT be invoked

    PaymentMachine::assertNotInvoked();

    Machine::resetMachineFakes();
});
```

## Recipe: Async dispatchTo Testing

Test `dispatchTo()` and `dispatchToParent()` dispatches with `Queue::fake()`:

<!-- doctest-attr: ignore -->
```php
use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\Jobs\SendToMachineJob;

it('dispatches async event to target machine', function () {
    Queue::fake();

    // ... trigger action that calls dispatchTo() ...

    Queue::assertPushed(SendToMachineJob::class, function (SendToMachineJob $job): bool {
        return $job->machineClass === TargetMachine::class
            && $job->event['type'] === 'NOTIFICATION';
    });
});
```

::: tip
For the full `Machine::fake()` API (`result`, `fail`, `error`, `finalState`) and assertion methods, see [Inter-Machine Testing](/testing/delegation-testing).
:::

## Recipe: Testing Order Cancellation After Timeout

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Support\Timer;

it('cancels order after 7 days without payment', function (): void {
    OrderMachine::test(['order_id' => 'ORD-123'])
        ->assertState('awaiting_payment')
        ->assertHasTimer('ORDER_EXPIRED')
        ->advanceTimers(Timer::days(8))
        ->assertState('cancelled')
        ->assertTimerFired('ORDER_EXPIRED')
        ->assertFinished();
});
```

## Recipe: Testing Recurring Billing

<!-- doctest-attr: no_run -->
```php
it('bills subscription every 30 days', function (): void {
    SubscriptionMachine::test()
        ->assertState('active')
        ->advanceTimers(Timer::days(31))
        ->assertContext('billing_count', 1)
        ->advanceTimers(Timer::days(31))
        ->assertContext('billing_count', 2)
        ->assertState('active');
});
```

## Recipe: Testing Retry with Max Attempts

<!-- doctest-attr: no_run -->
```php
it('retries payment 3 times then fails', function (): void {
    $test = RetryMachine::test()->assertState('retrying');

    // 3 retries
    for ($i = 1; $i <= 3; $i++) {
        $test->advanceTimers(Timer::hours(7))
            ->assertContext('retry_count', $i);
    }

    // After max → fails
    $test->advanceTimers(Timer::hours(7))
        ->assertState('failed')
        ->assertFinished();
});
```

::: tip Related
See [Overview](/testing/overview) for the testing pyramid,
[Isolated Testing](/testing/isolated-testing) for `runWithState()`,
[Fakeable Behaviors](/testing/fakeable-behaviors) for the faking API,
[Constructor DI](/testing/constructor-di) for service mocking,
[Transitions & Paths](/testing/transitions-and-paths) for guard and path testing,
[TestMachine](/testing/test-machine) for the fluent wrapper,
[Persistence Testing](/testing/persistence-testing) for DB-level testing,
and [Time-Based Testing](/testing/time-based-testing) for timer sweep testing.
:::
