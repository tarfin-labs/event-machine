# TestMachine

Fluent test wrapper inspired by `Livewire::test()`. Provides a chainable API for sending events and asserting state, context, transitions, and behavior execution.

## Construction

For class-based machines, use `Machine::test()` and `Machine::startingAt()`. For inline throwaway definitions, use `TestMachine::define()`.

<!-- doctest-attr: ignore -->
```php
// From a Machine subclass — THE entry point for class-based testing
TrafficLightsMachine::test()
TrafficLightsMachine::test(context: ['count' => 42])
TrafficLightsMachine::test(context: [...], guards: [...], faking: [...])

// Start at a specific state — skip path replay
TrafficLightsMachine::startingAt('active', context: ['count' => 42])

// Inline definition (no Machine class, no persistence)
TestMachine::define(
    config: ['id' => 'counter', 'initial' => 'active', 'context' => ['count' => 0], 'states' => [
        'active' => ['on' => ['GO' => ['target' => 'done']]],
        'done'   => [],
    ]],
)
```

## Configuration

Chain configuration methods before sending events to customize machine behavior for the test.

<!-- doctest-attr: ignore -->
```php
->faking([SendEmailAction::class, ChargePaymentAction::class])  // class-based: spy mode
->faking(['broadcastAction'])                                      // inline: fake with no-op
->faking(['isValidGuard' => true])                                 // inline: fake with return value
->faking(['calcTax' => fn(ContextManager $ctx) => $ctx->set('tax', 0)])  // inline: custom replacement
->withoutPersistence()                                            // skip DB writes
->withoutParallelDispatch()                                       // run regions sequentially
// Scenarios are activated via HTTP endpoints, not TestMachine.
// See /advanced/scenarios for the QA workflow.
```

## Sending Events

<!-- doctest-attr: ignore -->
```php
->send('SUBMIT')                                              // string shorthand
->send(['type' => 'PAY', 'payload' => ['amount' => 100]])    // full event
->send(PaymentEvent::forTesting()->toArray())                 // from factory
->sendMany(['SUBMIT', 'PAY', 'SHIP'])                        // sequence
```

## State Assertions

<!-- doctest-attr: ignore -->
```php
->assertState('awaiting_payment')
->assertState('checkout.payment')          // hierarchical
->assertNotState('cancelled')
->assertFinished()                         // current state is type:final
->assertOutput($expected)                  // final state output value
```

## Context Assertions

<!-- doctest-attr: ignore -->
```php
->assertContext('total', 100)
->assertContextHas('paid_at')
->assertContextMissing('error')
->assertContextMatches('amount', fn($v) => $v > 0 && $v < 10000)
->assertContextIncludes(['a' => 1, 'b' => 2])
```

## Transition Assertions

<!-- doctest-attr: ignore -->
```php
->assertTransition('NEXT', 'yellow')       // send + assertState in one
->assertGuarded('SHIP')                    // event blocked, state unchanged
->assertGuardedBy('SHIP', IsStockAvailableGuard::class)  // blocked by specific guard
->assertValidationFailed('PAY', 'amount')  // MachineValidationException thrown
```

## History Assertions

<!-- doctest-attr: ignore -->
```php
->assertHistoryContains('SUBMIT', 'PAY')
->assertHistoryOrder('SUBMIT', 'PAY', 'SHIP')  // events appear in this order
->assertTransitionedThrough(['idle', 'processing', 'done'])  // states visited (including @always)
```

## Path Assertions

<!-- doctest-attr: ignore -->
```php
->assertPath([
    ['event' => 'START',   'state' => 'active'],
    ['event' => 'PROCESS', 'state' => 'done', 'context' => ['completed' => true]],
])
```

## Parallel Assertions

<!-- doctest-attr: ignore -->
```php
->assertRegionState('payment', 'charged')
->assertAllRegionsCompleted()              // any parallel state's @done fired
->assertAllRegionsCompleted('processing')  // specific parallel state route
```

