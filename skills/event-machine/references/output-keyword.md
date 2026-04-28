# `output` Keyword Cheat-Sheet (curated)

The `'output'` key has **three different meanings** depending on context. Confusing them is the #1 source of `InvalidOutputDefinitionException` errors and the cause of "child→parent context merge doesn't work" debugging sessions.

This sheet is the quick lookup. Full reference: `docs/behaviors/outputs.md` and `docs/advanced/delegation-data-flow.md`.

## The three semantics at a glance

| Where | What it means | Parallel-region restricted? | Format options |
|---|---|---|---|
| **(1) Final state of THIS machine** | What `$machine->output()` returns | ✓ Yes — region states cannot define output; only the parent parallel state can | array filter / closure / OutputBehavior class / MachineOutput DTO |
| **(2) On a state with `'machine' =>`** (delegation) | Filters/transforms the **child's** final context exposed to `@done` | ✗ No — operates on child, not on this state | array filter / closure / OutputBehavior class / MachineOutput DTO |
| **(3) On an endpoint config** | Shapes HTTP response (any state, not just final) | ✗ No | array filter / closure / OutputBehavior class |

All three share the same **format syntax**, but they answer different questions:

- (1) "What does my machine return?"
- (2) "What does the child machine I'm delegating to return to me?"
- (3) "What does this HTTP endpoint return to the caller?"

## Decision tree

```
Where is this 'output' going?

├── On a state with type: 'final' in MY machine
│   → Meaning (1). Defines $machine->output().
│   → If state is inside a parallel region: only the parent parallel state can define output.
│   → "What do consumers of this machine see when it finishes?"
│
├── On a state with 'machine' => Child::class
│   → Meaning (2). Defines what the CHILD exposes to me.
│   → No parallel restriction (it's about the child, not this state).
│   → Lives on parent state, but operates on child context.
│   → @done action picks it up via typed MachineOutput injection or ChildMachineDoneEvent::output()
│   → "What slice of the child's context do I want?"
│
└── On an endpoint config
    → Meaning (3). Shapes HTTP response.
    → Any state, not just final.
    → "What JSON does this endpoint return?"
```

## Common confusion #1: parallel region + child machine invocation

**Scenario:** Parent has a parallel state with two regions; one region has a state that delegates to a child machine, and you want the child's output to flow into the parent context.

**Wrong:**
```php
'pricing_region' => [
    'initial' => 'calculating',
    'states'  => [
        'calculating' => [
            'machine' => PriceCalculatorMachine::class,
            'output'  => ['baseInterestRate', 'installmentOptions'],   // ❌
            '@done'   => 'calculated',
        ],
        'calculated' => ['type' => 'final'],
    ],
],
```
Throws `InvalidOutputDefinitionException::parallelRegionState` — the parser interprets `'output'` as meaning (1) on the `calculating` state, which is inside a parallel region.

**Right:** Declare the output on the **child machine's** final state, then pick it up in the parent's `@done` action.

```php
// Child machine
'completed' => [
    'type'   => 'final',
    'output' => ['baseInterestRate', 'installmentOptions'],   // ✓ child's own output (meaning 1, child's perspective)
],

// Parent state inside parallel region
'calculating' => [
    'machine' => PriceCalculatorMachine::class,
    '@done'   => [
        'target'  => 'calculated',
        'actions' => 'wirePricingContextAction',   // closure picks up child's output
    ],
],

// Parent behavior registry
'behavior' => [
    'actions' => [
        'wirePricingContextAction' => fn(OrderContext $ctx, ChildMachineDoneEvent $event) => [
            'baseRate'     => $event->output('baseInterestRate'),
            'installments' => $event->output('installmentOptions'),
        ],
    ],
],
```

The constraint is about the parent state declaring its OWN output, not about whether child machines can be invoked from parallel regions (they can).

## Common confusion #2: `'output'` as array filter and Eloquent models

```php
'completed' => [
    'type'   => 'final',
    'output' => ['user', 'order'],   // user is User model, order is Order model
],
```

The array filter passes context through `ContextManager::toResponseArray()` → which runs Spatie LaravelData's `ModelTransformer` → which serialises Eloquent models to their primary keys. Consumers see `['user' => 42, 'order' => 'ORD-1']`, not the full models.

**If you need the full model**, use a closure or OutputBehavior:

```php
'output' => fn(OrderContext $ctx) => [
    'user'  => $ctx->user,    // closure preserves the type
    'order' => $ctx->order,
],
```

Or override `toResponseArray()` on your ContextManager subclass.

## Common confusion #3: array filter vs closure vs class

```php
// Array filter — simple key picking from context
'output' => ['orderId', 'totalAmount']

// Closure — custom shape, full control
'output' => fn(OrderContext $ctx) => [
    'id'    => $ctx->orderId,
    'total' => $ctx->totalAmount + $ctx->tax,
]

// OutputBehavior class — DI, computation, reusable across states/endpoints
'output' => OrderSummaryOutput::class

// MachineOutput DTO — typed contract for inter-machine communication
'output' => OrderOutput::class   // class extends MachineOutput with named props
```

Rule of thumb:
- **Just picking fields?** Array.
- **Computing or formatting?** Closure (single-use) or OutputBehavior (reusable / DI'd).
- **Defining a typed inter-machine contract?** MachineOutput DTO. Combine with `'failure' => SomeFailure::class` for `@fail` typed injection.

## Type-dispatch order (for meanings 1 and 2)

When the engine resolves an `'output'` value, it checks in this order:

1. Inline behavior key — looked up in `behavior.outputs`
2. `MachineOutput` subclass — auto-resolves named properties from context
3. `OutputBehavior` subclass — invokes `__invoke()` with parameter injection
4. Array of strings — context key filter via `toResponseArray()`
5. Closure — invokes with parameter injection

This is why `'output' => SomeClass::class` works without you specifying which type it is — the engine introspects.

## Output placement rules — where is `'output'` valid?

Valid placements:
- ✅ Final state (`type: 'final'`) — most common
- ✅ Any state with `'machine' =>` delegation — filters child's output
- ✅ Endpoint config — shapes HTTP response

Invalid (throws `InvalidOutputDefinitionException`):
- ❌ Transient states (states with `@always` transitions) — would never be observable
- ❌ Individual region states inside a parallel state — only the parent parallel state can define output

## Quick checklist before adding `'output'`

1. Is this a final state, a delegation state, or an endpoint? (Determines semantic.)
2. Inside a parallel region? Then only meaning (2) — and never on the region states themselves.
3. Need the full Eloquent model in the output? Don't use array filter — use closure / OutputBehavior.
4. For child→parent context merge, declare on the child's final state — not on the parent's delegation state.
5. Keep the format choice proportional to the work: array if just picking, closure if 4-line shape, class if reusable + DI'd.

## Related

- `docs/behaviors/outputs.md` — full reference for OutputBehavior
- `docs/advanced/delegation-data-flow.md` — child→parent data flow including `output` and `@done`
- `docs/advanced/typed-contracts.md` — MachineInput / MachineOutput / MachineFailure
- `references/anti-patterns.md` — common output-related mistakes
- SKILL.md §7 — sync vs async + output decision tree
