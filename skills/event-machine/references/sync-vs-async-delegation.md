# Sync vs Async Delegation Cheat-Sheet (curated)

When delegating to a child machine, EventMachine offers three modes: **sync** (default), **async** (queue-dispatched), and **fire-and-forget**. The default catches most agents off guard — existing examples in the codebase typically include `'queue' =>`, leading to the assumption that delegation is always async.

This sheet is the quick lookup. Full reference: `docs/advanced/machine-delegation.md` and `docs/advanced/async-delegation.md`.

## The default is sync

```php
'processing_payment' => [
    'machine' => PaymentMachine::class,   // ← sync. Parent blocks in-process.
    '@done'   => 'shipping',
],
```

No `queue` key → child runs in the same request, parent blocks until child reaches a final state, `@done` fires immediately. Add `'queue' => 'name'` to make it async.

## Config matrix

| Config | Mode | When | Failure handling | Test mode |
|---|---|---|---|---|
| `'machine' => X::class` | **sync** | sync arithmetic, validation, transformation, fast lookups (<1s) | `@fail` fires inline; exceptions surface to caller | Runs immediately in-process |
| `'machine' => X::class, 'queue' => 'name'` | **async** | external API, polling, retry, multi-step async (seconds-minutes) | `@fail` via `ChildMachineCompletionJob`; `@timeout` available | Use `Machine::fake()` + `Queue::fake()` or `simulateChildDone/Fail/Timeout()` |
| `'machine' => X::class, 'queue' => 'name'` + no `@done` | **fire-and-forget** | parent doesn't care about result; child runs independently | Child handles its own failures | Same as async; parent transition immediate |
| `'machine' => X::class` + child uses `ShouldQueue` | **mixed (anti-pattern)** | ambiguous — sync container, async child | Unpredictable | Avoid |

## Sync delegation

```php
'validating' => [
    'machine' => ValidationMachine::class,
    'input'   => ['orderId'],
    '@done'   => 'awaiting_payment',
    '@fail'   => 'validation_failed',
],
```

- Parent calls `start()` on child, blocks until child reaches `type: 'final'`.
- `@done` / `@fail` action runs immediately in the parent's macrostep.
- Use for: deterministic computation, in-process validation, sub-second lookups, sync arithmetic.
- Children with their own `idle` initial state need `@always` to bootstrap — `start()` enters the initial state but does NOT fire any event. See `docs/best-practices/sync-child-machines.md`.

## Async delegation

```php
'querying_credit_score' => [
    'machine'  => CreditScoreMachine::class,
    'input'    => CreditScoreInput::class,
    'queue'    => 'credit',
    '@done'    => ['target' => 'reviewing', 'actions' => StoreScoreAction::class],
    '@fail'    => 'credit_check_failed',
    '@timeout' => ['after' => 300, 'target' => 'credit_check_timed_out'],
],
```

- Parent transitions to delegation state; `ChildMachineJob` dispatched to the named queue.
- Child runs on a worker; on completion, `ChildMachineCompletionJob` routes back to parent's `@done` / `@fail`.
- `@timeout` is async-only — `'after' => N` seconds before parent times out waiting.
- Use for: external APIs, anything that retries, long-running multi-step flows.

## Fire-and-forget

```php
'archived' => [
    'machine' => ArchiveMachine::class,
    'queue'   => 'archive',
    'target'  => 'completed',   // immediate parent transition; no @done
],
```

- Parent transitions immediately (via `target` or to the next state via `@always`).
- Child runs independently; its outcome doesn't affect parent.
- Use for: notifications, telemetry, side-flows where the result is irrelevant to the parent's progression.

## How to choose