## Behavior Assertions

<!-- doctest-attr: ignore -->
```php
->assertBehaviorRan(SendEmailAction::class)        // class-based
->assertBehaviorRan('broadcastAction')              // inline
->assertBehaviorRan([SendEmailAction::class, 'broadcastAction'])  // batch — mixed entries allowed
->assertBehaviorNotRan(RefundAction::class)
->assertBehaviorRanTimes(SendEmailAction::class, 2)
->assertBehaviorRanWith(SendEmailAction::class, fn($ctx) => $ctx->get('email') !== null)
->assertBehaviorRanWith('myAction', fn(array $params) => $params[0]->get('done'))  // inline: array param
```

The array form asserts each entry ran and the failure names the entry that didn't; an empty array throws `InvalidArgumentException`.

## Available Events Assertions

Assert which events the machine's current state can accept. Useful for verifying the machine exposes the correct API at each step — especially with forward endpoints.

<!-- doctest-attr: ignore -->
```php
// Assert a specific event is available
->assertAvailableEvent('SUBMIT_ORDER')

// Assert an event is NOT available (e.g., after transitioning away)
->assertNotAvailableEvent('SUBMIT_ORDER')

// Assert exact set of available event types
->assertAvailableEvents(['SUBMIT_ORDER', 'CANCEL'])

// Assert a forwarded event is available (source: 'forward')
->assertForwardAvailable('PROVIDE_CARD')

// Assert no events available (final state)
->assertNoAvailableEvents()
```

| Method | Description |
|--------|-------------|
| `assertAvailableEvent(string $eventType)` | Event type is available in current state |
| `assertNotAvailableEvent(string $eventType)` | Event type is NOT available |
| `assertAvailableEvents(array $expectedTypes)` | Exact set of available events matches |
| `assertForwardAvailable(string $eventType)` | Forwarded event is available (`source: 'forward'`) |
| `assertNoAvailableEvents()` | No events available — current state is final or has no transitions |

::: tip Parent vs Forward Events
`assertAvailableEvent()` matches events from any source. `assertForwardAvailable()` specifically matches events with `source: 'forward'` — those auto-generated from the `forward` config on delegation states. See [Available Events](/laravel-integration/available-events) for details.
:::

## Timer Testing

Methods for testing time-based events (`after`/`every` on transitions).

<!-- doctest-attr: ignore -->
```php
// Advance time and run timer sweep
->advanceTimers(Timer::days(7))

// Run sweep without advancing time
->processTimers()

// Assert timer exists on current state
->assertHasTimer('ORDER_EXPIRED')

// Assert timer fired / not fired
->assertTimerFired('ORDER_EXPIRED')
->assertTimerNotFired('ORDER_EXPIRED')
```

See [Time-Based Testing](/testing/time-based-testing) for complete examples.

## Accessors

When you need direct access to the underlying machine, state, or context — for example, to perform custom assertions not covered by the built-in methods.

<!-- doctest-attr: ignore -->
```php
->machine()     // underlying Machine instance
->state()       // current State object
->context()     // ContextManager instance
```

## Utilities

Helper methods for debugging and mid-chain side effects.

<!-- doctest-attr: ignore -->
```php
// Execute a callback mid-chain for side-effect assertions
->tap(fn($test) => Notification::assertSentTo($user, ApprovalNotification::class))

// Debug guard evaluation results (WARNING: sends the event, mutates state)
->debugGuards('SUBMIT')  // returns ['IsValidGuard' => true, 'HasStockGuard' => false]
```

## Cleanup

Call `resetFakes()` when you need to clear fakes mid-test. In most cases, the global `afterEach` hook handles this automatically.

<!-- doctest-attr: ignore -->
```php
->resetFakes()  // cleanup faked behaviors registered via faking()
```

> **Note:** As of v7.4, `resetFakes()` also clears `Machine::fake()` registrations and CommunicationRecorder state.

