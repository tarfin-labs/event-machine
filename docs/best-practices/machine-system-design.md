# Machine System Design

A multi-machine system is not a collection of independent machines -- it is an organized structure where machines communicate through well-defined interfaces. The parent orchestrates, children report status through their states, and the hierarchy keeps the system comprehensible.

[Machine Decomposition](./machine-decomposition) covers _when_ to split a machine. This page covers how to _organize_ the resulting system: communication rules, hierarchy, and timer placement.

::: tip Built-In Handshaking
EventMachine's delegation pattern implements the handshaking rule automatically: the parent's delegation state (e.g., `processing_payment`) acts as the "busy" state, and `@done`/`@fail` acts as the "done" acknowledgment. You don't need to design this manually -- the framework guarantees it.
:::

## The Command--State Interface

Information flows one way in a well-designed machine system: **commands down, states up.** The parent sends commands to the child (via the `machine` key or `sendTo`). The parent reads the child's outcome (via `@done`, `@done.{state}`, or `sendToParent`). The child never reads the parent's state.

### Anti-Pattern: Leaking Parent State to Child

When a parent passes its own status flags to a child via `with`, the child becomes coupled to the parent's lifecycle:

```php ignore
// Anti-pattern: parent passes its own state to child
'awaiting_payment' => [
    'machine' => PaymentMachine::class,
    'with'    => ['order_id', 'order_total', 'order_status'],  // ← parent state leaked
    '@done'   => 'shipping',
    '@fail'   => 'failed',
],
```

```php no_run
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\ContextManager;

// Anti-pattern: child reads parent state to decide behavior
class CapturePaymentAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // Child uses parent's order_status — tight coupling
        if ($context->get('order_status') === 'cancelled') {
            return;
        }

        // ... capture logic ...
    }
}
```

The child should not care whether the order is cancelled. That is the parent's concern.

### Fix: Parent Handles Its Own Concerns

Pass only payment-relevant data via `with`. Handle cancellation in the parent with an `on` transition:

```php ignore
// Parent handles cancellation — child only knows about payment
'awaiting_payment' => [
    'machine' => PaymentMachine::class,
    'with'    => ['order_id', 'order_total'],  // only payment-relevant data
    'on'      => ['ORDER_CANCELLED' => 'cancelled'],
    '@done'   => 'shipped',
    '@fail'   => 'failed',
],
```

If the parent receives `ORDER_CANCELLED` while the child is running, the parent transitions to `cancelled` directly. The child is cleaned up automatically. No coupling needed.

**Takeaway:** Pass only IDs and values the child needs via `with` -- never parent state or status flags. Commands flow down (`machine`, `forward`, `sendTo`), states flow up (`@done`, `@done.{state}`, `sendToParent`).

## Design Your Child States for the Parent

A child machine's final states should tell the parent everything it needs to know. Use `@done.{finalState}` to route the parent based on which final state the child reached. Use `output` on final states to control which context keys the parent sees.

### Anti-Pattern: Single Final State Hides Outcomes

When a child has only one final state, the parent cannot distinguish between different outcomes:

```php ignore
// Child: CreditCheckMachine — parent can't distinguish outcomes
'states' => [
    'checking'  => [...],
    'completed' => ['type' => 'final'],  // approved? rejected? bureau down?
],
```

```php ignore
// Parent: no way to route differently
'processing_credit' => [
    'machine' => CreditCheckMachine::class,
    'with'    => ['applicant_id'],
    '@done'   => 'credit_decision',  // always same target, must read context
],
```

The parent receives `@done` for every outcome and must inspect the child's context to decide what to do next. This is fragile -- if the child adds a new outcome, the parent's action must be updated.

### Fix: Explicit Final States + `@done.{state}` Routing

Give each distinct outcome its own final state. Use `output` to expose only the relevant context keys. The parent uses `@done.{finalState}` to route directly:

```php ignore
// Child: CreditCheckMachine — each outcome is a distinct final state
'states' => [
    'querying_bureau' => [
        'entry' => 'queryCreditBureauAction',
        'on'    => [
            'SCORE_RECEIVED'      => 'evaluating',
            'BUREAU_QUERY_FAILED' => 'bureau_unavailable',
        ],
        'after' => [
            Timer::seconds(30) => 'bureau_unavailable',
        ],
    ],
    'evaluating' => [
        'on' => [
            '@always' => [
                ['target' => 'approved', 'guards' => 'isCreditScoreSufficientGuard'],
                ['target' => 'rejected'],
            ],
        ],
    ],
    'approved' => [
        'type'   => 'final',
        'output' => ['credit_score', 'credit_limit'],
    ],
    'rejected' => [
        'type'   => 'final',
        'output' => ['credit_score', 'rejection_reason'],
    ],
    'bureau_unavailable' => [
        'type'   => 'final',
        'output' => ['error_code'],
    ],
],
```

```php ignore
// Parent: routes differently based on child's final state
'processing_credit' => [
    'machine'                   => CreditCheckMachine::class,
    'with'                      => ['applicant_id'],
    '@done.approved'            => 'approved',
    '@done.rejected'            => 'rejected',
    '@done.bureau_unavailable'  => 'retrying_credit_check',
    '@fail'                     => 'failed',  // exception/crash only
],
```

**Resolution order:** `@done.{state}` (specific match) → `@done` (catch-all fallback) → no transition.

**Takeaway:** Design child final states as a "report card" for the parent. Use `@done.{finalState}` for routing and `output` on final states to filter context. Reserve `@fail` for infrastructure failures (exceptions, job crashes) -- not business logic outcomes.

## Timer Placement

Business timeout timers belong on the machines that talk to the outside world (leaf machines). If a parent machine needs `@timeout` to guard against a child hanging, the child's design is incomplete.

### Anti-Pattern: Timer on Parent to Guard Against Child

```php ignore
// Anti-pattern: parent uses @timeout because child might hang
'verifying_documents' => [
    'machine' => DocumentVerificationMachine::class,
    'queue'   => 'default',
    'timeout' => 300,
    '@done'   => 'documents_verified',
    '@fail'   => 'verification_failed',
    '@timeout' => 'verification_timed_out',
],
```

Why would the child hang? If it calls an external API, the _child_ should own that timeout. The parent should not need to know about the child's integration details.

### Fix: Timer on the Leaf Machine

The child owns its business timeout with `after`. It handles retries internally and always reaches a final state:

```php ignore
// Child: DocumentVerificationMachine — owns its business timeout
'states' => [
    'calling_api' => [
        'entry' => 'callVerificationApiAction',
        'on'    => [
            'VERIFICATION_COMPLETED' => 'verified',
            'VERIFICATION_REJECTED'  => 'rejected',
        ],
        'after' => [
            Timer::minutes(2) => 'api_timed_out',
        ],
    ],
    'api_timed_out' => [
        'entry' => 'handleApiTimeoutAction',
        'on'    => [
            '@always' => [
                ['target' => 'calling_api', 'guards' => 'canRetryGuard'],
                ['target' => 'failed'],
            ],
        ],
    ],
    'verified' => ['type' => 'final', 'output' => ['verification_id']],
    'rejected' => ['type' => 'final', 'output' => ['rejection_reason']],
    'failed'   => ['type' => 'final', 'output' => ['failure_reason']],
],
```

```php ignore
// Parent: no timer needed — child guarantees completion
'verifying_documents' => [
    'machine'            => DocumentVerificationMachine::class,
    'queue'              => 'default',
    'with'               => ['document_ids'],
    '@done.verified'     => 'documents_verified',
    '@done.rejected'     => 'documents_rejected',
    '@done.failed'       => 'verification_failed',
    '@fail'              => 'failed',
],
```

