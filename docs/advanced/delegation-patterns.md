# Delegation Patterns

This page covers common machine delegation patterns: orchestrator, saga/compensation, and when to use cross-machine messaging vs the orchestrator pattern.

## Orchestrator Pattern

The orchestrator machine's `definition()` **IS** the system definition. Reading it tells you which machines exist, how they relate, and what data flows between them.

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class OrderWorkflowMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'order_workflow',
                'initial' => 'validating',
                'context' => [
                    'orderId'       => null,
                    'totalAmount'   => 0,
                    'paymentData' => null,
                ],
                'states' => [
                    'validating' => [
                        'machine' => ValidationMachine::class,
                        'with'    => ['orderId'],
                        '@done'   => 'processing_payment',
                        '@fail'   => 'validation_failed',
                    ],
                    'processing_payment' => [
                        'machine' => PaymentMachine::class,
                        'with'    => ['orderId', 'totalAmount'],
                        'queue'   => 'payments',
                        '@done'   => [
                            'target'  => 'shipping',
                            'actions' => 'storePaymentResultAction',
                        ],
                        '@fail' => 'payment_failed',
                    ],
                    'shipping' => [
                        'machine' => ShippingMachine::class,
                        'with'    => ['orderId'],
                        '@done'   => 'completed',
                        '@fail'   => 'shipping_failed',
                    ],
                    'completed'         => ['type' => 'final'],
                    'validation_failed' => ['type' => 'final'],
                    'payment_failed'    => ['type' => 'final'],
                    'shipping_failed'   => ['type' => 'final'],
                ],
            ],
        );
    }
}
```

```
OrderWorkflowMachine (orchestrator)
    ├── invokes ValidationMachine  → @done → processing_payment
    ├── invokes PaymentMachine     → @done → shipping
    └── invokes ShippingMachine    → @done → completed
```

### Typed Orchestration

When child machines define typed contracts, the orchestrator's definition becomes a fully typed I/O specification:

<!-- doctest-attr: ignore -->
```php
'validating' => [
    'machine' => ValidationMachine::class,
    'input'   => ValidationInput::class,
    '@done'   => [
        'target'  => 'processing_payment',
        'actions' => 'storeValidationOutputAction',
    ],
    '@fail' => 'validation_failed',
],
'processing_payment' => [
    'machine' => PaymentMachine::class,
    'input'   => PaymentInput::class,
    'failure' => PaymentFailure::class,
    '@done'   => 'shipping',
    '@fail'   => 'payment_failed',
],
```

Each child declares what it needs (`MachineInput`), what it produces (`MachineOutput` on final states), and how it fails (`MachineFailure`). The orchestrator reads like a typed pipeline specification.

### Conditional Orchestration

When a child machine has multiple outcomes, use `@done.{state}` for declarative routing instead of guards:

<!-- doctest-attr: ignore -->
```php
'credit_check' => [
    'machine' => CreditCheckMachine::class,
    'with'    => ['applicantId', 'loanAmount'],

    '@done.approved'       => 'disbursement',
    '@done.manual_review'  => 'underwriting',
    '@done.rejected'       => 'declined',

    '@fail' => 'system_error',
],
```

**Reads as:** "The credit check can result in approval, manual review, or rejection — each routes to a different next step."

Compare with the guard-based approach that achieves the same result:

<!-- doctest-attr: ignore -->
```php
// Before: imperative routing via guards
'@done' => [
    ['target' => 'disbursement', 'guards' => 'isApprovedGuard'],
    ['target' => 'underwriting', 'guards' => 'needsReviewGuard'],
    ['target' => 'declined'],
],
```

The `@done.{state}` approach makes the routing visible in the definition — you can read the orchestration flow without looking at guard implementations.

### Why No Separate System Class

The orchestrator machine already declares everything:

| Concern | Solved By |
|---------|-----------|
| Where are machines defined? | Orchestrator's `definition()` |
| How do they communicate? | `@done`/`@fail` transitions |
| Who coordinates the flow? | The orchestrator machine |
| How do siblings talk? | They don't — flow goes through the orchestrator |

## Saga / Compensation Pattern

When a step fails and you need to undo previous steps, use the saga pattern with compensation machines:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class BookingMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'booking',
                'initial' => 'reserving_flight',
                'context' => [
                    'bookingId'  => null,
                    'flightRef'  => null,
                    'hotelRef'   => null,
                ],
                'states' => [
                    'reserving_flight' => [
                        'machine' => FlightReservationMachine::class,
                        'with'    => ['bookingId'],
                        '@done'   => [
                            'target'  => 'reserving_hotel',
                            'actions' => 'storeFlightRefAction',
                        ],
                        '@fail' => 'failed',
                    ],
                    'reserving_hotel' => [
                        'machine' => HotelReservationMachine::class,
                        'with'    => ['bookingId'],
                        '@done'   => [
                            'target'  => 'confirmed',
                            'actions' => 'storeHotelRefAction',
                        ],
                        // Hotel fails → cancel flight
                        '@fail' => 'cancelling_flight',
                    ],
                    'cancelling_flight' => [
                        'machine' => FlightCancellationMachine::class,
                        'with'    => ['flightRef'],
                        '@done'   => 'failed',
                        '@fail'   => 'failed',
                    ],
                    'confirmed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
        );
    }
}
```