> **Note:** With the `InteractsWithMachines` trait, manual `resetMachineFakes()` calls are no longer needed. The trait resets all Machine fakes, CommunicationRecorder, and InlineBehaviorFake state automatically after each test.

## Complete Example

<!-- doctest-attr: ignore -->
```php
TrafficLightsMachine::test()
    ->assertState('active')
    ->assertContext('count', 0)
    ->send('INCREASE')
    ->send('INCREASE')
    ->assertContext('count', 2)
    ->assertHistoryContains('INCREASE')
    ->assertPath([
        // assertPath sends each event, then checks state + context
        ['event' => 'INCREASE', 'state' => 'active', 'context' => ['count' => 3]],
    ]);
```

## Direct State Access

Use direct state access when you need raw values for complex assertions, or when integrating with external assertion libraries that don't work with TestMachine's fluent API.

For advanced cases where TestMachine doesn't fit, you can access the underlying state directly:

<!-- doctest-attr: ignore -->
```php
// Direct state matching
expect($machine->state->matches('processing'))->toBeTrue();
expect($machine->state->value)->toBe(['order.processing']);

// Context access
expect($machine->state->context->get('total'))->toBe(100);
expect($machine->state->context->has('paid_at'))->toBeTrue();

// History inspection
expect($machine->state->history->pluck('type'))->toContain('SUBMIT');
```

## Timer Helpers

For testing time-based events (`after`/`every` on transitions):

<!-- doctest-attr: ignore -->
```php
// Advance time and trigger timer sweep inline
$machine->test()
    ->advanceTimers(Timer::days(8))
    ->assertState('expired');

// Run timer sweep without advancing time
$machine->test()
    ->processTimers()
    ->assertState('active');  // no timers due yet

// Assert timer exists / fired / not fired
$machine->test()
    ->assertHasTimer('ORDER_EXPIRED')
    ->advanceTimers(Timer::days(8))
    ->assertTimerFired('ORDER_EXPIRED');

$machine->test()
    ->assertTimerNotFired('ORDER_EXPIRED');
```

See [Time-Based Testing](/testing/time-based-testing) for full details.

## Schedule Helpers

For testing scheduled events (`schedules` key on definition):

<!-- doctest-attr: ignore -->
```php
// Send scheduled event inline (bypasses queue)
$machine->test()
    ->runSchedule('CHECK_EXPIRY')
    ->assertState('expired');

// Assert schedule exists
$machine->test()
    ->assertHasSchedule('CHECK_EXPIRY')
    ->assertHasSchedule('DAILY_REPORT');
```

See [Scheduled Testing](/testing/scheduled-testing) for full details.

## Child Delegation Assertions

When a state delegates to a child machine, use `fakingChild()` to short-circuit the delegation and control the outcome. Then assert what happened using the child assertion methods.

<!-- doctest-attr: ignore -->
```php
OrderMachine::test()
    ->fakingChild(PaymentMachine::class, output: ['id' => 'pay_1'], finalState: 'approved')
    ->send('PLACE_ORDER')
    ->assertChildInvoked(PaymentMachine::class)
    ->assertChildNotInvoked(AuditMachine::class)
    ->assertChildInvokedTimes(PaymentMachine::class, 1)
    ->assertChildInvokedWith(PaymentMachine::class, ['orderId' => 'ORD-1'])
    ->assertRoutedViaDoneState('approved')
```

| Method | Description |
|--------|-------------|
| `fakingChild(class, output, finalState)` | Short-circuit child delegation with a given outcome |
| `assertChildInvoked(class)` | Assert the child machine was invoked at least once |
| `assertChildNotInvoked(class)` | Assert the child machine was never invoked |
| `assertChildInvokedTimes(class, int)` | Assert invocation count |
| `assertChildInvokedWith(class, array)` | Assert initial context passed to the child |
| `assertRoutedViaDoneState(string)` | Assert the `@done` routing used a specific child final state |

::: tip
Chain multiple `fakingChild()` calls for multiple children. This keeps the API simple and each call explicit.
:::

