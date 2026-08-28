---
tp:
  domain: software
  review_roles:
    execution-model:
      focus:
        - "Does each stated invariant describe something the runtime actually does?"
        - "Is any invariant unfalsifiable, or satisfiable by an implementation that is obviously wrong?"
---

# Parallel Path Analysis Termination

This spec states **invariants** — properties the analysis output must have. It deliberately does not
prescribe mechanism: signatures, cache rules, rendering strings and pinned test values are decided in
task acceptance, where the quality gate can check them. §7 records the constraints that mechanism
must respect, because those are facts about the system rather than choices.

## 1. Motivation

`machine:paths` terminates the PHP process with SIGSEGV (exit 139) on machines that combine a
parallel state with a transition that re-enters a parallel state. Reproduced by execution:

| Machine | States | Result today |
|---|---|---|
| `ReentrantParallelMachine` (test stub) | 17 | exit 139, no output |
| `CarSalesMachine` (production) | 46 | exit 139, no output |
| `TractorSalesMachine` (production) | 47 | exit 139, no output |

The crash is not size-related — a 17-state stub reproduces it, against 46 and 47 for the two production machines by the same measure. The trigger is a graph shape.

A second, independent defect makes `machine:scenario` answer "No path" for targets that are
genuinely reachable. It is not a consequence of the first: the scenario resolver never crashes and
returns cleanly. Reproduced on the cycle-free existing stub `ScenarioTestMachine`, where
`reviewing --START_PARALLEL--> parallel_check.region_a.checking_a` yields 0 paths.

## 2. Root causes

### 2.1 Unbounded recursion across the parallel region boundary

`PathEnumerator::handleParallel()` builds one sub-enumerator per region and starts it with an empty
visited set. `MachineGraph::transitionsFrom()` returns a state's own transitions plus every
ancestor's, so region DFS is not confined to the region: it follows an inherited transition out of
the region and walks the whole machine. On reaching any parallel state it spawns another
sub-enumerator, again with an empty visited set. Neither `$visitedIds` nor the `maxPaths` budget
crosses that boundary, so cycle detection can never fire.

The cycle check and the path ceiling both exist and both work. They are discarded at the boundary.

### 2.2 The scenario resolver never enters a parallel region

`ScenarioPathResolver::getNextStates()` handles `StateClassification::PARALLEL` by following only
`@done`, `@fail` and the parallel state's own transitions. It never descends into the regions, so
every state inside a region is unreachable from its BFS.

### 2.3 A parallel state's own transitions are never enumerated

`handleParallel()` follows `@done` and `@fail` only, so a transition declared on a parallel state is
absent from `machine:paths` output today.

## 3. Scope

`PathEnumerator`, `PathEnumerationResult`, `ParallelPathGroup`, `PathType`, `MachinePath`,
`PathCoverageReport`, `ScenarioPathResolver`, `ScenarioPath`, `ScenarioPathStep`,
`StateClassification`, `ScenarioScaffolder`, `ScenarioValidator`, `NoScenarioPathFoundException`,
`MachinePathsCommand`, `MachineCoverageCommand`, `MachineScenarioCommand`,
`MachineScenarioValidateCommand`, `Machine`'s coverage assertions, plus tests, docs and the agent
skill.

Out of scope: runtime transition behaviour, `MachineGraph::transitionsFrom()` semantics, and
`PathCoverageTracker`.

The scenario BFS iteration cap keeps its shipped default value. Making that cap injectable is **in**
scope: S3 is otherwise unverifiable, because the cap is a hardcoded local today.

## 4. Enumeration invariants

| # | Invariant |
|---|---|
| E1 | Enumeration terminates and returns a result for every definition the package can build, whatever the graph shape. No validation step is a precondition — no path-analysis entry point runs one. |
| E2 | Every step of an enumerated region path names a state inside that region, except a final step that records the region being left, and that step names the event and target that leave it. |
| E3 | A transition whose source is at or above a parallel state is followed by machine-level enumeration only; region enumeration does not follow it. E3 constrains which level follows a transition, not how many paths contain it — ordinary DFS repeats a transition across many paths and that is correct. |
| E4 | Every transition the runtime can take is represented somewhere in the output, or the analysis reports that it was truncated. Silent omission is a defect. Truncation is an exceptional outcome, not a way to satisfy E4: the reproduction stub and every machine in the existing test suites enumerate to completion without it. |
| E5 | No enumerated path is classified as a condition the runtime does not exhibit. In particular, a state the runtime can leave is never classified as a dead end. |
| E6 | A region state whose only continuations are followed at machine level under E3 is recorded as such, distinguishably from both a dead end and a region exit. This is the outcome E3 and E5 jointly require and neither alone supplies. |