**Key insight:** The compensating machine (`FlightCancellationMachine`) is just another child machine — no special API needed.

## Parallel Orchestration

Combine parallel states with machine delegation to run multiple child machines concurrently:

<!-- doctest-attr: ignore -->
```php
'processing' => [
    'type'   => 'parallel',
    '@done'  => 'shipping',
    '@fail'  => 'compensating',
    'states' => [
        'payment' => [
            'initial' => 'charging',
            'states'  => [
                'charging' => [
                    'machine' => PaymentMachine::class,
                    'with'    => ['orderId', 'totalAmount'],
                    '@done'   => 'charged',
                ],
                'charged' => ['type' => 'final'],
            ],
        ],
        'inventory' => [
            'initial' => 'reserving',
            'states'  => [
                'reserving' => [
                    'machine' => InventoryMachine::class,
                    'with'    => ['orderId'],
                    '@done'   => 'reserved',
                ],
                'reserved' => ['type' => 'final'],
            ],
        ],
    ],
],
```

Both children run. The parallel state's `@done` fires when **all** regions reach final.

## Communication Patterns

| Pattern | Mechanism | Best For |
|---------|-----------|----------|
| **Orchestration** | `machine` key | All inter-machine workflows (primary pattern) |
| **Sync progress** | `sendToParent()` | Child → parent immediate updates |
| **Async progress** | `dispatchToParent()` | Child → parent via queue |
| **External interaction** | Endpoints (webhooks) | Third-party callbacks |
| **Loose coupling** | Laravel Events | Cross-model, fire-and-forget |
| **Sync escape hatch** | `sendTo()` | Direct cross-machine messaging |
| **Async escape hatch** | `dispatchTo()` | Queued cross-machine messaging |

### Design Rule: Orchestrator First

Sibling machines should **not** communicate directly. Let the orchestrator handle flow:

<!-- doctest-attr: ignore -->
```php
// WRONG: PaymentMachine directly triggers ShippingMachine
class NotifyShippingAction extends ActionBehavior {
    public function __invoke(ContextManager $context): void {
        $this->sendTo(
            machineClass: ShippingMachine::class,
            rootEventId: $context->get('shipping_machine_id'),
            event: ['type' => 'START_SHIPPING'],
        );
    }
}

// RIGHT: Orchestrator manages the flow (visible in definition)
'processing_payment' => [
    'machine' => PaymentMachine::class,
    '@done'   => 'shipping',          // orchestrator decides what's next
],
'shipping' => [
    'machine' => ShippingMachine::class,
],
```

**`sendTo()` / `dispatchTo()` are escape hatches**, not the primary communication pattern. Their main use case is `sendToParent()` / `dispatchToParent()` for progress reporting.

## Fire-and-Forget Pattern

Fire-and-forget means dispatching work without tracking the output. Use it for side effects where the parent doesn't care about the outcome.

### Fire-and-Forget with Typed Input

Fire-and-forget machines can still use typed input to validate the data being passed:

<!-- doctest-attr: ignore -->
```php
'dispatching_audit' => [
    'machine' => AuditMachine::class,
    'input'   => AuditInput::class,
    'queue'   => 'background',
    'target'  => 'suspended',
],
```

The `AuditInput` is validated before the child is dispatched. No `failure` key is needed since the parent does not track the child's outcome.

### Machine Delegation (stay in state)

Omit `@done` to make a machine delegation fire-and-forget. The parent stays in the state and handles its own events:

<!-- doctest-attr: ignore -->
```php
'suspended' => [
    'machine' => AuditMachine::class,
    'with'    => ['userId'],
    'queue'   => 'background',
    // No @done → fire-and-forget
    'on' => ['REACTIVATE' => 'active'],
],
```

### Machine Delegation (spawn and transition)

Use `@always` or `target` to spawn and immediately move to the next state:

<!-- doctest-attr: ignore -->
```php
'dispatching_audit' => [
    'machine' => AuditMachine::class,
    'with'    => ['userId'],
    'queue'   => 'background',
    'on'      => ['@always' => 'suspended'],
],
```

### Job Actor

For single-step async operations:

<!-- doctest-attr: ignore -->
```php
'logging' => [
    'job'    => AuditLogJob::class,
    'with'   => ['action', 'userId'],
    'target' => 'next_state',
],
```

### dispatchTo() from an Action

For sending an event to an existing machine without waiting:

<!-- doctest-attr: no_run -->
```php
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class SendAlertAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $this->dispatchTo(
            machineClass: AlertMachine::class,
            rootEventId: $context->get('alert_machine_id'),
            event: ['type' => 'SEND_ALERT'],
        );
    }
}
```

### Choosing the Right Mechanism

| Mechanism | Tracks Output | Parent Waits | Use Case |
|-----------|--------------|-------------|----------|
| `machine + queue` (no `@done`) | No | No | Stateful child (multiple states, webhooks) |
| `job` + `target` | No | No | Single-step async (logging, notification) |
| `dispatchTo()` | No | No | Event to existing machine |
| `machine` + `@done` | Yes | Yes | Complex stateful delegation |
| `job` + `@done` | Yes | Yes | Managed async job |

## Interactive Delegation Pattern

The interactive delegation pattern is for multi-step workflows where the **child machine needs user input** before it can proceed. Unlike the webhook pattern (third-party callbacks) or orchestrator pattern (autonomous child), the interactive pattern **forwards HTTP requests from the parent's endpoint directly to the running child**.

**When to use:** Approval flows, payment flows, document submission, KYC verification — any workflow where a child machine is waiting for a human to provide data or confirm an action.

### Example: Loan Application

A loan application machine delegates to an identity verification child. The child needs the user to upload documents and confirm their identity — those events arrive via the parent's endpoints and are forwarded to the child.

```php no_run
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class LoanApplicationMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'loan_application',
                'initial' => 'collecting_info',
                'context' => [
                    'applicantId'       => null,
                    'loanAmount'        => 0,
                    'verification_result' => null,
                ],
                'states' => [
                    'collecting_info' => [
                        'on' => ['SUBMIT_APPLICATION' => 'identity_verification'],
                    ],
                    'identity_verification' => [
                        'machine' => IdentityVerificationMachine::class,
                        'with'    => ['applicantId'],
                        'queue'   => 'verification',
                        'forward' => [
                            'UPLOAD_DOCUMENT',
                            'CONFIRM_IDENTITY',
                        ],
                        '@done' => [
                            'target'  => 'underwriting',
                            'actions' => 'storeVerificationOutputAction',
                        ],
                        '@fail' => 'verification_failed',
                        'on'    => ['CANCEL' => 'cancelled'],
                    ],
                    'underwriting'        => ['type' => 'final'],
                    'verification_failed' => ['type' => 'final'],
                    'cancelled'           => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'SUBMIT_APPLICATION' => SubmitApplicationEvent::class,
                    'CANCEL'             => CancelEvent::class,
                ],
            ],
        );
    }
}
```