<!-- doctest-attr: ignore -->
```php
// Complete fluent delegation testing pattern
OrderMachine::test()
    ->fakingChild(PaymentMachine::class, output: ['id' => 'pay_1'], finalState: 'approved')
    ->fakingChild(FraudCheckMachine::class, output: ['score' => 0.1], finalState: 'clear')
    ->send('PLACE_ORDER')
    ->assertChildInvoked(PaymentMachine::class)
    ->assertChildInvoked(FraudCheckMachine::class)
    ->assertRoutedViaDoneState('approved')
    ->assertState('order_confirmed')
    ->assertContext('paymentId', 'pay_1')
```

## Async Simulation

Use `simulateChild*` methods to trigger completion on a parent that is already waiting for a child — as opposed to `fakingChild()` which short-circuits at the delegation entry point. Works for both **machine delegation** (`machine:` key) and **job actors** (`job:` key) — the routing infrastructure is identical.

<!-- doctest-attr: ignore -->
```php
->simulateChildDone(PaymentMachine::class, output: ['id' => 'pay_1'], finalState: 'approved')
->simulateChildFail(PaymentMachine::class, errorMessage: 'Insufficient funds')
->simulateChildTimeout(PaymentMachine::class)
```

| Method | Description |
|--------|-------------|
| `simulateChildDone(class, output, finalState)` | Trigger `@done` transition as if the child completed successfully |
| `simulateChildFail(class, errorMessage)` | Trigger `@fail` transition as if the child threw an error |
| `simulateChildTimeout(class)` | Trigger `@timeout` transition as if the child exceeded its deadline |

::: info
The `output` parameter populates the `output()` accessor on the event, matching `Machine::fake()` behavior. Prefer passing a typed `MachineOutput` instance over a raw array — it is supported directly: `simulateChildDone(PaymentMachine::class, output: new PaymentOutput(id: 'pay_1'))`.
:::

::: warning finalState is validated
For machine-delegation children, `finalState` is validated against the child definition's FINAL states — a typo or renamed child final state throws an `AssertionFailedError` listing the child's actual final states. Both the leaf name (`'approved'`) and the full dotted id (`'payment_machine.approved'`) are accepted; either way the done event carries the **leaf** key, exactly like the real completion pipeline (`@done.{state}` routing only ever sees the leaf). Job actors have no state tree, so their `finalState` is not validated.
:::

**`fakingChild()` vs `simulateChildDone()`:**

- `fakingChild()` — short-circuits at delegation entry. The child is never dispatched. Use this for most unit tests where you control the happy/failure path.
- `simulateChild*()` — the parent has already entered the waiting state (child was dispatched). Use this when testing a parent that was constructed around an in-progress child, or when you need to test the parent's response to a completion event independently.

**Job actor example** (use `Queue::fake()` to capture the dispatch):

<!-- doctest-attr: no_run -->
```php
Queue::fake();

MyMachine::test()
    ->withoutPersistence()
    ->send('START')
    ->assertState('processing')
    ->simulateChildDone(ProcessDataJob::class, output: ['status' => 'done'])
    ->assertState('completed');
```

## Bulk Faking

Fake all class-based behaviors in one call instead of listing each one:

<!-- doctest-attr: ignore -->
```php
->fakingAllActions()                                   // all actions → spy
->fakingAllActions(except: [StorePaymentAction::class])    // all except this one
->fakingAllActions(except: ['storePinAction'])          // by behavior key

->fakingAllGuards()                                    // all guards → spy
->fakingAllBehaviors()                                 // actions + guards + calculators
```

The `except:` parameter accepts both class FQCNs and behavior key strings. Excluded behaviors run their real logic.

::: warning Testing Nothing Trap
`fakingAllActions()` without `except:` means NO action logic runs. Combined with `fakingAllGuards()`, your test only verifies transition wiring (`@done → state_x`) — which is already visible in the machine config.

**Ask yourself: "What behavior am I actually testing?"**