::: tip When Parent @timeout IS Appropriate
Use `@timeout` for **infrastructure protection** -- when the queue infrastructure itself might fail (worker crash, Redis down, job lost). This guards against the child _never completing at all_, not against slow business logic. If you need a parent timer for business logic, the child's design is incomplete.
:::

## Keep the Hierarchy Clean

Each machine should communicate only with its direct parent and children. "Wild links" -- messages that skip hierarchy levels -- make the system unpredictable.

### Anti-Pattern: Grandparent Bypasses Parent

```php no_run
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\ContextManager;

// Anti-pattern: OrderWorkflowMachine action sends directly to FraudCheckMachine
// (should go through PaymentMachine)
class HandlePaymentReceivedAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        // Order machine reaching past PaymentMachine to FraudCheckMachine
        $this->sendTo(
            machineClass: 'App\\Machines\\FraudCheckMachine',
            rootEventId: $context->get('fraud_check_id'),
            event: ['type' => 'FRAUD_DATA_SUBMITTED', 'payload' => ['amount' => $context->get('amount')]],
        );
    }
}
```

PaymentMachine does not know FraudCheckMachine received a command. It cannot account for this in its transitions. System behavior becomes unpredictable.

### Fix: Route Through the Hierarchy

PaymentMachine owns the fraud check as its child. The parent (OrderWorkflow) delegates to PaymentMachine, which delegates to FraudCheckMachine. Each machine talks only to its direct neighbors:

```php ignore
// PaymentMachine owns the fraud check as its child
'processing' => [
    'machine'                    => FraudCheckMachine::class,
    'with'                       => ['transaction_id', 'amount'],
    '@done.clean'                => 'capturing',
    '@done.flagged'              => 'awaiting_manual_review',
    '@fail'                      => 'failed',
],
```

**When `sendTo()` / `dispatchTo()` IS appropriate:**

- **Sibling coordination:** Two machines at the same level need to synchronize (e.g., InventoryMachine notifies ShippingMachine that stock is reserved)
- **Notifications:** Fire-and-forget messages to logging, analytics, or notification machines
- **Cross-domain events:** Explicitly modeled communication between separate domain boundaries (e.g., order domain → accounting domain)

**Takeaway:** Prefer hierarchical delegation (`machine` key) over direct messaging (`sendTo`). Use `sendTo` for sibling coordination and fire-and-forget notifications, not for bypassing the hierarchy.

## Composition Patterns

Three patterns for organizing multi-machine systems, from most to least common:

### Pattern A: Hierarchical (Parent → Children)

The parent orchestrates a sequential or branching workflow. Children specialize in domain-specific logic.

```ignore
OrderWorkflow
  ├── ValidationMachine     (@done → awaiting_payment)
  ├── PaymentMachine        (@done.captured → shipping, @done.declined → payment_failed)
  │     └── FraudCheckMachine  (@done.clean → capturing)
  ├── ShippingMachine       (@done → completed)
  └── RefundMachine         (@done → refunded)
```

**When:** The parent decides _what_ to do next based on child outcomes. Most EventMachine systems use this pattern.

### Pattern B: Parallel Regions

Independent sub-flows running simultaneously within one machine. Regions cannot communicate with each other.

```ignore
OrderFulfillment (parallel state)
  ├── region: payment   (processing → captured)
  └── region: inventory (reserving → reserved)
  @done → shipping (when both regions complete)
```

**When:** Independent sub-flows that must all complete before proceeding. See [Parallel Patterns](./parallel-patterns).

### Pattern C: Coordinator + Peer Machines

Independent machines notify a coordinator via `dispatchTo`. No parent-child relationship -- machines are peers across domain boundaries.

```ignore
OrderMachine ──dispatchTo──→ AccountingMachine
ShippingMachine ──dispatchTo──→ AccountingMachine
RefundMachine ──dispatchTo──→ AccountingMachine
```

**When:** Cross-domain communication where machines belong to different bounded contexts. Use `dispatchTo` (async) to avoid coupling the sender to the coordinator's response time.