The `forward` key tells EventMachine:
1. Auto-discover the child's `UPLOAD_DOCUMENT` and `CONFIRM_IDENTITY` event classes
2. Register HTTP endpoints on the parent's route prefix
3. Validate incoming requests using the **child's** `EventBehavior` class
4. Route the event through the parent to the running child
5. Return a response with both parent and child state

No duplication needed — the child's event definitions are the single source of truth.

### Choosing the Right Pattern

| Need | Pattern | Example |
|------|---------|---------|
| Child runs autonomously | Orchestrator | Validation, batch processing |
| Child waits for external callback | Webhook | Stripe, bank callbacks |
| Child waits for user input | Interactive (forward) | Document upload, approval |
| Child runs independently | Fire-and-forget | Background verification |

### Orphan Strategy

When a parent leaves the delegating state (via the `on` key or `@timeout`) while a child is still running, you have an orphaned child. Choose a strategy based on your domain:

| Strategy | Implementation | When |
|----------|---------------|------|
| Guard | `'guards' => 'noActiveChildGuard'` | Child must complete before parent can leave |
| Exit action | `'exit' => 'cancelChildAction'` | Child is cancellable (clean shutdown) |
| Accept orphan | No special handling | Child is harmless (logging, audit) |

The guard approach prevents the parent from leaving:

```php ignore
'identity_verification' => [
    'machine' => IdentityVerificationMachine::class,
    'queue'   => 'verification',
    'forward' => ['UPLOAD_DOCUMENT'],
    '@done'   => 'underwriting',
    'on'      => [
        'CANCEL' => [
            'target' => 'cancelled',
            'guards' => 'noActiveChildGuard',
        ],
    ],
],
```

The exit action approach cancels the child:

```php ignore
'identity_verification' => [
    'machine' => IdentityVerificationMachine::class,
    'queue'   => 'verification',
    'forward' => ['UPLOAD_DOCUMENT'],
    '@done'   => 'underwriting',
    'exit'    => 'cancelChildAction',
    'on'      => ['CANCEL' => 'cancelled'],
],
```

### Forward + Parallel States

| Scenario | Supported | Notes |
|----------|-----------|-------|
| Parent in parallel state | Not yet | Parallel states do not support `machine` key on regions (documented gap) |
| Child in parallel state | Yes | Child's internal structure is independent |
| Nested delegation (grandchild) | Chains one level | Response shows the immediate child only, not grandchildren |

### Async Child Lifecycle

The full lifecycle of an interactive delegation with forward events:

```
Parent Machine
  │
  └── 'identity_verification' state
        │
        ├── machine: IdentityVerificationMachine (async, queue)
        │     │
        │     ├── awaiting_document
        │     │     │
        │     │     └── User POSTs UPLOAD_DOCUMENT ──→ Parent endpoint
        │     │                                         │
        │     │         Parent.tryForwardEventToChild() ─┘
        │     │         │
        │     ├── awaiting_confirmation ◄───────────────┘
        │     │     │
        │     │     └── User POSTs CONFIRM_IDENTITY ──→ Parent endpoint
        │     │                                          │
        │     │         Parent.tryForwardEventToChild() ──┘
        │     │         │
        │     ├── verified (final) ◄─────────────────────┘
        │     │
        │     └── (parent receives @done with verification result)
        │
        └── @done → underwriting
```

Each forwarded request:
1. Arrives at the **parent's** registered endpoint
2. Is validated using the **child's** `EventBehavior` class
3. Is sent to the parent, which detects the forward config
4. The parent calls `tryForwardEventToChild()` on the running child
5. The child transitions to its next state
6. The response includes both parent state and child state

## Testing Orchestration Patterns

<!-- doctest-attr: ignore -->
```php
// Fake multiple children for sequential orchestration
ValidationMachine::fake(output: ['is_valid' => true]);
PaymentMachine::fake(output: ['paymentId' => 'pay_1'], finalState: 'approved');

OrderWorkflowMachine::test()
    ->send('START')
    ->assertState('completed');

ValidationMachine::assertInvoked();
PaymentMachine::assertInvoked();
Machine::resetMachineFakes();
```

::: tip Full Testing Guide
See [Delegation Testing](/testing/delegation-testing) for more examples.
:::