| Question | Answer |
|---|---|
| Does parent need the child's result before continuing? | **Yes → sync or async (with `@done`)**. **No → fire-and-forget.** |
| Will child take longer than ~1 second / make HTTP calls / poll? | **Yes → async.** |
| Does child wait on external input (webhooks, human approval)? | **Async** (so HTTP requests aren't held open). |
| Does parent run in an HTTP request that should return fast? | **Async** for anything non-trivial. |
| Pure deterministic computation, no I/O? | **Sync** (or even calculator/action — see `references/anti-patterns.md` § "Modeling sync arithmetic as a machine"). |

## Bootstrap pattern for sync child machines

A sync child enters its `initial` state but does not fire any event. If the child needs to start work immediately, the initial state must use `@always` to begin:

```php
class PriceCalculatorMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'price_calculator',
                'initial' => 'idle',
                'context' => [...],
                'states'  => [
                    'idle' => [
                        // sync child: bootstrap on entry via @always
                        'on' => ['@always' => 'calculating'],
                    ],
                    'calculating' => [
                        'entry' => CalculatePricesAction::class,
                        'on'    => ['@always' => 'completed'],
                    ],
                    'completed' => [
                        'type'   => 'final',
                        'output' => PriceOutput::class,
                    ],
                ],
            ],
        );
    }
}
```

For async children, this is rarely needed — the parent typically starts the child via an explicit event, or the child polls/waits on its own.

## @done / @fail / @timeout

| Trigger | Sync | Async |
|---|---|---|
| `@done` — child reached any final state | ✓ | ✓ |
| `@done.{stateName}` — child reached specific final state | ✓ | ✓ |
| `@fail` — child reached failure state or threw | ✓ | ✓ |
| `@timeout` — child didn't complete within `after: N` seconds | ✗ (sync blocks until done) | ✓ |

`@done` / `@fail` actions can type-hint `MachineOutput` / `MachineFailure` for typed injection from the child's typed contract. See `docs/advanced/typed-contracts.md`.

## Test-mode behavior

- **Sync delegation in tests**: child runs inline. Use `Machine::fake()` to stub if you don't want the real child to run.
- **Async delegation in tests**: `ChildMachineJob` is dispatched but not necessarily processed. Use `Queue::fake()` to inspect dispatch, or `simulateChildDone/Fail/Timeout()` to drive parent through delegation paths without running child.
- **Job actors (`'job' =>` instead of `'machine' =>`)**: skip dispatch entirely in test mode (sync queue cascade prevention). Use `simulateChildDone(JobClass::class)` to step.
- **Fakes auto-reset**: include the `InteractsWithMachines` trait on your TestCase.

## Common mistakes

| Mistake | Symptom | Fix |
|---|---|---|
| Assuming `'machine' => X::class` is async because all examples in the codebase use `'queue' =>` | `@timeout` doesn't fire; tests show child runs inline | Read this sheet — sync is the default. Add `'queue' => 'name'` if you wanted async. |
| Sync child has no `@always` on `idle` | Child enters `idle` and stops; parent waits forever | Add `@always` on `idle` (or whatever the initial state is) to bootstrap. |
| `@timeout` on sync delegation | Ignored at runtime — sync blocks until done, no timeout enforced | If timeout matters, switch to async; otherwise remove `@timeout`. |
| `'output' =>` on parent state inside parallel region | `InvalidOutputDefinitionException::parallelRegionState` | Declare `'output'` on the child's final state instead. See `references/output-keyword.md`. |
| Mixed mode: sync `'machine'` but child uses `ShouldQueue` job actors | Confusing semantics; tests inconsistent | Pick one mode per delegation state. |

## Related

- `docs/advanced/machine-delegation.md` — full reference (config keys, lifecycle, deferred invoke)
- `docs/advanced/async-delegation.md` — async-specific concerns (queue, timeout, @fail propagation)
- `docs/advanced/typed-contracts.md` — typed input/output/failure contracts
- `docs/best-practices/sync-child-machines.md` — sync-only patterns + bootstrap
- `docs/best-practices/machine-decomposition.md` — when to split, sync vs async vs fire-and-forget decision
- `references/delegation.md` — broader delegation cheat-sheet
- `references/output-keyword.md` — `'output'` semantic per context
- SKILL.md §7 — gotchas for delegation & parallel
