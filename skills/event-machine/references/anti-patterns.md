# Anti-Patterns Cheat-Sheet (curated)

Quick lookup of EventMachine anti-patterns + the correct alternative for each. Synthesised from `docs/best-practices/*.md`. Most failures come from agents bringing typed-OOP / Laravel / OpenAPI patterns into a state-machine domain.

## How to use this sheet

Search for what you're about to write. If a row matches, read the "instead" cell. Each row links to the canonical doc for full rationale.

## Action anti-patterns

| Anti-pattern | Why it's bad | Instead | Doc |
|---|---|---|---|
| **Action throws to block transition** (`if (!$ok) throw …;` inside `__invoke`) | Action runs AFTER guard approves — throw doesn't roll back the state change. Machine ends up inconsistent. | Move the check into a `GuardBehavior`. Action only writes context after guard returns true. | `docs/best-practices/action-design.md` |
| **Mega-action doing 5 things** | 5 reasons to change, 5 mocks, tangled failure modes | One responsibility per action. Compose in transition `'actions' => [a, b, c]`. | `docs/best-practices/action-design.md` |
| **Ordering dependency between actions** (action B reads context written by action A) | Coupled tests, fragile order | Calculator computes, action consumes. Or split into two states with `@always`. | `docs/best-practices/action-design.md` |
| **Lazy I/O fallback in action** (`if ($ctx->id === null) $ctx->id = Api::get(…)`) | Fires during scenario runs — scenarios intercept delegations, not actions | Move I/O into a `job`/`machine` delegation state with `@done`/`@fail`. | `docs/best-practices/action-design.md` § Scenario-Friendly Design |
| **Calling `$machine->send()` inside an action** | Re-entrant call into the engine; corrupts the macrostep | Use `raise()` for internal events within the same machine; `sendTo()` for cross-machine. | `docs/advanced/raised-events.md` |
| **Manually constructing `MachineEvent`** | Bypasses event sourcing, context diffs, persistence | Always go through `send()` / `raise()`. The engine owns the event log. | `docs/understanding/events.md` |

## Guard anti-patterns

| Anti-pattern | Why it's bad | Instead | Doc |
|---|---|---|---|
| **Guard with DB read / HTTP call / `now()`** | Guards must be pure (same input → same output). Engine snapshot/restore enforces purity across multi-branch transitions; impure guard leaks side effects | Use a `Calculator` (runs BEFORE guards) to fetch and stash the value in context, then guard reads from context. | `docs/best-practices/guard-design.md` |
| **Guard mutates context** (`$context->set(...)` inside guard) | Engine restores context if any guard returns false on a multi-branch transition — your write is silently discarded | Guards return bool only. Use action/calculator to mutate. | `docs/behaviors/guards.md` |
| **`Validate` prefix or other ad-hoc names** | Naming convention violation; signals intent unclear | Always `Is` / `Has` / `Can` / `Should` boolean prefixes. | `docs/building/conventions.md` |

## State design anti-patterns

| Anti-pattern | Why it's bad | Instead | Doc |
|---|---|---|---|
| **State explosion from boolean flags** (`processing_priority_fragile_gift`) | 3 booleans → 8 states; combinatorial explosion | Keep flags in context. State is for distinct *behaviors*, not data combinations. | `docs/best-practices/state-design.md` |
| **Imperative state names** (`process`, `submit`, `validate`) | Fails the "is" test ("the order is process" reads wrong) | Adjective / past participle: `processing`, `submitted`, `validating`. | `docs/building/conventions.md` |
| **Self-loop to reset a timer** (`'EVENT' => 'self'` expecting `state_entered_at` reset) | Self-loops are diff-based no-ops — `state_entered_at` is preserved by design; timer never re-arms | Transition through a transit state (e.g., `awaiting → renewing → awaiting`). | `docs/best-practices/time-based-patterns.md` § Renewable Timers |

## Parallel anti-patterns