<!-- doctest-attr: ignore -->
```php
// ✅ Good — tests StorePaymentAction logic:
->fakingAllActions(except: [StorePaymentAction::class])

// ⚠️ Questionable — tests nothing but config:
->fakingAllActions()
->fakingAllGuards()
```
:::

::: tip fakingAllActions() vs fakingAllBehaviors()
`fakingAllActions()` only fakes actions. Calculators are NOT affected. If your test passes through `@always` transitions with calculators, use `fakingAllBehaviors()` which includes actions + guards + calculators.
:::

### Pre-Init Behavior Faking

The `guards:` and `faking:` parameters on `Machine::test()` and `Machine::startingAt()` set behavior fakes **before** `getInitialState()` runs — solving the `@always` timing problem where guards and actions run before the fluent chain reaches `fakingAllActions()`:

<!-- doctest-attr: no_run -->
```php
VerificationMachine::test(
    context: ['orderId' => 'ORD-1'],
    guards: [
        HasExistingOrderGuard::class  => false,
        IsPaymentValidGuard::class       => true,
    ],
    faking: [InitializeOrderAction::class],
)
->fakingAllActions()
->assertState('processing');
```

- `guards:` — sets guard return values (`$class::shouldReturn($value)`)
- `faking:` — spies behavior classes (`$class::spy()`) — prevents real side effects during init

### Batch Spying — spying()

Spy several class-based behaviors in one fluent call instead of N consecutive `SomeAction::spy();` lines:

<!-- doctest-attr: ignore -->
```php
OrderMachine::test()
    ->spying([BroadcastStateAction::class, StoreTcknAction::class, StoreCustomerPhoneAction::class])
    ->send('SUBMIT')
    ->assertBehaviorRan([StoreTcknAction::class, StoreCustomerPhoneAction::class]);
```

- Accepts only `InvokableBehavior` subclass FQCNs — inline behavior keys throw `InvalidArgumentException` with a hint to use `InlineBehaviorFake::spy('key')`; an empty array also throws.
- **Timing**: `spying()` applies AFTER machine initialization — it does NOT observe initial-state entry actions or `@always` chains fired during boot. Use the pre-init `faking:` parameter of `test()`/`startingAt()` when boot-time behaviors must be observed.
- `spy()` is idempotent — spying a class already spied (e.g. by `fakingAllActions()`) is a harmless no-op.

### Isolated Preset — testIsolated()

`Machine::testIsolated()` bundles the most common isolated-test opener — exactly equivalent to `test($context, faking: $faking)->fakingAllActions()` (persistence is already disabled by `test()`):

<!-- doctest-attr: ignore -->
```php
// Before:
$machine = OrderMachine::test()->withoutPersistence()->fakingAllActions()->send('SUBMIT');

// After:
$machine = OrderMachine::testIsolated()->send('SUBMIT');
```

::: warning except: needs the long form
`fakingAllActions(except:)` with a non-empty `except:` list throws `LogicException` after `testIsolated()` — the preset already spied all actions and spies cannot be selectively undone. Tests needing `except:` must use the long form `test()->fakingAllActions(except: [...])`.
:::

## Transition Table Assertions

