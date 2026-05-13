# Sync Child Machines

Sync child machines are the **default** mode of [machine delegation](/advanced/machine-delegation): a state declares `'machine' => Child::class` (without `'queue' =>`), and the child runs in-process during the parent's macrostep. The parent blocks until the child reaches a final state, then `@done` fires inline.

This page covers patterns and pitfalls specific to sync delegation: when to use it, how to bootstrap a child that needs to do work immediately, and how to handle output, especially inside parallel regions.

## When to Use Sync Delegation

Sync delegation fits when:

- The work is **deterministic computation** — pricing, validation, transformation, format conversion.
- The work runs in **under one second** — fits inside an HTTP request without blocking unduly.
- There is **no external I/O** — no HTTP calls to third parties, no polling, no human input.
- The parent's **next decision depends on the result** — you need `@done` to fire before continuing.

Async (`'queue' =>`) is the right choice for I/O, retry logic, or anything that may take seconds-to-minutes. Fire-and-forget (queue + no `@done`) is right when the parent doesn't need the child's result.

::: tip Decision matrix
See [Machine Decomposition: Sync vs Async vs Fire-and-Forget](./machine-decomposition#sync-vs-async-vs-fire-and-forget-decision) and the [sync-vs-async-delegation cheat-sheet](https://github.com/tarfin-labs/event-machine/blob/main/skills/event-machine/references/sync-vs-async-delegation.md).
:::

## Bootstrap Pattern: `idle` + `@always`

When a parent state delegates synchronously to a child (`'machine' => Child::class`), the engine calls `$child->start()`. **`start()` enters the initial state but does NOT fire any event.** If the child has no transitions out of its initial state, it sits there and the parent blocks indefinitely (or in a sync test run, the parent hangs).

For sync child machines that need to do work immediately on entry, the canonical pattern is **`idle` with `@always`**:

```php no_run
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class PriceCalculatorMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'price_calculator',
                'initial' => 'idle',
                'context' => [
                    'baseAmount'   => 0,
                    'taxRate'      => 0.0,
                    'totalAmount'  => 0,
                ],
                'states'  => [
                    'idle' => [
                        // Bootstrap on entry — start() enters idle but fires no event,
                        // so without @always the child sits here forever.
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

When the parent delegates:

```php ignore
'pricing' => [
    'machine' => PriceCalculatorMachine::class,
    'input'   => ['baseAmount', 'taxRate'],
    '@done'   => [
        'target'  => 'priced',
        'actions' => 'wirePricingContextAction',
    ],
],
```

the sequence is:

1. Parent enters `pricing` state → engine creates the child and calls `start()`.
2. Child enters `idle` (initial state) — no event fired.
3. `@always` immediately transitions child to `calculating`.
4. `entry` runs `CalculatePricesAction` (synchronous computation).
5. `@always` transitions child to `completed`.
6. `completed` is `type: final` → child stops.
7. Parent's `@done` fires; `wirePricingContextAction` reads child output.

Without `@always` on `idle`, step 2 would terminate the macrostep without ever reaching step 4.

::: warning Async children rarely need this
For async children, you typically send an explicit event to start the child (e.g., `->send(['type' => 'CALCULATE'])`), or the child polls / waits on its own. Async patterns are more flexible because the parent has already transitioned to the delegation state and is no longer blocking.
:::

## Output: Three Format Choices

The child's final state declares an `'output' =>` to control what the parent's `@done` action receives. Pick the format proportional to the work:

### Array filter — simplest, but watch for Eloquent

```php ignore
'completed' => [
    'type'   => 'final',
    'output' => ['baseInterestRate', 'installmentOptions'],
],
```

Picks two context keys, runs them through `ContextManager::toResponseArray()`. Cheap and explicit, but **Eloquent models in context get serialised to their primary keys** by `ModelTransformer`. If the parent needs the full model, use a closure or `OutputBehavior` instead.

### Closure — custom shape, single use

```php ignore
'completed' => [
    'type'   => 'final',
    'output' => fn(PriceContext $ctx) => [
        'baseRate'     => $ctx->baseInterestRate,
        'installments' => $ctx->installmentOptions->toArray(),
    ],
],
```

Best when you need a custom shape but the output isn't reused elsewhere. Preserves types — Eloquent models stay as models unless you call `->toArray()`.

### `OutputBehavior` class — reusable, computed, DI'd

```php no_run
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\ContextManager;

class PriceOutput extends OutputBehavior
{
    public function __construct(
        private readonly RoundingPolicy $rounder,
    ) {}

    public function __invoke(ContextManager $context): array
    {
        return [
            'baseRate'     => $this->rounder->round($context->get('baseInterestRate')),
            'installments' => $context->get('installmentOptions'),
        ];
    }
}
```

Best when computation is non-trivial, the output is reused across multiple final states, or you need constructor DI for services.

::: tip Don't reach for a class for trivial wire-up
A 4-line wire-up does not need an `OutputBehavior` subclass + a `MachineOutput` DTO + a custom action class. Use a closure on the child's final state plus an inline action key in `behavior.actions` on the parent. Save classes for behaviors that earn the boilerplate. See [Action Design](./action-design) and the [closure-vs-class guidance in SKILL.md §4](https://github.com/tarfin-labs/event-machine/blob/main/skills/event-machine/SKILL.md).
:::

## Sync Delegation Inside a Parallel Region

A common confusion: you have a parallel state, one region delegates to a sync child, and you want the child's output to flow into the parent's context. You add `'output' =>` to the parent's delegation state and get:

```
InvalidOutputDefinitionException: Region states inside a parallel state cannot define output.
```

This is **not** a restriction on sync delegation inside parallel regions — sync delegation works fine there. The error is about the [`'output'` keyword's three semantics](/behaviors/outputs#output-vs-output-array-filter-vs-toresponsearray): adding `'output' =>` to a state inside a parallel region is interpreted as defining that state's own output (meaning 1), which is restricted.

**Wrong:**
<!-- doctest-attr: ignore -->
```php
// Parent has type: parallel; pricing_region is one of the regions.

'pricing_region' => [
    'initial' => 'calculating',
    'states'  => [
        'calculating' => [
            'machine' => PriceCalculatorMachine::class,
            'output'  => ['baseRate', 'installments'],  // ❌ parallel-region restricted
            '@done'   => 'calculated',
        ],
        'calculated' => ['type' => 'final'],
    ],
],
```

**Right:** Declare the output on the **child's** final state. The parent's delegation state only carries `'machine' =>` and `@done`; the child's own `'output' =>` controls what flows back.

```php ignore
// Child machine
'completed' => [
    'type'   => 'final',
    'output' => ['baseRate', 'installments'],   // ✓ child's own output
],

// Parent state inside parallel region
'calculating' => [
    'machine' => PriceCalculatorMachine::class,
    '@done'   => [
        'target'  => 'calculated',
        'actions' => 'wirePricingContextAction',
    ],
],

// Parent behavior registry
'behavior' => [
    'actions' => [
        'wirePricingContextAction' => fn(OrderContext $ctx, ChildMachineDoneEvent $event) => [
            'baseRate'     => $event->output('baseRate'),
            'installments' => $event->output('installments'),
        ],
    ],
],
```

The constraint is purely about **whose output** you're declaring. Child machines invoked from inside parallel regions are perfectly legal.

::: tip Output keyword cheat-sheet
For the full decision tree across the three semantics — parent-state output, child-machine output, endpoint output — see the [output-keyword cheat-sheet](https://github.com/tarfin-labs/event-machine/blob/main/skills/event-machine/references/output-keyword.md).
:::

## Reuse vs Inlining: When Sync Delegation Pays Off

A sync child machine adds plumbing (entry/exit actions, event sourcing, child machine row, `ChildMachineCompletionJob` if it ever transitions to async later). It's worth this overhead when:

- The same logic is used in **3+ call sites** — the abstraction earns its keep.
- The sub-flow has its **own visible states** that are useful in debugging or tests (`pricing.calculating`, `pricing.completed`).
- You're already paying for **typed contracts** (`MachineInput`, `MachineOutput`) and want strict enforcement at the boundary.

If only one parent state ever calls the logic, and the logic is "compute three values from context and write them back," it's usually cheaper to model it as a [calculator](/behaviors/calculators) or an action that does the math directly.

::: tip When NOT to use a machine
See [Machine Decomposition: When NOT to Use a Machine](./machine-decomposition#when-not-to-use-a-machine) for the inline / calculator / action / service alternatives.
:::

## Common Pitfalls

| Symptom | Likely cause | Fix |
|---|---|---|
| Parent hangs in sync test; child never reaches final | Child's `idle` has no `@always` | Add `'on' => ['@always' => 'next_state']` on `idle` |
| `InvalidOutputDefinitionException` on parent state inside parallel region | Confusing meaning (1) and meaning (2) of `'output'` | Move `'output'` to the child's final state |
| `@timeout` ignored | `@timeout` is async-only; sync delegation blocks until done | Switch to async, or remove `@timeout` |
| Eloquent models become IDs in `@done` action | Array filter goes through `ModelTransformer` | Use closure or `OutputBehavior` to preserve model types |
| Inline closure works in dev but not in production | Inline behavior key registered in parent — fails in cherry-picked tests too | Use the [region cherry-picking recipe](/testing/recipes#region-cherry-picking) — pass parent's `behavior['actions']` through to the isolated `TestMachine` |
| Child uses `ShouldQueue` but `'queue' =>` is omitted on parent | Mixed mode — confusing semantics | Pick one: either delegate sync (no `ShouldQueue`) or async (`'queue' =>`) |

## Testing Sync Children

Sync delegation runs the child inline. Two main testing strategies:

**1. Test the child in isolation** with `Machine::test()`:

```php no_run
PriceCalculatorMachine::test([
    'baseAmount' => 1000,
    'taxRate'    => 0.18,
])->assertFinished()->assertOutput([
    'baseRate'     => 0.85,
    'installments' => [/* ... */],
]);
```

**2. Test the parent with `Machine::fake()`** to short-circuit the child:

```php no_run
PriceCalculatorMachine::fake(
    output: ['baseRate' => 0.85, 'installments' => []],
);

OrderMachine::test()
    ->send('SUBMIT')
    ->assertState('priced')
    ->assertContext('baseRate', 0.85);
```

For the parent test, the sync child does not actually run — the fake injects the output directly into the parent's `@done`. This is faster and decouples parent tests from child internals.

::: tip Region cherry-picking
If you want to test a single region of a parallel state in isolation while reusing the parent's behavior registry, see the [region cherry-picking recipe](/testing/recipes#region-cherry-picking).
:::

## Related

- [Machine Delegation](/advanced/machine-delegation) — full reference for `'machine' =>`
- [Async Delegation](/advanced/async-delegation) — `'queue' =>`, retries, `@timeout`
- [Delegation Data Flow](/advanced/delegation-data-flow) — `input`, `output`, `@done` event
- [Outputs](/behaviors/outputs) — output behaviors, three placement contexts
- [Machine Decomposition](./machine-decomposition) — when to split into a child machine
- [Action Design](./action-design) — idempotent side effects, never throw to block
- [`output-keyword` cheat-sheet](https://github.com/tarfin-labs/event-machine/blob/main/skills/event-machine/references/output-keyword.md) — three semantics decision tree
- [`sync-vs-async-delegation` cheat-sheet](https://github.com/tarfin-labs/event-machine/blob/main/skills/event-machine/references/sync-vs-async-delegation.md) — config matrix