| Anti-pattern | Why it's bad | Instead | Doc |
|---|---|---|---|
| **Shared context key across regions** (both regions write `status`) | Last-writer-wins silently; data corruption invisible until reproduction | Each region owns its keys: `paymentStatus`, `shippingStatus`. Coordinate via `raise()` / `sendTo()`. | `docs/best-practices/parallel-patterns.md` |
| **Cross-region transition** (target a state in a sibling orthogonal region) | Rejected at definition time | Send an event; let each region transition independently. | `docs/advanced/parallel-states/event-handling.md` |

## Decomposition anti-patterns

| Anti-pattern | Why it's bad | Instead | Doc |
|---|---|---|---|
| **Mega-machine** (50+ states, all sub-flows inline) | Impossible to visualise/test; payment changes break shipping | Extract child machines per sub-flow with own lifecycle. | `docs/best-practices/machine-decomposition.md` |
| **Too-tiny machine** (2 states, immediate `@always` to final) | Adds DB rows, event persistence, delegation plumbing without value | Calculator or action on parent. | `docs/best-practices/machine-decomposition.md` § Too-Tiny |
| **Excessive cross-machine messaging** (every state change pings the other) | Two machines pretending to be one; serialisation overhead | Merge them. Use hierarchical states. | `docs/best-practices/machine-decomposition.md` |
| **Modeling sync arithmetic as a machine** (calc service → 3-state idle/CALCULATE/done machine) | Boilerplate for a function | If pure deterministic computation: service or inline closure on parent. Promote to machine only when own lifecycle / reuse / failure modes / multi-step async appear. | `docs/best-practices/machine-decomposition.md` § When NOT to use a machine |

## Output anti-patterns

| Anti-pattern | Why it's bad | Instead | Doc |
|---|---|---|---|
| **Adding `'output'` to a state inside a parallel region** expecting child→parent context merge | Parser sees it as the parent state's own output (parallel-region restricted) and throws `InvalidOutputDefinitionException` | The child's final state declares `'output'`; parent's `@done` action picks it up via typed `MachineOutput` injection or `ChildMachineDoneEvent::output()`. | `references/output-keyword.md` |
| **DTO + OutputBehavior subclass + Action class for 4-line wire-up** | 200+ lines of class boilerplate for a closure | Closure on child final state's `'output'` + inline action key (`'wireXxxAction' => fn(...) => ...`) in `behavior.actions`. | SKILL.md §4 |
| **Plain array filter `'output' => ['user', 'order']` for non-scalar context** | Array filter passes through `toResponseArray()` → `ModelTransformer` serialises Eloquent models to IDs | Use a closure or `OutputBehavior` to control serialisation. | `docs/behaviors/outputs.md` |

## Naming anti-patterns

| Wrong | Right | Rule |
|---|---|---|
| `SUBMIT_ORDER` (command) | `ORDER_SUBMITTED` (past-tense fact) | Events are facts that already happened |
| `ORD_SUB` | `ORDER_SUBMITTED` | No abbreviations in event types |
| `revised_financed_amount` (snake_case payload key) | `revisedFinancedAmount` | Business data keys are camelCase |
| `process` (state, imperative) | `processing` (adjective/participle) | "is" test for states |
| `storeRevisedAmountInContext` (no type suffix) | `storeRevisedAmountAction` | Inline behavior keys: `{verb}{Obj}{Type}` — type suffix mandatory |
| `CalculatePricesAction` (name describes what it used to do) | `WirePricingContextAction` (current job) | Class name = current responsibility, not historical |

Full naming guide: `docs/building/conventions.md`. SKILL.md §1 has the table.

## When the agent reaches for an anti-pattern

Before writing, ask:
1. **Action with `if (...) throw`** → guard
2. **Guard with `DB::` / `Http::` / `now()`** → calculator + cached context
3. **`'output'` on parent state inside parallel region** → declare on child's final state instead
4. **Boolean flags carving new states** → context flags + state behavior
5. **DTO + Output class + Action class for plumbing** → closures + inline keys
6. **State name without "is" test passing** → rename
7. **2-state machine with `@always` to final** → calculator or action

These cover ~80% of agent-introduced violations in real refactors.