`Machine::assertTransitions()` verifies a state×event→target table — one machine boot per row via `startingAt()`, formalizing the one-edge-per-test pattern. See [Testing Transitions & Paths](/testing/transitions-and-paths#table-driven-transition-testing) for the full reference:

<!-- doctest-attr: ignore -->
```php
FindeksMachine::assertTransitions([
    ['from' => 'findeks.report_retrieval.syncing_phones',  'event' => 'PHONES_SYNCED',   'to' => 'findeks.report_retrieval.checking_consent'],
    ['from' => 'findeks.awaiting_consent',                  'event' => 'RETRY_REQUESTED', 'to' => null, 'guarded' => true],
], context: ['tckn' => '12345678901'], faking: [StorePhonesAction::class]);
```
## Starting at a Specific State

Skip path replay and start the machine at any state:

<!-- doctest-attr: no_run -->
```php
VerificationMachine::startingAt(
    stateId: 'processing_payment',
    context: ['orderId' => 'ORD-1', 'amount' => 5000],
    guards: [IsRetryableGuard::class => true],
)
->fakingAllActions(except: [StorePaymentAction::class])
->send(PaymentConfirmedEvent::make(['confirmationCode' => 'ABC123']))
->assertState('verifying');
```

`startingAt()` creates the machine at the given state without running its entry lifecycle: the target state's entry actions and job dispatch are skipped. Eventless (`@always`) transitions **are** processed, though — a state whose `@always` guard passes is not a restable configuration, so the machine stabilizes exactly like a real start would. This makes auto-routing states directly testable:

<!-- doctest-attr: ignore -->
```php
// checking_info routes via guarded @always — assert the routing itself
ApplicationMachine::startingAt('checking_info', context: ['score' => 10])
    ->assertState('rejected');

// Need to park AT the transient state? Pin its @always guards to false:
ApplicationMachine::startingAt('checking_info', guards: [IsEligibleGuard::class => false])
    ->assertState('checking_info');
```

The machine uses the real definition — all transitions, guards, and actions are available. The state id may be a leaf name, a machine-relative dotted path (`'parent.region.leaf'`), or the full id.

### Parallel states

Starting at a parallel state activates **every region at its initial leaf** — matching real parallel-entry semantics — so region events route and `@done` fires when all regions reach their finals. Starting at a leaf (or region) **inside** one region anchors the machine on the parallel parent and initializes all sibling regions at their initial leaves:

<!-- doctest-attr: ignore -->
```php
// Parallel parent: both regions live at their initials
OrderMachine::startingAt('data_collection')          // ['...product.awaiting_selection', '...customer_info.awaiting']
    ->send(ProductSelectedEvent::forTesting())
    ->send(CustomerInfoSubmittedEvent::forTesting())
    ->send(ApplicationSubmittedEvent::forTesting())   // gating guard sees both regions final
    ->assertState('submitted');

// Leaf inside one region: siblings auto-initialize
OrderMachine::startingAt('data_collection.product.product_selected')
    ->send(CustomerInfoSubmittedEvent::forTesting())  // sibling region is live
    ->send(ApplicationSubmittedEvent::forTesting())
    ->assertState('submitted');
```

The `@always` drain covers region leaves too: starting at a leaf whose `@always` guard passes routes that region (running the transition's actions) while sibling regions stay at their leaves — the same behavior as real parallel entry.

<!-- doctest-attr: ignore -->
```php
// retailer region routes via its leaf @always; documents region stays put
CarSalesMachine::startingAt('processing.retailer.calculating')
    ->assertRegionState('retailer', 'awaiting_options')
    ->assertRegionState('documents', 'awaiting_docs');
```

No more hand-copied region configs to test parallel gating — use the real definition.

::: tip When to use startingAt() vs withContext()
- **`withContext()`** — tests the full path from initial state. Entry actions and `@always` run. Use for integration tests that validate the complete flow.
- **`startingAt()`** — tests behavior FROM a specific state. No path validation. Use for focused unit tests of a single transition or state behavior.
:::

## Cross-Machine Communication Assertions

Assert that the machine sent events to other machines, dispatched async messages, or raised internal events.

<!-- doctest-attr: ignore -->
```php
OrderMachine::test()
    ->recordingCommunication()
    ->send('SHIP')
    ->assertSentTo(WarehouseMachine::class)
    ->assertSentTo(WarehouseMachine::class, 'PICK_ORDER')
    ->assertNotSentTo(RefundMachine::class)
    ->assertDispatchedTo(AuditMachine::class)
    ->assertRaisedEvent('RETRY')
```

| Method | Description |
|--------|-------------|
| `recordingCommunication()` | Enable communication recording (skips actual `sendTo` calls) |
| `assertSentTo(class)` | Assert at least one synchronous `sendTo` was made to the target |
| `assertSentTo(class, string)` | Assert a specific event type was sent to the target |
| `assertNotSentTo(class)` | Assert no `sendTo` calls were made to the target |
| `assertDispatchedTo(class)` | Assert a `dispatchTo` (queued) call was made to the target |
| `assertRaisedEvent(string)` | Assert an internal `raise()` was called and the event was processed |

::: info
`assertRaisedEvent()` asserts the event was raised AND processed (appears in history). This is a stronger assertion than checking the raise call alone. For unit-level raise testing (without a full machine), use `assertRaised()` after `runWithState()` — see [Isolated Testing — Raised Events](/testing/isolated-testing#actions-asserting-raised-events).
:::

> **Note:** `assertDispatchedTo` requires `Queue::fake()` to intercept queued jobs.
> `recordingCommunication()` skips actual `sendTo` calls — the target machine does not need to exist in the database.

## Forward Endpoint Helpers

When testing delegation states that expose a `forward` endpoint, use `withRunningChild()` to simulate an already-running child so the parent can accept forwarded events.

<!-- doctest-attr: ignore -->
```php
OrderMachine::test()
    ->withRunningChild(PaymentMachine::class)
    ->send('PROVIDE_CARD', ['number' => '4111...'])
    ->assertForwardAvailable('PROVIDE_CARD')
```

| Method | Description |
|--------|-------------|
| `withRunningChild(class)` | Simulate a running child machine so the parent can receive forwarded events |

::: tip
The child starts in its initial state. If you need the child in a specific state for forward testing, create it separately with `TestMachine::for()` and send events to reach the desired state.
:::

::: warning withRunningChild() requires persistence
`withRunningChild()` creates a child record in the database. It does NOT work with `withoutPersistence()` or `TestMachine::define()`. Use `Machine::test()` (persistence enabled by default).
:::

## When to Use What

| Scenario | Use | Why |
|----------|-----|-----|
| Full machine flow with assertions | `Machine::test()` | Fluent chain, automatic cleanup, pre-init context |
| Skip to deep state | `Machine::startingAt()` | No path replay, focused testing |
| Quick inline definition | `TestMachine::define()` | No Machine class needed |
| Wrapping existing instance | `TestMachine::for($machine)` | Access fluent API on pre-built machine |
| Quick transition unit test | `MachineDefinition::define()` + `transition()` | Lightweight, no TestMachine overhead |

**Rule of thumb:** Use `Machine::test()` for class-based machines, `TestMachine::define()` for inline throwaway machines, `MachineDefinition::define()` when you only need `getInitialState()` + `transition()`.

## Method Compatibility

| Method | withoutPersistence() | startingAt() | fakingAllActions() |
|--------|---------------------|-------------|-------------------|
| assertRegionState() | ✅ | ✅ | ✅ |
| assertAllRegionsCompleted() | ✅ | ✅ | ✅ |
| simulateChildDone/Fail() | ✅ | ✅ | ✅ |
| fakingChild() | ✅ | ✅ | ✅ |
| recordingCommunication() | ✅ | ✅ | ✅ |
| assertSentTo/DispatchedTo() | ✅ | ✅ | ✅ |
| withRunningChild() | ❌ Requires DB | ❌ Requires DB | ✅ |
| advanceTimers() | ✅ | ✅ | ✅ |

::: tip Related
See [Isolated Testing](/testing/isolated-testing) for unit-level `runWithState()`,
[Fakeable Behaviors](/testing/fakeable-behaviors) for the faking API,
[Transitions & Paths](/testing/transitions-and-paths) for guard and path testing,
[Parallel Testing](/testing/parallel-testing) for parallel state testing,
[Persistence Testing](/testing/persistence-testing) for DB-level testing,
[Time-Based Testing](/testing/time-based-testing) for timer testing,
and [Scheduled Testing](/testing/scheduled-testing) for schedule testing.
:::
