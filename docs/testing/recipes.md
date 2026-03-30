# Testing Recipes

Advanced real-world testing patterns combining multiple EventMachine techniques. For basic API reference, see the dedicated guide pages.

**Categories:**
- [Behavior Patterns](#behavior-patterns) — actions, guards, calculators, events
- [State Flow](#state-flow) — @always chains, compound @done, scenarios
- [Job Actors](#job-actors) — managed jobs, failure routing
- [Parallel States](#parallel-states) — regions, failure, mixed sync/async
- [Inter-Machine](#inter-machine) — delegation, forward endpoints, context isolation
- [Advanced DX](#advanced-dx) — startingAt, fakingAllActions, selective faking
- [End-to-End](#end-to-end) — real infrastructure, full pipelines

---

## Behavior Patterns {#behavior-patterns}

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
    OrderMachine::test(['orderId' => 1])
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

Test guards that accept named parameters via tuple syntax:

<!-- doctest-attr: ignore -->
```php
// Guard definition: 'guards' => [[CheckDaysAfterCompletionGuard::class, 'days' => 7]]
// The engine passes 'days' => 7 as a named parameter to __invoke

it('blocks before 7 days', function () {
    $state = State::forTesting([
        'completed_at' => now()->subDays(3),
    ]);

    expect(CheckDaysAfterCompletionGuard::runWithState($state, configParams: ['days' => 7]))->toBeFalse();
});

it('passes after 7 days', function () {
    $state = State::forTesting([
        'completed_at' => now()->subDays(10),
    ]);

    expect(CheckDaysAfterCompletionGuard::runWithState($state, configParams: ['days' => 7]))->toBeTrue();
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

## Recipe: Entry Action Testing with Pre-Init Context

`Machine::test(context: [...])` merges context **before** initialization — entry actions on the initial state see the injected values:

<!-- doctest-attr: ignore -->
```php
// Context injected before start — entry action sees orderId = 1
OrderMachine::test(context: ['orderId' => 1])
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
For the full `Machine::fake()` API (`output`, `fail`, `error`, `finalState`) and assertion methods, see [Inter-Machine Testing](/testing/delegation-testing).
:::

## Recipe: Controller Testing with Machine::fake()

Isolate controller tests from machine pipeline -- verify DB operations without running state transitions:

<!-- doctest-attr: ignore -->
```php
it('approves consent link without running machine', function (): void {
    OrderMachine::fake();

    $consentLink = ConsentLink::factory()->create([
        'machine_root_event_id' => 'evt_123',
        'status' => ConsentLinkStatus::PENDING,
    ]);

    $this->postJson("/consent/{$consentLink->hash}/approve")
        ->assertOk()
        ->assertJson(['status' => 'approved']);

    // DB assertion — machine didn't actually run
    expect($consentLink->fresh()->status)->toBe(ConsentLinkStatus::APPROVED);

    // Machine assertions — verify it was touched
    OrderMachine::assertCreated();
    OrderMachine::assertSent(PaymentReceivedEvent::getType());
    // No cleanup needed — InteractsWithMachines handles it
});
```

`Machine::fake()` makes `create()` return a stub where `send()` and `persist()` are no-ops. The machine records what was called for assertion purposes but doesn't execute transitions or write to the database.

::: warning Don't combine fake() with test()
`Machine::fake()` is for skipping the machine. `Machine::test()` is for exercising it. Don't use both on the same class in the same test.
:::

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

## Recipe: Forward Endpoint Testing

Minimal setup for testing a forwarded event through the parent to a running child:

<!-- doctest-attr: no_run -->
```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Routing\MachineRouter;

uses(RefreshDatabase::class);

it('forwards PROVIDE_CARD to child via parent endpoint', function (): void {
    MachineRouter::register(OrderMachine::class, 'orders', 'order_mre');

    $order   = Order::create(['status' => 'pending']);
    $machine = $order->order_mre;
    $machine->send(['type' => 'START']);

    $response = $this->postJson("/orders/{$order->id}/provide-card", [
        'card_number' => '4111111111111111',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.child.value.0', 'payment_child.card_provided');
});
```

For full forward endpoint patterns including `output`, `availableEvents`, and `ForwardContext` injection, see [Inter-Machine Testing — Forward Endpoints](/testing/delegation-testing#testing-forward-endpoints).

## Recipe: Available Events Introspection

Test which events your machine accepts at each state — especially useful with forward endpoints:

<!-- doctest-attr: ignore -->
```php
OrderMachine::test(['orderId' => 'ORD-1'])
    ->assertAvailableEvent('SUBMIT_ORDER')            // initial state accepts SUBMIT
    ->send('SUBMIT_ORDER')
    ->assertNotAvailableEvent('SUBMIT_ORDER')          // no longer in initial state
    ->assertAvailableEvent('CANCEL')                   // can cancel while processing
    ->assertForwardAvailable('PROVIDE_CARD')           // forward event from child
    ->send('COMPLETE')
    ->assertNoAvailableEvents();                       // final state — no events
```

See [TestMachine — Available Events Assertions](/testing/test-machine#available-events-assertions) for the full API reference.

## Recipe: Per-Final-State Routing with @done.{state}

Test which `@done.{state}` route fires using `Machine::fake(finalState: ...)`:

<!-- doctest-attr: ignore -->
```php
PaymentMachine::fake(finalState: 'approved');

OrderMachine::test()
    ->send('START_PAYMENT')
    ->assertState('completed');
```

For the full pattern including catch-all fallback and output data, see [Inter-Machine Testing — Testing Per-Final-State Routing](/testing/delegation-testing#testing-per-final-state-routing).

## Recipe: Full Async Delegation Pipeline

When `Queue::fake()` isn't enough — verify the complete async cycle: parent dispatches → child runs → child completes → parent routes via `@done`.

**Requirements:** Real database + Redis + queue worker (Horizon or `queue:work`).

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Models\MachineCurrentState;

it('completes full async delegation pipeline', function (): void {
    $parent = ParentMachine::create();
    $parent->send(['type' => 'START']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Parent should be in delegating state
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('processing');

    // Poll DB for parent state change (queue worker processes async)
    $completed = retry(30, function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs && str_contains($cs->state_id, 'completed')
            ? true
            : throw new \Exception('waiting');
    }, sleepMilliseconds: 1000);

    expect($completed)->toBeTrue();
});
```

::: warning Gotchas
- `.env.testing` must have real DB + Redis config (not sqlite/sync)
- Redis prefix must match between test process and queue worker
- Queue worker must be started **before** the test runs
- Clean tables between tests with truncate (not `RefreshDatabase` which rolls back)
:::

## Recipe: Complex Event Payloads

When guards or actions depend on rich event payloads (nested items, calculated dates, DB-seeded relationships), use [EventBuilder](/testing/isolated-testing#eventbuilder) to encapsulate the complexity. The builder handles data generation, the test focuses on behavior.

<!-- doctest-attr: ignore -->
```php
// Test stays clean — builder handles data generation
it('calculates stock for each order item', function () {
    $event = ApplicationStartedEvent::builder()
        ->withOrderItems(3)
        ->make();

    $state = State::forTesting($context, currentEventBehavior: $event);
    CalculateStockDetailsGuard::runWithState($state);

    expect($state->context->get('stock_details'))->toHaveCount(3);
});

// Validation testing with raw()
it('rejects event without required order items', function () {
    $raw = ApplicationStartedEvent::builder()->raw();

    expect(fn () => ApplicationStartedEvent::validateAndCreate($raw))
        ->toThrow(ValidationException::class);
});

// Immutable branching — same base, different scenarios
it('handles different order sizes', function () {
    $base = ApplicationStartedEvent::builder();

    $small = $base->withOrderItems(1)->make();
    $large = $base->withOrderItems(10)->make();

    expect($small->payload['order_items'])->toHaveCount(1);
    expect($large->payload['order_items'])->toHaveCount(10);
});
```

## Job Actors {#job-actors}

## Recipe: Testing Managed Job Completion

Test `@done` routing for a managed job actor without running the job. Use `Queue::fake()` to capture the dispatch, then `simulateChildDone()` to simulate the output:

<!-- doctest-attr: no_run -->
```php
it('routes to completed after job finishes', function (): void {
    Queue::fake();

    PaymentMachine::test()
        ->withoutPersistence()
        ->send('START_PAYMENT')
        ->assertState('charging')
        ->simulateChildDone(ChargeCardJob::class, output: [
            'transactionId' => 'txn_123',
            'amount'         => 5000,
        ])
        ->assertState('completed')
        ->assertContext('transactionId', 'txn_123');
});
```

## Recipe: Testing Job Failure Routing

Test `@fail` routing when a managed job fails:

<!-- doctest-attr: no_run -->
```php
it('routes to failed state when job throws', function (): void {
    Queue::fake();

    PaymentMachine::test()
        ->withoutPersistence()
        ->send('START_PAYMENT')
        ->assertState('charging')
        ->simulateChildFail(ChargeCardJob::class,
            errorMessage: 'Card declined',
            errorCode: 402,
        )
        ->assertState('payment_failed');
});
```

## Advanced DX {#advanced-dx}

## Recipe: Testing a Deep State Without Path Replay

Use `startingAt()` to skip directly to a deep state without replaying the entire path:

<!-- doctest-attr: no_run -->
```php
it('handles PIN retry from processing_payment state', function (): void {
    Queue::fake();

    VerificationMachine::startingAt(
        stateId: 'processing_payment',
        context: ['orderId' => 'ORD-1', 'amount' => 5000],
        guards: [IsRetryableGuard::class => true],
    )
    ->fakingAllActions(except: [StorePaymentAction::class])
    ->simulateChildFail(ProcessPaymentJob::class, errorMessage: 'Wrong PIN')
    ->assertState('awaiting_pin');
});
```

## Recipe: Focused Action Testing with fakingAllActions(except:)

Test a single action's behavior by faking everything else:

<!-- doctest-attr: no_run -->
```php
it('CalculatePricesAction sets installment options', function (): void {
    OrderMachine::test(
        context: ['vehicle_price' => 100000, 'down_payment' => 20000],
    )
    ->fakingAllActions(except: [CalculatePricesAction::class])
    ->send('SUBMIT_VEHICLE')
    ->assertState('awaiting_payment_options')
    ->assertContextMatches('installment_options', fn ($options) => count($options) > 0);
});
```

## Recipe: Testing Parallel Regions with Child Delegation

Test a parent machine whose parallel regions delegate to child machines:

<!-- doctest-attr: ignore -->
```php
it('verification parallel state completes when both children finish', function (): void {
    Queue::fake();

    OrderMachine::test(
        context: [...],
        guards: [
            IsEligibleGuard::class         => true,
            HasSufficientFundsGuard::class => false,
        ],
        faking: [InitializeOrderAction::class],
    )
    ->withoutPersistence()
    ->fakingAllActions()
    ->fakingChild(VerificationMachine::class, output: [...], finalState: 'completed')
    ->fakingChild(NotificationMachine::class, output: [...])
    ->assertState('checking_protocol');
});
```

Both child machines are faked — they complete immediately when the parent enters the parallel `verification` state. The parent's `@done` guard fires and transitions to `checking_protocol`.

## State Flow {#state-flow}

## Recipe: @always Guard Chain Routing

Test a machine with multiple `@always` branches — each guarded, first match wins:

<!-- doctest-attr: no_run -->
```php
it('routes to correct target based on guard results', function (): void {
    // Machine has: idle → @always [
    //   { target: 'premium',  guards: IsPremiumGuard },
    //   { target: 'standard', guards: IsEligibleGuard },
    //   { target: 'rejected' },  // fallback
    // ]

    // Premium path
    VerificationMachine::test(
        guards: [IsPremiumGuard::class => true],
    )->assertState('premium');

    // Standard path (not premium, but eligible)
    VerificationMachine::test(
        guards: [IsPremiumGuard::class => false, IsEligibleGuard::class => true],
    )->assertState('standard');

    // Rejected path (no guards pass → fallback)
    VerificationMachine::test(
        guards: [IsPremiumGuard::class => false, IsEligibleGuard::class => false],
    )->assertState('rejected');
});
```

## Recipe: Compound @done with Delegation

Test a compound state reaching final → `@done` transitions to a state with child delegation:

<!-- doctest-attr: no_run -->
```php
it('compound @done triggers child delegation', function (): void {
    Queue::fake();

    // Machine: review (compound, initial: checking) → checking is final
    //          → @done → processing (machine: PaymentMachine)
    //          → @done → completed
    ApprovalMachine::test(context: ['orderId' => 'ORD-1'])
        ->withoutPersistence()
        ->fakingAllActions()
        ->assertState('processing')  // compound @done already fired
        ->simulateChildDone(PaymentMachine::class, output: ['paymentId' => 'pay_1'])
        ->assertState('completed');
});
```

## Parallel States {#parallel-states}

## Recipe: Parallel Region Failure

Test that when one parallel region fails, the parent's `@fail` fires:

<!-- doctest-attr: no_run -->
```php
it('parallel @fail fires when one region fails', function (): void {
    Queue::fake();

    // ShippingMachine has parallel: warehouse + delivery regions
    // Both delegate to child machines
    ShippingMachine::test(context: ['orderId' => 'ORD-1'])
        ->withoutPersistence()
        ->fakingAllActions()
        ->fakingChild(WarehouseMachine::class, output: ['packed' => true])
        ->fakingChild(DeliveryMachine::class, fail: true, error: 'Address not found')
        ->assertState('shipping_failed');
});
```

## Recipe: Mixed Sync/Async Children in Parallel

Test a parallel state with one sync child (completes immediately) and one async child (via queue):

<!-- doctest-attr: no_run -->
```php
it('sync child completes immediately, async child via Horizon', function (): void {
    Queue::fake();

    // OrderMachine has parallel: validation (sync) + payment (async queue)
    $test = OrderMachine::test(context: ['orderId' => 'ORD-1'])
        ->withoutPersistence()
        ->fakingAllActions();

    // Validation (sync) completed immediately
    // Payment (async) dispatched to queue — still at 'processing'
    $test->assertRegionState('validation', 'completed');

    // Simulate async payment completion
    $test->simulateChildDone(PaymentMachine::class, output: ['paymentId' => 'pay_1'])
        ->assertAllRegionsCompleted()
        ->assertState('fulfilled');
});
```

## Inter-Machine {#inter-machine}

## Recipe: Forward Endpoint with Event Validation

Test that forwarded events validate payload before reaching the child:

<!-- doctest-attr: no_run -->
```php
it('forward endpoint validates event payload', function (): void {
    MachineRouter::register(OrderMachine::class, 'orders', 'order_mre');

    $order   = Order::create(['status' => 'pending']);
    $machine = $order->order_mre;
    $machine->send(['type' => 'START']);

    // Forward with invalid payload — validation fails
    $response = $this->postJson("/orders/{$order->id}/provide-card", [
        'card_number' => '',  // required field empty
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['card_number']);

    // Forward with valid payload — reaches child
    $response = $this->postJson("/orders/{$order->id}/provide-card", [
        'card_number' => '4111111111111111',
    ]);

    $response->assertOk();
});
```

## Recipe: Context Isolation Between Parent and Child

Test that `with:` passes only specified context keys and child modifications don't affect parent:

<!-- doctest-attr: no_run -->
```php
it('child receives only with: keys, parent context unchanged', function (): void {
    Queue::fake();

    // OrderMachine delegates to PaymentMachine with: ['orderId', 'amount']
    // Parent has orderId, amount, customerName — child only gets first two
    OrderMachine::test(
        context: [
            'orderId'       => 'ORD-1',
            'amount'        => 5000,
            'customerName' => 'John',
        ],
    )
    ->withoutPersistence()
    ->fakingAllActions()
    ->fakingChild(PaymentMachine::class, output: ['paymentId' => 'pay_1'])
    ->assertState('completed')
    ->assertContext('customerName', 'John')  // parent context preserved
    ->assertChildInvokedWith(PaymentMachine::class, [
        'orderId' => 'ORD-1',
        'amount'   => 5000,
        // customer_name NOT passed — not in with: config
    ]);
});
```

## End-to-End {#end-to-end}

## Recipe: Full Pipeline with Real Infrastructure

End-to-end test with real MySQL + Redis + Horizon. See [Real Infrastructure Testing](/testing/localqa) for setup.

<!-- doctest-attr: no_run -->
```php
it('full async pipeline: parent → child → completion → @done', function (): void {
    // No Queue::fake() — real queue processing via Horizon
    $parent = OrderMachine::create();
    $parent->send(['type' => 'START_PAYMENT']);
    $parent->persist();

    $rootEventId = $parent->state->history->first()->root_event_id;

    // Wait for Horizon to: dispatch child → child completes → route @done
    $completed = LocalQATestCase::waitFor(function () use ($rootEventId) {
        $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();

        return $cs !== null && str_contains($cs->state_id, '.completed');
    }, timeoutSeconds: 45);

    expect($completed)->toBeTrue('Async pipeline did not complete');

    // Verify final state
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toBe('order.completed');
});
```
