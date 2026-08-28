# Derivation — expected enumeration of `ReentrantParallelMachine`

Produced by T01, **before** any `src/Analysis` change, as §8 of
[the spec](upcoming-parallel-path-analysis-termination.md) requires. The test written in T14 asserts
these values; it must not read them back off the implementation.

If the implementation disagrees with a value here, the disagreement is resolved by deciding which of
the two is wrong, and the decision is recorded — silently adopting the observed value is the failure
this document exists to prevent.

## The stub

```
idle              on: START -> data_collection
data_collection   PARALLEL   on: RESTART -> data_collection   @done -> verification
  retailer        initial awaiting_vehicle
    awaiting_vehicle   on: VEHICLE_PROVIDED -> vehicle_ready
    vehicle_ready      FINAL
  customer_info   initial awaiting_customer
    awaiting_customer  on: CUSTOMER_PROVIDED -> customer_ready
    customer_ready     FINAL
verification      PARALLEL   on: EDIT -> data_collection      @done -> completed
  findeks         initial running;  running on: FINDEKS_DONE -> finished;  finished FINAL
  turmob          initial running;  running on: TURMOB_DONE  -> finished;  finished FINAL
completed         FINAL
```

## Machine-level enumeration

`idle` is atomic and its only transition is `START`, so enumeration enters `data_collection`.
Being parallel, it records a region group, follows `@done` to `verification`, and — per **E3** —
follows its own `RESTART`, whose target is already in `visitedIds` and therefore terminates as a
loop. `verification` behaves the same way: it records its group, follows its own `EDIT` to the
already-visited `data_collection` as a second loop, and follows `@done` to `completed`. `completed`
is final with no compound `@done` above it, so it classifies as a completed path.

| Value | Expected | Derived from |
|---|---|---|
| machine-level paths | 3 | the three terminations below |
| completed path | 1 | `idle → data_collection → verification → completed`, the `@done` chain |
| loop paths | 2 | E3 gives machine level `RESTART` and `EDIT`; both re-enter a visited parallel state |
| truncated paths | 0 | E4 — this stub must enumerate to completion |
| region-exit paths | 0 | see the gap below |
| machine-level-owned (E6) paths | 0 | see the gap below |
| `parallelGroups` | 2 | the two states declared `'type' => 'parallel'` |

## Region enumeration

Each region state inherits its parallel state's transition (`RESTART` for `data_collection`'s
regions, `EDIT` for `verification`'s). Both have their **source at the parallel state itself**, so
**E3** assigns them to machine level and region enumeration does not follow them. Every region state
also has an in-region transition, so **E6** does not apply to any of them.

| Region | Paths | Content |
|---|---|---|
| `retailer` | 1 | `awaiting_vehicle → vehicle_ready` via `VEHICLE_PROVIDED` |
| `customer_info` | 1 | `awaiting_customer → customer_ready` via `CUSTOMER_PROVIDED` |
| `findeks` | 1 | `running → finished` via `FINDEKS_DONE` |
| `turmob` | 1 | `running → finished` via `TURMOB_DONE` |

`combinationCount()` is 1 for each group (1 × 1).

Every region path step names a state inside its own region, and no path carries a region-exit step —
which is what **E2** requires of this stub specifically.

## Gap this derivation surfaced

**The reproduction stub exercises neither `REGION_EXIT` nor E6.** A region exit needs a transition
declared *inside* a region whose target is outside it; E6 needs a region state whose *only*
continuations are ancestor-declared. Here both parallel states declare their escaping transition on
the parallel state itself, and every region state also has an in-region transition, so neither
condition is met.

T15 therefore requires a second stub covering both outcomes. Without it, two of the six enumeration
invariants would ship with no test reaching them.