E1 is the crash fix. E3, E4 and E6 together stop the fix from trading a crash for a lie: E3 says who
follows a transition, E4 forbids dropping it, E6 gives the region a truthful thing to record when E3
hands ownership upward.

## 5. Truncation invariants

| # | Invariant |
|---|---|
| T1 | Whenever the analysis stopped early, every surface that presents it says so — console output, `--json`, and the programmatic result. |
| T2 | A path that was cut short is distinguishable from one that reached a terminal point, in every one of those surfaces. |
| T3 | No path kind introduced by this change enters the coverage denominator unless an observed run could match it. Path kinds already in the denominator keep their current treatment — `GUARD_BLOCK` is already there and already unmatchable, and changing that is a T5 break, not this change's business. |
| T4 | Coverage never reports a passing figure computed over an enumeration that did not complete, without disclosing that it did not complete. |
| T5 | **Withdrawn as stated.** It read "a consumer whose suite passes today keeps passing, unless its machine contains a parallel state", and that was true of E3/E4 only. Following the continuations out of a final state (added later, see §12's D1 note) moves path counts on machines with **no** parallel state at all — measured at 2 → 5 and 2 → 8 on plain compound machines — so the carve-out no longer holds and no suite is guaranteed untouched. What remains true of E3/E4 alone: the **contents** of region paths, `ParallelPathGroup::combinationCount()` and the console `PARALLEL:` block move for any parallel machine, because region enumeration escapes through inherited ancestor transitions today. Note counts do not always move with them — `ParallelContinueMachine` keeps 3 and 3 region paths on both trees while what those paths say changes entirely — that is §2.1's premise. So the blast radius is not only machines whose parallel state carries its own transitions: it is every parallel machine where the parallel state **or an ancestor** carries one. A parallel machine with no transition at or above the parallel state produces byte-identical output before and after. |
| T6 | A region sub-enumerator never walks outside its own region, following a region-declared escape included. It records the exit edge and stops at the boundary; continuing past the target is machine-level work. A nested parallel's escape target lies outside the enclosing region, so without this the region walk resumes at machine level and re-enumerates every parallel it reaches — work multiplying per nesting level while the top-level path count stays flat, so neither ceiling trips and the analysis still reports itself complete. |
| T7 | `maxPaths` bounds the paths recorded by the analysis as a whole, region paths included. Region paths are handed to `ParallelPathGroup` rather than to the enumerator's own `$paths`, so a budget derived from `$paths` alone is re-issued near-full to every region at every nesting level, and a result can carry many times `maxPaths` while `pathLimitReached` stays false. |

T4 and T5 meet at the pre-existing `maxPaths` ceiling, which fires silently at its default (§7.7).
An earlier revision of this spec resolved that meeting in T5's favour — the path ceiling would not
newly fail a suite — on the ground that no coverage consumer could raise it. **That resolution is
withdrawn**, because the premise stopped holding within this change: `assertAllPathsCovered()` and
`assertPathCoverage()` now take `maxPaths` and `maxDepth`, so a consumer whose machine legitimately
needs a larger budget can say so. With the ceiling under the consumer's control, T4 binds on both
ceilings: a coverage assertion over a truncated enumeration fails rather than reporting a percentage
computed on a partial analysis. A suite that newly fails this way was already being told something
untrue, and now has the lever to fix it. T4 still binds on the disclosure too — the truncation is
reported on every surface T1 names, including `machine:coverage`'s own output and exit path, because
a green gate computed over an incomplete enumeration with no signal anywhere is the defect T4 exists
to prevent.

## 6. Scenario resolution invariants

| # | Invariant |
|---|---|
| S1 | A state the runtime can reach from the source via the event is reachable by the resolver, or the resolver reports that its search was truncated. |
| S2 | A resolved route that passes through a parallel region is marked as a projection of a concurrent configuration, distinguishably from exclusive compound descent. |
| S3 | A search that stopped early is distinguishable from one that completed and found nothing, for every caller — the command, the validator, and programmatic callers. |
| S4 | A route the resolver returns today is still returned, with the same meaning. |

## 7. Constraints on any mechanism

These are verified facts about the current implementation. They bind the tasks; they are not
themselves the design.

### 7.1 Two different runtime behaviours look alike from inside a region

`MachineDefinition::isParallelEscapeSource()` returns true only when a matched transition's source is
the parallel state itself or one of its ancestors. Everything else is handled by
`transitionParallelState()`, which has three further outcomes. All four were read off the code:

| Case | Runtime behaviour |
|---|---|
| source is the parallel state or an ancestor of it | the whole parallel state is exited, sibling regions end |
| source is an active region leaf, target is not parallel | that region's slot is re-pointed; the parallel state stays active |
| source is a compound inside a region, so not itself an active leaf | `array_search` over `$state->value` misses and no state value is updated at all; only entry processing runs |
| target is itself a parallel state | that one slot is `array_splice`d into the target's N initial leaves — this is the re-entrant shape §1 names as the crash trigger |

An analysis that treats these as one thing violates E5. Row 1 is the only case whose transition is
also visible at machine level.

### 7.2 The runtime deduplicates inherited handlers

`MachineDefinition::selectTransitions()` deduplicates by `spl_object_id($transitionDef)`, so an
ancestor-level handler matched from N active region states executes once, not N times. Any
per-branch counting rule must agree with that.

### 7.3 Region `@always` edges are reachable, by a different route

Two facts, and the second is the one that matters:

1. `MachineDefinition` gates `processPostEntryTransitions()` behind `count($state->value) <= 1` at
   both of its call sites, so that particular sweep does not run in a parallel configuration.
2. `transitionParallelState()` and `transition()`'s parallel-entry branch each run their **own
   ungated** `@always` sweep, looping over `$state->value` and re-entering `transition()` for any
   active state carrying an `@always`. (An earlier revision of this section credited the second
   sweep to the parallel `@done` handling, which contains none.)

So region `@always` edges **are** runtime-reachable. Dropping them from the analysis on the strength
of fact 1 alone would breach E4. An earlier revision of this spec drew exactly that wrong conclusion.

### 7.4 Region paths and machine paths live in different places

Region paths are collected into `ParallelPathGroup::$regionPaths` and never enter
`PathEnumerationResult::$paths`. Consequences: coverage cannot see region paths, a machine-level
count cannot detect a region-level regression, and anything added to region paths needs its own
surfacing to satisfy T1 and T2.

### 7.5 The suffix cache short-circuits the type dispatch

`PathEnumerator::dfs()` returns from its cache hit before dispatching on state type, so a second
encounter of a parallel state does not re-run region enumeration. Any depth or boundary rule that
assumes the dispatch always runs is unsound.

### 7.6 Coverage has no per-case dispatch to update, but five per-type sites

There is no `match` or `switch` over `PathType` in the package. A new case instead affects
`PathEnumerationResult`'s per-type filters, `MachinePathsCommand`'s console grouping,
`MachinePath`'s `GUARD_BLOCK` special case, `PathEnumerator`'s terminal-state nulling list, and
`PathEnumerator::classifyPath()`'s priority ordering. `StateClassification` has its own per-case
dispatch, including `ScenarioScaffolder`'s `default => null` arm, which any new classification
reaches.

### 7.7 Consumers construct the enumerator with defaults

**This section describes the situation before the change and is retained for the reasoning that
follows from it; §5 withdraws the conclusion.** As found, `MachineDefinition::enumeratePaths()` and
`MachineCoverageCommand` constructed `PathEnumerator` with no arguments, and
`Machine::buildPathCoverageReport()` reached it through the parameterless `enumeratePaths()`, so no
coverage consumer could raise a ceiling that started failing its suite. That is why T5 was originally
stated as it was. The change threads `maxPaths` and `maxDepth` through `enumeratePaths()`, both
coverage assertions and `machine:coverage`, so the premise no longer holds and the carve-out it
supported is withdrawn.

`MachinePathsCommand` is the exception: it does pass `--max-paths`. It is not a coverage consumer, so
it does not weaken T5, and an earlier revision of this spec wrongly lumped it in.

Region sub-enumerators are constructed inside `handleParallel()` and today inherit none of the
caller's settings, and their `pathLimitReached` is discarded — so region-level truncation currently
has no route to the result at all, which T1 and T2 require it to have.

## 8. Verification

The reproduction stub is `tests/Stubs/Machines/Parallel/ReentrantParallelMachine.php`: two parallel
states, each carrying a transition that re-enters a parallel state.

**On the production figures recorded during implementation.** T16 pinned 239 / 99 / 223 paths for
CarSales, IyiFinansCarSales and TractorSales. Those were correct when measured and remain the right
record of that task, but they are the **pre-final-state** numbers: the later change that follows
continuations out of a final state raises the same three machines to **878 / 390 / 862**, verified
by running one harness against both trees. Anyone comparing against T16's evidence should expect
the larger figures from the shipped code.

E1's failure modes are SIGSEGV, which cannot be caught and which kills a whole worker under parallel
Pest, and non-termination, which no in-process assertion can observe at all. Every check that
enumerates this stub therefore runs out of process — not only E1's — under a wall-clock bound, with
a timeout counted as a failure rather than a hang. The subprocess reports its results as data the
test decodes, so a check that never enumerated cannot pass by reporting success.

Expected values for the stub are **derived and pinned during implementation**, from the invariants
above and the stub's own definition, and recorded in the task that pins them. They are not asserted
here: a value this spec cannot derive would either be guessed, or read back off the implementation —
and a test whose expected value came from the code under test passes the gate exactly like a real
one while guarding nothing.

Deferring the values only moves that risk unless the task that pins them is constrained, so it is:

1. The values are derived and written down **before** the enumerator change is made, from the stub
   definition and the invariants, and the derivation is recorded in the task's evidence.
2. The derivation names, for each value, which invariant and which part of the stub produce it.
3. If the implementation then disagrees with a derived value, the disagreement is resolved by
   deciding which of the two is wrong — recorded either way. Silently adopting the observed value is
   the failure this condition exists to prevent.

| Invariant | How it is verified |
|---|---|
| E1 | out-of-process enumeration of the stub reports success |
| E2 | every region path step is checked against its region, allowing the recorded leaving step |
| E3 | a transition inherited from at or above a parallel state appears once across machine and region output |
| E4 | a transition declared inside a region that leaves it appears in the output |
| E5 | a region state the runtime can leave is not classified as a dead end |
| E6 | a region state whose only continuations are ancestor-declared is recorded distinguishably from both a dead end and a region exit |
| T1, T2 | console, JSON and programmatic surfaces each assert the truncation signal and the cut-short path |
| T3, T4 | coverage figures over truncated and non-truncated enumerations |
| T5 | the existing suites that construct enumerators with defaults still pass |
| S1 | `ScenarioTestMachine` resolves a target inside a parallel region |
| S2 | the resolved route's region entry is distinguishable from compound entry |
| S3 | a capped search reports differently from an exhausted one, in all three callers |
| S4 | `ScenarioPathResolverTest`'s existing expectations, and `PathEnumeratorTest`'s, still hold |

## 9. Documentation and skill

Documentation states that region paths are scoped to their region, that a parallel state's own
transitions are enumerated at machine level, that a truncated analysis is reported rather than
silently dropped, and how truncation interacts with coverage.

`skills/event-machine/SKILL.md` gains the parallel path-analysis behaviour in its gotcha material,
so agents do not read a truncated analysis as a complete one.

## 10. Backward compatibility

| Change | Impact |
|---|---|
| `PathType` gains cases | the five per-type sites in §7.6 must handle them; no consumer `match` exists to break |
| `PathEnumerationResult` and `PathEnumerator` gain settings | appended with defaults; existing construction sites unaffected |
| E3/E4 change what is enumerated for parallel machines | path counts, region path counts and coverage percentages move for every parallel machine whose parallel state **or an ancestor** carries a transition — the T5 exception. Region enumeration escapes through inherited ancestor transitions today, which is §2.1's premise, so the radius is wider than "parallel states carrying their own transitions" (an earlier revision) but narrower than "every parallel machine" (the revision after it): with no transition at or above the parallel state, output is byte-identical |
| S2 adds a step to routes through parallel regions | those routes did not resolve at all before |
| Final states gain continuations (both analysers) | path counts rise on **any** machine with a final state under an inherited handler, a guarded compound `@done`, or a delegating final state — parallel or not. This is the release's largest blast radius: 239→878, 99→390, 223→862 on the production machines, and 2→5 / 2→8 on plain compound ones. Coverage percentages fall accordingly and `assertAllPathsCovered()` fails where it passed |
| `machine:paths --json` gains keys | additive; no existing key changes meaning |

An existing key's meaning is not redefined. Where a new reading is wanted, it gets a new key.

## 11. Release

Minor release. `PathType` gains cases and two classes gain defaulted settings. On the command
surface: `machine:paths` gains `--max-depth` and JSON keys, `machine:coverage` gains `--max-paths`
and `--max-depth`, and both `machine:scenario` and `machine:scenario-validate` gain
`--max-iterations`; every integer option on those four commands is now validated rather than coerced
to zero. Coverage accounting changes for truncated enumerations, and reports an observation no
enumerated path matches.

The change with the largest blast radius is not a flag: following the continuations out of a final
state raises path counts substantially on machines that have them — by roughly 3.7× on the three
production machines this was validated against — which lowers every coverage percentage computed
over such a machine and will fail `assertAllPathsCovered()` where it passed. Release notes should
lead with that, not with the segfault. Docs and skill ship in the same tag.

## 12. Deferred

Found during the audit rounds, deliberately left out of this change and recorded here so the next
reader inherits the finding rather than rediscovering it. Each was checked against `main`: all are
pre-existing, none is a regression from this work.

**D1 was on this list and is now fixed instead.** It was the finding that `handleFinal()` never
consulted `transitionsFrom()`, so a route continuing out of a final state was invisible. It was
deferred as too large, then escalated when the security auditor verified against the runtime that
the machine does take such a route while `assertAllPathsCovered()` passed over the gap. Both the
enumerator and the scenario resolver now follow those continuations. The numbering below is left
as it was so earlier round notes still resolve.

| # | Finding | Why deferred |
|---|---|---|
| D2 | **A compound-sourced escape is labelled `REGION_EXIT` identically to the active-leaf case.** At runtime the two differ: `MachineDefinition`'s `array_search` misses for the compound source, so no state value updates. The analysis cannot currently tell them apart. | Needs a distinct path type or a step flag, i.e. new public surface. |
| D3 | **A region whose `initial` child carries an explicit `id` resolves to `null`.** `findInitialStateDefinition()` returns null, the `if` around the region walk has no `else`, and the region is recorded empty with no disclosure. | Disclosing it needs a third truncation-like channel; both existing flags would name the wrong cause. **And the analysis is not lying:** `MachineDefinition.php:640-643` skips entering that region at runtime for the same reason, so no runtime-takeable transition is being omitted. This is a broken machine the analyser reports quietly, not a gap in the analyser. |
| D4 | **`handleParallel()` is over 330 lines and carries around eleven concerns** — group reuse, region sub-enumeration, budget arithmetic, nested-group merging, truncation propagation, escape collection, escape following, `@done`, `@fail`, own-transition partitioning, and the dead-end/deferred decision. The obvious first extraction is the `@done`/`@fail` pair, two ~36-line near-clones differing only in the property and the event literal. | Each audit round touched a *different* arm, so a rewrite is the one change the suite cannot validate by diff. **This reason renews itself** — every round's churn regenerates it — so it needs a commitment rather than a judgement: extract in the release after this one, before any further behaviour work lands in this method. |
| D5 | **`resolveClassFromFile()` is triplicated** across `MachineCoverageCommand`, `MachinePathsCommand` and `ExportXStateCommand`, byte-identical but for two comment lines — and it is not merely duplication. It `require_once`s the file, so it loads code into the process, and its `preg_match('/class\s+(\w+)\s+extends/')` takes the **first** extending class in the file: a file declaring a helper class before the machine resolves to the wrong FQCN, which the command then calls `::definition()` on. | Deferred for scope, not because it is harmless — an earlier revision of this row claimed it was read-only and could not produce a wrong answer, and both halves of that were false. Fixing it means changing class resolution in three commands at once. |
| D6 | **The `1000`/`200` ceilings are literals in six declaration sites** rather than `config/machine.php`, unlike `max_transition_depth`: `PathEnumerator`'s constructor, `MachineDefinition::enumeratePaths()`, both coverage assertions on `Machine`, and the `machine:paths` and `machine:coverage` signatures. (The scenario search ceiling adds three more `1000`s under a different name — `ScenarioPathResolver`, `ScenarioValidator` and `machine:scenario-validate` — which is why a plain grep for the literal gives a larger number.) | Each is overridable at the point it matters; config-backing is ergonomics, not correctness. |
| D7 | **The two `catch (\Throwable) {}` reload fallbacks in `Machine`** — in `send()` and in `dispatchPendingChildJobs()` — state the fallback but not why the caller must not see the failure, and catch programming errors alongside transient ones. | Pre-existing and unchanged on this branch. Note the reason is weaker than it looks for `send()`: continuing on a stale local state then mutates a forked timeline under a freshly acquired lock, which does look like success to the caller. |
| D8 | **`machine:coverage` run in-process destroys a tracking suite's observations.** `importFromFile`/`importFromDirectory` *replace* `$observedPaths`, so by the time the command's cleanup runs, whatever the surrounding suite had recorded is already gone. Restoring the enabled flag (which this change does) keeps collection alive from that point but cannot bring those back. | The documented flow runs the command in a separate process, where this cannot arise. Making it safe in-process means giving the tracker a snapshot/restore API — new public surface. |
| D10 | **The region boundary tests the transition's target, never its source.** `leavesBoundary()` asks only where a branch points, so a transition declared **on** a parallel state whose target is **inside** one of its own regions is followed by region enumeration too. Two shapes reproduce it: an explicit `on REWIND => working.alpha.first` gives region `alpha` a `[loop] first→[REWIND]→first` the runtime never shows (`MachineDefinition.php:1107` makes that source a parallel escape, so the runtime leaves the whole parallel); and, far more commonly, any guarded `on` handler on a parallel state — `enumerateTransitions` records `GUARD_BLOCK` even when every branch was deferred, which also sets `$enumerated` and suppresses the `REGION_DEFERRED` rule 6 asks for. Fix sketch: `leavesBoundary()` should also consult `isDeclaredInsideBoundary($source)`, and the `GUARD_BLOCK` record should be conditional on something having actually been enumerated. | Deferred, not hidden. It cannot regress the crash this release fixes (the boundary still confines the walk), and it cannot move any coverage figure, because region paths never enter `PathEnumerationResult::$paths` — the only list `PathCoverageReport` reads. Its blast radius is the `PARALLEL:` block of a diagnostic command: an extra region path and a wrong label on one of them. It is also strictly better than what ships today, where that same region walked the whole machine. |
| D9 | **`PathCoverageTrackerTest` leaks its export directory.** It calls `setExportDirectory()` to a temp dir it later deletes, and `reset()` does not restore that static, so the path stays set for the rest of the process. Harmless today only because every coverage-command test passes `--from`. | Test-local, no production path reaches it, and the fix belongs with whatever gives the tracker a proper lifecycle. |
| D11 | **Parallel-group reuse is order-dependent.** A parallel reachable by both a short and a long route is analysed on whichever route reaches it first, and the group recorded then is reused. At `maxDepth=8` with a 2-step and a 6-step route, declaring the short one first gives `depthLimitReached=false` and a complete region path; declaring the long one first gives `true` and a truncated one — same machine, same ceilings, different verdict from `on`-key order. | The verdict is disclosed either way (the flag is raised, not hidden), so no analysis silently claims completeness. Making it order-independent means analysing a parallel on its shallowest route, which needs a scheduling pass this change does not have. |
