# Where `ScenarioPathResolver`'s Graph Disagrees With the Runtime

> **Status:** Draft — a catalogue, not a plan. Nothing here is scheduled.

Four places where the resolver's view of a `MachineDefinition` differs from what the machine does at
runtime. All four predate `spec/upcoming-weighted-path-resolution.md` and are out of scope for it; they
were enumerated during that spec's review and moved here so it could specify its change rather than the
resolver's whole standing behaviour. Each was checked against the code when written.

That spec's own accepted imprecisions live in `spec/upcoming-weighted-path-resolution.derivation.md`,
not here. This file is only about the graph-versus-runtime gap.

Weighted resolution makes several of these shapes *more* likely to win — a free `@always` chain and a
3-weight delegation both beat a 5-weight parallel — so a route the runtime refuses is easier to surface
than it was under breadth-first. That is why the catalogue is worth keeping even though nothing here is
scheduled.

## The four

1. **A delegation state is left only by its four delegation keys.** The DELEGATION arm follows only
   `@done`, `@done.{finalState}`, `@fail` and `@timeout`, so such a state has no successors, and
   terminates any path reaching it, exactly when it carries none of those four. The runtime disagrees
   for the fire-and-forget shape, which is a `machine`/`job` state carrying a **`target`** rather than a
   `@done` — the engine requires one or the other and rejects a state with neither, so the shape is not
   the one an earlier draft of this file described. `target` is not among the four keys, so the resolver
   dead-ends exactly where the parent leaves by it while the child runs independently. What that
   produces is a path that is *missing*, not a wrong one, so it cannot be spotted by inspecting a
   returned result. Pinned by `tests/Analysis/ScenarioDelegationTraversalTest.php`, which also shows an
   ordinary `on:` transition on a delegation state contributing no successor either.
2. **A deferred invoke that the macrostep skips is still traversed.** The invoke runs at the end of the
   macrostep and is skipped entirely if the machine has already moved. The resolver always traverses the
   four delegation keys and never the edge that moved the machine. Two shapes reach it. An `@always` on
   the same state — which `classifyState()` hides, since DELEGATION outranks TRANSIENT — drains inside
   the macrostep and takes the machine away; this is unconditional when the `@always` is unguarded, and
   guard-dependent when it is not, though a scenario cannot exploit that: `buildPlanEntries()` emits per
   classification, and the guard block belongs to TRANSIENT, so a DELEGATION-classified state gets only
   `'route' => '@done',` and no place to pin the hidden `@always` false. An entry action that `raise()`s
   an event the state handles by an ordinary `on:` transition reaches the same outcome with no
   classification conflict at all.
3. **A parallel state's own `@always` is never traversed.** PARALLEL outranks TRANSIENT and the parallel
   arm excludes `@always` explicitly. If the `@always` is taken the machine leaves the parallel state,
   and every region descent and every `@done`/`@fail` exit the resolver enumerated is a route it will not
   run. A region timeout is the mirror case: under `parallel_dispatch` it fires as a queued job on an
   edge no `on:` key carries, so it exists at runtime and not in the graph at all.
4. **`@done.{finalState}` is never validated against the child machine.** The resolver walks one
   `MachineDefinition`, so a branch naming a final state the child cannot reach is an edge that exists
   only in the parent's graph, traversed like any other exit. This is the one disagreement that is not
   intra-definition.

## Two the review did not settle

- **An ancestor's `@always`.** `classifyState()` resolves `@always` through the parent chain, so a
  compound ancestor's `@always` classifies a delegation or parallel descendant the same way rules 2 and
  3 describe. Whether it strands the same edges was never checked. Its *pricing* consequence is
  separate, is settled, and is recorded in the derivation file.
- **A synchronous entry listener.** Narrower than it first appeared. A `queue: true` listener is a
  `ListenerJob` buffered until persist and cannot strand the invoke, and `exit`/`transition` hooks fall
  outside the pre-invoke window. Only a synchronous `entry` listener that raises an event is a third
  producer of rule 2, and it was never examined.

## What none of this covers

`@done`/`@fail` on a parallel state that also carries an invoke. Those are the same config keys with two
runtime meanings — all-regions-final, and child completion — and neither this file nor the spec says
which the resolver traverses or what the author must stand in for. It is a gap in both documents rather
than a disagreement between graph and runtime, which is why it is named here and priced nowhere.
