# Delegation Cheat-Sheet (curated)

## Decision tree — which delegation flavor?

```
Does parent need the child's result?
├─ NO  → Fire-and-forget: `queue: true` + NO @done/@fail
│         └─ Parent continues immediately; child runs independently.
│
└─ YES → Does the work run on a worker (async)?
         ├─ YES → Async delegation: `queue: 'queue-name'`
         │         + @done (required) + @fail (required) + @timeout (recommended)
         │         └─ Parent state = delegation state; transitions on child completion.
         │
         └─ NO  → Sync delegation: no `queue` key
                   + @done (required) + @fail (required)
                   └─ Parent blocks in-process until child hits final.
```

## Canonical config

```php
'processing_payment' => [
    'machine' => PaymentMachine::class,
    'input'   => PaymentInput::class,        // typed input contract (optional)
    'queue'   => 'payments',                 // omit for sync
    '@done'   => [
        'target'  => 'shipping',
        'actions' => CapturePaymentAction::class,   // can type-hint MachineOutput
    ],
    '@fail' => [
        'target'  => 'payment_failed',
        'actions' => HandleFailureAction::class,    // can type-hint MachineFailure
    ],
    '@timeout' => [
        'after'  => 300,                     // seconds
        'target' => 'payment_timed_out',
    ],
],
```

## Job delegation (Laravel Job, not machine)

```php
'validate_document' => [
    'job'   => ValidateDocumentJob::class,
    'queue' => 'validations',
    '@done' => 'validated',
    '@fail' => 'validation_failed',
],
```

`ValidateDocumentJob` = regular Laravel Job. Wraps inside `ChildJobJob`. `@done` fires when job completes successfully; `@fail` fires via `ChildJobJob::failed()` hook.

## @done.{finalState} routing

Child has multiple final states → parent routes per outcome:

```php
'@done.approved' => ['target' => 'shipping'],
'@done.rejected' => ['target' => 'refund'],
'@done'          => 'completed',            // fallback for any other final
```

## MachineInput / MachineOutput / MachineFailure

```php
// Input — validated before child boot
class PaymentInput extends MachineInput {
    public function __construct(
        public readonly string $orderId,
        #[Min(0)] public readonly int $amount,
    ) {}
}

// Output — injected into parent @done action by type-hint
class PaymentOutput extends MachineOutput {
    public function __construct(public readonly string $paymentId) {}
}

class CapturePaymentAction extends ActionBehavior {
    public function __invoke(OrderContext $ctx, PaymentOutput $out): void {
        $ctx->set('paymentId', $out->paymentId);
    }
}

// Failure — same, for @fail actions
class HandleFailureAction extends ActionBehavior {
    public function __invoke(OrderContext $ctx, MachineFailure $fail): void {
        $ctx->set('failureReason', $fail->reason);
    }
}
```

## Deep chains (Parent → Child → Grandchild)

- Each level resolves its @done / @fail independently
- `ChildMachineCompletionJob` propagates completion upward
- Grandparent receives `success` flag reflecting the middle machine's outcome, NOT the grandchild's
- No single atomic transaction spans the full chain — each hop is a separate queue job with its own lock

## Critical gotchas

| # | Gotcha | Fix |
|---|--------|-----|
| 1 | Async forward-endpoint HTTP responses don't contain child state | `waitFor()` child, then restore to verify (never read `data.child` from immediate response) |
| 2 | `dispatchToParent` is transient — silently dropped if parent already transitioned | Use `sendToParent` if you need guaranteed delivery; otherwise accept fire-and-forget semantics |
| 3 | Dispatched jobs for archived parents fail silently | `ChildMachineCompletionJob` now auto-restores archived parents; verify with `tests/LocalQA/ArchivedParentTest` |
| 4 | Child entry actions during initial `start()` CANNOT use `dispatchToParent` | Parent identity is set AFTER `start()` — use raised events or delay to a later state |
| 5 | Invoke deferred until after macrostep | If entry actions / raised events transition child away, delegation is skipped entirely (SCXML invoker-05) |
| 6 | `FailingTestJob` creates `failed_jobs` records in QA | Expected — assert `failed_jobs <= N` not `== 0` |
| 7 | `MachineOutput` must be serialized before queue dispatch | `ChildMachineCompletionJob` stores `toArray()` + class name; never the object |
| 8 | Lost child→parent propagation after pod SIGTERM | Fixed in 9.8.5 — `ChildMachineCompletionJob` retries detect orphaned `MachineChild.status='running'` + parent in final state, then re-dispatch. If debugging production stuck children, look for `ChildMachineCompletionJob: recovering lost propagation` (warning) or `parent already transitioned, idempotent skip` (info) log records. |
| 9 | Async (`'queue:'`) parent's child scenario reference silently runs full I/O | Fixed in 9.10.3 — `ChildMachineJob` now carries `scenarioClass` payload and worker re-activates scenario context before child boot. Earlier versions silently dropped the scenario at dispatch time. See `references/scenarios.md` for decision rule between inline outcome and child scenario class. |

## Testing delegation

```php
// Fake the child entirely
PaymentMachine::fake(
    output: new PaymentOutput(paymentId: 'pay_123'),
    finalState: 'settled',
);

OrderMachine::test()->send('PROCESS')->assertState('shipping');
PaymentMachine::assertInvokedWith(['orderId' => 'ORD-1']);

// Or drive through simulated outcomes without running child
OrderMachine::test()
    ->send('PROCESS')
    ->simulateChildDone(PaymentMachine::class, output: [...])
    ->assertState('shipping');

// ->simulateChildFail(PaymentMachine::class, reason: ...)
// ->simulateChildTimeout(PaymentMachine::class)
```

## See also

- `docs/advanced/machine-delegation.md` — canonical reference
- `docs/advanced/delegation-data-flow.md` — parent/child data flow semantics
- `docs/advanced/delegation-patterns.md` — common patterns
- `docs/advanced/async-delegation.md` — queue mechanics
- `docs/advanced/job-actors.md` — job (not machine) delegation
- `docs/testing/delegation-testing.md` — test patterns
