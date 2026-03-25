# Hardened Testing — Research & Plan

> Status: Plan Ready for Beads
> Created: 2026-03-25
> Scope: Add critical tests inspired by industry-leading state machine implementations

---

## Part G: Execution Plan

### Critical Rules for Agents

> **MANDATORY — every bead task agent MUST follow these rules:**
>
> 1. **NO shortcuts.** Do not combine multiple tasks into one. Do not skip steps. Do not summarize instead of reading. Complete each task fully before moving to the next.
> 2. **NO parallel task execution within a bead.** Each bead is one focused unit of work. Do not try to "speed up" by doing multiple things at once — this causes missed items.
> 3. **Clone repos to /tmp before reading.** For Workstream 3 implementation reviews: `git clone --depth=1` the repo to `/tmp/<name>` FIRST. Do NOT proceed without a local copy. Do NOT rely on web fetches of individual files — you will miss context.
> 4. **Read EVERY test file.** When a bead says "read all test files", that means opening and reading every single file — not sampling, not skimming filenames, not reading 3 out of 20. If there are 56 test files, read all 56. Track which files you've read with a checklist.
> 5. **Read files completely.** Do not read only the first 100 lines of a 500-line test file. Read the entire file. Use offset/limit to paginate if needed, but do not stop until you've read every line.
> 6. **One pass = one theme only.** When doing a themed pass (e.g., "edge cases"), focus ONLY on that theme. Do not open beads for other themes — those will be caught in their dedicated pass.
> 7. **Dedup before opening.** Before opening any new bead, check the existing bead list AND grep existing EventMachine test files. Document what you checked.
> 8. **Do NOT shy away from opening new beads.** The initial plan may produce ~50 beads. Those beads will produce hundreds more — this is expected and desired. Every gap found deserves its own bead. Do not batch unrelated tests into a single bead to "keep the count down." Do not skip opening a bead because "there are already many." Thoroughness is the entire point of this effort. One bead = one focused test scenario.
> 9. **Use beads-from-plan to create child beads.** When a review bead needs to open multiple new beads, use the `/beads-from-plan` skill to create them — do NOT create beads manually or inline. Write a mini-plan in markdown, then invoke beads-from-plan on it. This ensures consistent formatting and tracking.
> 10. **Agentic commits + quality gate on every test-writing bead.** Every test-writing bead MUST:
>     - Use `/agentic-commits` for all commits (atomic, structured format)
>     - Run `composer quality` before marking the bead as complete
>     - If `composer quality` fails, fix the issue and re-run — do NOT mark complete with failures
>     - These two requirements apply to EVERY test-writing bead, including child beads opened by review beads
> 11. **Never stop until ALL beads are done.** Once the plan starts executing, do NOT pause, ask for confirmation, or wait between beads. Complete one bead, move to the next. If a bead produces child beads, execute those too. Continue until every single bead (including all dynamically created child beads) is resolved. The only reason to stop is an unrecoverable blocker that requires human input.
> 12. **Failing tests = fix the code (TDD approach).** When a newly written test fails, this is a FEATURE not a bug — it means we found a real gap. The agent MUST:
>     - First, verify the test is correct (testing the right behavior per SCXML/statechart semantics)
>     - If the test is correct and the code is wrong: fix the EventMachine source code to make the test pass
>     - If the test expectation is wrong (misunderstanding of EventMachine's intentional deviation from spec): adjust the test and document why
>     - Do NOT skip, delete, or mark as "known failure" — every test must pass before moving on
>     - Run `composer quality` after fixes to ensure nothing else broke

### Overview

Three workstreams, each producing bead tasks that themselves produce further bead tasks:

```
Workstream 1: General Problems Review (5 themed passes)         — sequential
Workstream 2: W3C SCXML IRP Mapping (standalone, 192 tests)     — independent
Workstream 3: Implementation Test Deep-Dives (Tier 1/2/3)       — sequential per impl
```

**Sequencing rules:**
- Workstream 1 passes are **strictly sequential** (Pass 2 starts after Pass 1 completes, so it can see Pass 1's beads)
- Workstream 2 is **independent** — can run at any time
- Workstream 3 implementations are **sequential within each tier** but different tiers can overlap
- Workstream 2 (W3C) completing first is **recommended** (informs other workstreams) but not required

### Pass Themes (apply to both Workstream 1 and 3)

| Pass | Theme | Lens |
|------|-------|------|
| 1 | **Happy path / semantic correctness** | Basic behavior that should work but isn't tested |
| 2 | **Edge cases & boundary conditions** | Off-by-one, empty, null, max depth, single-region parallel |
| 3 | **Async / concurrent / race conditions** | Timing, locking, parallel dispatch, worker crashes |
| 4 | **Failure / recovery / timeout** | Exception paths, @fail, @timeout, archive/restore |
| 5 | **Cross-cutting concerns** | Ordering guarantees, persistence fidelity, serialization, context integrity |

### Bead Types

There are two distinct bead types in this plan:

1. **Review beads** — Read source material, find gaps, open test-writing beads. A review bead does NOT write tests itself.
2. **Test-writing beads** — Write actual EventMachine test code (and stub machines if needed). Each test-writing bead is self-contained: it has enough context from its description to write the test without re-reading the source implementation.

### Workstream 1: General Problems Review

Review `spec/hardened-testing-research.md` (50+ documented problems) with 5 themed passes.
Passes are **strictly sequential** — each pass sees the previous pass's output.

```
[Review Bead] Pass 1: Review ALL problems through happy-path lens → open test-writing beads
[Review Bead] Pass 2: Review ALL problems through edge-case lens → open test-writing beads
[Review Bead] Pass 3: Review ALL problems through async/race lens → open test-writing beads
[Review Bead] Pass 4: Review ALL problems through failure/recovery lens → open test-writing beads
[Review Bead] Pass 5: Review ALL problems through cross-cutting lens → open test-writing beads
```

Each pass:
1. Re-read ALL 50+ problems in the research doc (no skipping)
2. For each problem, check existing EventMachine tests for coverage of THIS THEME specifically
3. For each uncovered scenario: open a new test-writing bead (Feature/E2E/LocalQA)
4. Before opening: check existing bead tasks for duplicates (document what you checked)

### Workstream 2: W3C SCXML IRP Test Mapping

Standalone workstream — maps all 192 W3C tests to EventMachine equivalents.
Reference SCION's W3C test runner approach for how to map SCXML tests to executable assertions.

```
[Bead] Fetch W3C SCXML IRP test manifest and .txml files (from W3C site or SCION/USCXML repo copies)
       → If W3C site is inaccessible, use SCION repo: github.com/jbeard4/SCION or USCXML repo copies
[Bead] Categorize ALL 192 tests: applicable / not-applicable / partially-applicable to EventMachine
       → Document WHY each non-applicable test is excluded (e.g., "history states not implemented")
[Bead] For each applicable category: create EventMachine test stubs
  → [Test-writing Bead] <scxml> and <state> tests (initial state, compound states)
  → [Test-writing Bead] <parallel> tests (orthogonal regions, done detection)
  → [Test-writing Bead] <transition> tests (event matching, conditions, targets, priority, internal/external)
  → [Test-writing Bead] <onentry>/<onexit> tests (action ordering)
  → [Test-writing Bead] <raise> and <send> tests (internal/external event queuing, delayed events)
  → [Test-writing Bead] <invoke> tests (child machine invocation, autoforward, finalize)
  → [Test-writing Bead] <final> tests (final states, donedata)
  → [Test-writing Bead] <cancel> tests (timer cancellation)
  → [Test-writing Bead] Event processing tests (macrostep semantics, eventless transitions)
  → [Test-writing Bead] Data model tests (context initialization, assignment)
```

### Workstream 3: Implementation Test Deep-Dives

#### Mandatory Setup for Every Implementation Review

```bash
# MUST be done before ANY review pass — no exceptions
git clone --depth=1 <repo-url> /tmp/<implementation-name>
# Then list ALL test files:
find /tmp/<implementation-name> -name "*test*" -o -name "*spec*" | sort > /tmp/<name>-test-manifest.txt
# Count them to set expectations:
wc -l /tmp/<name>-test-manifest.txt
```

The agent MUST read every file listed in the manifest. Track progress with a checklist in the bead output.

#### Tier 1 — Full Deep-Dive (5 themed passes each)

For each Tier 1 implementation, the first bead clones the repo and reads ALL test files to build context.
Subsequent pass beads re-read the test files through a specific themed lens.

```
[Review Bead] Deep-dive: Clone repo to /tmp, read ALL test files (build manifest, track checklist)
  → [Review Bead] Pass 1 (happy path): Re-read all tests through this lens → open test-writing beads
  → [Review Bead] Pass 2 (edge cases): Re-read all tests through this lens → open test-writing beads
  → [Review Bead] Pass 3 (async/race): Re-read all tests through this lens → open test-writing beads
  → [Review Bead] Pass 4 (failure/recovery): Re-read all tests through this lens → open test-writing beads
  → [Review Bead] Pass 5 (cross-cutting): Re-read all tests through this lens → open test-writing beads
```

Passes within an implementation are **strictly sequential**.

**Tier 1 implementations (3):**

1. **XState** — `packages/core/test/` (56 files, ~800KB)
   - Clone: `git clone --depth=1 https://github.com/statelyai/xstate.git /tmp/xstate`
   - Deep-dive bead reads files in groups (context window management):
     - Group A: `parallel.test.ts`, `final.test.ts`, `transient.test.ts`, `transition.test.ts`
     - Group B: `invoke.test.ts`, `actor.test.ts`, `system.test.ts`
     - Group C: `actions.test.ts`, `guards.test.ts`, `assign.test.ts`
     - Group D: `rehydration.test.ts`, `scxml.test.ts`, `state.test.ts`, `errors.test.ts`
     - Group E: All remaining test files
   - XState history.test.ts: read for general patterns only (EventMachine has no history states)

2. **MassTransit** — `tests/MassTransit.Tests/SagaStateMachineTests/` (42 files)
   - Clone: `git clone --depth=1 https://github.com/MassTransit/MassTransit.git /tmp/masstransit`
   - Focus on saga state machine tests only (ignore other test directories)

3. **python-statemachine** — `tests/` (53 files)
   - Clone: `git clone --depth=1 https://github.com/fgmacedo/python-statemachine.git /tmp/python-statemachine`
   - Read all 53 test files, no exceptions

#### Tier 2 — Targeted Review (2 passes each)

Tier 2 passes are NOT themed from the global 5-theme list. Instead, each implementation gets 2 passes focused on its specific strengths:

```
[Review Bead] Targeted review: Clone repo to /tmp, read ALL test files in target area
  → [Review Bead] Pass 1 (primary strength area): Review relevant tests → open test-writing beads
  → [Review Bead] Pass 2 (secondary area): Review remaining relevant tests → open test-writing beads
```

**Tier 2 implementations (4):**

4. **Spring Statemachine** — Pass 1: persistence + region tests, Pass 2: reactive/async
   - Clone: `git clone --depth=1 https://github.com/spring-projects/spring-statemachine.git /tmp/spring-statemachine`

5. **Boost.Statechart** — Pass 1: triggering event access + event deferral, Pass 2: compile-time validation
   - Clone: `git clone --depth=1 https://github.com/boostorg/statechart.git /tmp/boost-statechart`
   - SKIP history tests (EventMachine has no history states)

6. **Apache Commons SCXML** — Pass 1: tie-breaker tests (6 files) + W3C runner, Pass 2: parallel fixtures
   - Clone: `git clone --depth=1 https://github.com/apache/commons-scxml.git /tmp/commons-scxml`

7. **AASM** — Pass 1: guard tests (6 files) + memory leaks, Pass 2: persistence adapters
   - Clone: `git clone --depth=1 https://github.com/aasm/aasm.git /tmp/aasm`

#### Tier 3 — Skim (1 pass each)

One review pass per implementation, focused on unique patterns not found in Tier 1/2.
Still MUST clone and read test files — no web-only skimming.

```
[Review Bead] Skim: Clone repo, read test files, open beads for NOVEL findings only
```

**Tier 3 implementations (5):**

8. **Sismic** — Property-based verification, design-by-contract
   - Clone: `git clone --depth=1 https://github.com/AlexandreDecan/sismic.git /tmp/sismic`

9. **Stateless (.NET)** — Firing modes (immediate vs queued), async patterns
   - Clone: `git clone --depth=1 https://github.com/dotnet-state-machine/stateless.git /tmp/stateless`

10. **Erlang gen_statem** — Event postponement, multiple timeout types
    - Clone: `git clone --depth=1 https://github.com/erlang/otp.git /tmp/otp`
    - Focus on `lib/stdlib/test/gen_statem_SUITE.erl` only

11. **Squirrel** — Machine verification, performance benchmarks
    - Clone: `git clone --depth=1 https://github.com/hekailiang/squirrel.git /tmp/squirrel`

12. **pytransitions** — Thread safety tests, parallel/async separation
    - Clone: `git clone --depth=1 https://github.com/pytransitions/transitions.git /tmp/pytransitions`

### Completion Criteria

The hardened testing effort is **complete** when:
1. All 5 passes of Workstream 1 are done
2. All 192 W3C tests are categorized and all applicable tests have EventMachine equivalents
3. All Tier 1 implementations have completed 5 passes each
4. All Tier 2 implementations have completed 2 passes each
5. All Tier 3 implementations have completed 1 pass each
6. ALL test-writing beads opened by review beads are resolved (test code written and passing)
7. `composer quality` passes with all new tests included

### Dedup Protocol

Before opening any new test-writing bead, the agent MUST:
1. Read the current bead task list — document which beads were checked
2. Grep existing EventMachine test files for the scenario — document the grep pattern used
3. Only open if no existing task or test covers this specific scenario
4. Reference the source (problem #, implementation name, test file, line numbers) in the bead description
5. If a duplicate is found, add the new source reference to the existing bead as additional context

### Test-Writing Bead Format

Each test-writing bead opened by a review pass must specify:
- **Priority**: Critical / High / Medium / Low (aligned with Part C priority tiers)
- **Source**: Which problem/implementation/test file inspired it (with line numbers where applicable)
- **Type**: Feature test / E2E test / LocalQA test
- **Scenario**: Concrete description of what to test
- **Expected behavior**: What the correct outcome should be
- **Stub machine needed**: Yes/No (if a new test stub machine is required)
- **Dedup check**: What was searched to confirm this is not a duplicate
- **Workflow**: Use `/agentic-commits` for commits, run `composer quality` before completing

### Mandatory Rules Block

Every bead description — both the initial beads created from this plan AND all dynamically created child beads — MUST start with the following rules block. Copy-paste it verbatim into every bead description:

```
⚠️ MANDATORY RULES — read before starting:
- Read the full spec: spec/upcoming-hardened-testing.md (Part G: Critical Rules for Agents)
- NO shortcuts. No parallel tasks within a bead. No skipping. No summarizing instead of reading.
- For implementation reviews: clone repo to /tmp FIRST. Read EVERY test file completely. Track with checklist.
- One pass = one theme only. Do not mix themes.
- Dedup before opening new beads: check bead list + grep existing tests. Document what you checked.
- Do NOT batch. One bead = one focused scenario. Open as many child beads as needed — hundreds is expected.
- Use /beads-from-plan to create child beads (never manually).
- For test-writing beads: use /agentic-commits for commits. Run composer quality before completing.
- Failing tests = fix the code (TDD). Do NOT skip/delete/mark-as-known-failure.
- Never stop. Complete this bead, then move to the next. Continue until ALL beads are done.
```

### Review Bead Output Format

When a review bead finds multiple gaps, it must:
1. Write a mini-plan in markdown listing all gaps found (one per line, with the Test-Writing Bead Format fields)
2. Use `/beads-from-plan` to create the child beads from this mini-plan
3. Each child bead description must include the Mandatory Rules Block at the top, followed by the full Test-Writing Bead Format fields

---

## Part A: State Machine Implementation Survey

### Tier 1 — Must-Study Test Suites

#### 1. W3C SCXML IRP Tests (The Gold Standard)
- **URL**: https://www.w3.org/Voice/2013/scxml-irp/
- **Mirror**: https://github.com/alexzhornyak/SCXML-tutorial
- **159 mandatory + 33 optional** = 192 total tests
- Covers every normative assertion: `<parallel>`, `<transition>`, `<history>`, `<invoke>`, `<send>`, `<cancel>`, `<raise>`, entry/exit ordering, event processing semantics
- Implementations that pass all: **SCION** (159/159 + 33/33), USCXML (~155/159), Qt SCXML (~155/159)
- **Relevance**: Parallel done detection, transition priority, entry/exit ordering, internal vs external transitions, child invocation

#### 2. XState (TypeScript, 29K stars)
- **GitHub**: https://github.com/statelyai/xstate
- **Test location**: `packages/core/test/` — 56 test files, ~800KB of test code
- **Key files**:
  - `invoke.test.ts` (91KB) — child machine invocation (largest behavioral test file)
  - `actions.test.ts` (100KB) — entry/exit/transition actions
  - `history.test.ts` (35KB) — shallow/deep history, history in parallel
  - `parallel.test.ts` (31KB) — parallel regions, done detection, re-entry, guards
  - `transient.test.ts` (17KB) — eventless (@always) transitions
  - `rehydration.test.ts` (12KB) — state persistence and restoration
  - `scxml.test.ts` (19KB) — W3C SCXML conformance runner
- **Notable bugs** (directly relevant regression tests):
  - #1885: External self-transition resets child states
  - #400, #1829: Parallel external transitions reset sibling regions
  - #2349: Nested parallel done events don't bubble
  - #753: Delayed transitions in parallel can't be reset
  - #427: onError fires for all substates in parallel
  - #4456: Raised events in parallel don't propagate to siblings
  - #335: Data not passed to parent onDone for parallel
  - #1508: Entry action for state with eventless transition called after next state
  - #4895: Race condition with parallel state snapshots

#### 3. MassTransit (C#, 7.7K stars)
- **GitHub**: https://github.com/MassTransit/MassTransit
- **Test location**: `tests/MassTransit.Tests/SagaStateMachineTests/` — 42 test files
- **Key files**:
  - `CompositeEvent_Specs.cs` — composite events (multiple events → one trigger, like parallel done)
  - `InMemoryDeadlock_Specs.cs` — deadlock detection
  - `Fault_Specs.cs`, `CatchFault_Specs.cs`, `FaultRescue_Specs.cs` — fault recovery
  - `Request_Specs.cs` — request/response chains
  - `Outbox_Specs.cs` — outbox pattern for reliable messaging
  - `Choir_Specs.cs` — multi-machine choreography
- **Relevance**: Distributed/async patterns, deadlock prevention, composite event (done) detection, fault correlation

#### 4. python-statemachine (Python, 1.2K stars)
- **GitHub**: https://github.com/fgmacedo/python-statemachine
- **Test location**: `tests/` — 53 test files, W3C SCXML conformance included
- **Key files**: `test_statechart_parallel.py`, `test_statechart_compound.py`, `test_statechart_history.py`, `test_statechart_delayed.py`, `test_statechart_eventless.py`, `test_rtc.py`, `test_mock_compatibility.py`
- **Relevance**: Closest architecture to EventMachine. One file per statechart feature.

#### Note: SCION
- **GitHub**: https://github.com/jbeard4/SCION
- Not a Tier 1 deep-dive target — its value is in W3C test mapping infrastructure
- Referenced in Workstream 2 as the approach model for mapping SCXML tests to executable assertions

### Tier 2 — Valuable for Specific Areas

| Implementation | Stars | Valuable For |
|---|---|---|
| **Spring Statemachine** (Java) | 1.7K | Persistence testing, region tests, reactive/async, `StateMachineTestPlanBuilder` DSL |
| **Squirrel** (Java) | 2.3K | Parallel tests, machine verification, SCXML import, performance benchmarks |
| **Boost.Statechart** (C++) | Boost | Compile-time validation, event deferral, triggering event access (history tests N/A — EventMachine has no history states) |
| **AASM** (Ruby) | 5.2K | **6 guard test files**, memory leak tests, persistence adapters, class reloading |
| **Sismic** (Python) | 159 | Property-based verification, design-by-contract, BDD, runtime verification via property statecharts |
| **Stateless** (.NET) | 6.1K | Async patterns, firing modes (immediate vs queued), synchronization context |
| **Erlang gen_statem** | OTP | Hot code upgrade, event postponement, multiple timeout types |
| **Apache Commons SCXML** (Java) | 65 | **6 tie-breaker test files**, W3C test runner, history+parallel fixtures |

### Tier 3 — Niche but Informative

| Implementation | Interesting Aspect |
|---|---|
| **QP/C** (C, 1.3K stars) | MC/DC coverage, trace-based testing |
| **Statig** (Rust, 765 stars) | Compile-time error tests, per-state storage |
| **USCXML** (C++, 114 stars) | Near-complete W3C conformance |
| **pytransitions** (Python, 6.5K stars) | Separate `test_parallel.py`, `test_async.py`, `test_threading.py` |

---

## Part B: Known Problems & Edge Cases

### 1. Basic State Machine Problems

| # | Problem | Risk | Existing EventMachine Coverage |
|---|---------|------|-------------------------------|
| 1.1 | **Transition conflict / priority resolution** — multiple guarded transitions on same event, first-match-wins semantics | High | Partial (TransitionsTest) |
| 1.2 | **Guard side effects** — guards that mutate context even when returning false | Medium | None |
| 1.3 | **Action execution order** — SCXML exit→transition→entry ordering in hierarchies | Medium | RootEntryExitTest (2 levels only) |
| 1.4 | **Self-transition vs internal transition** — external resets children, internal preserves | High | None apparent |
| 1.5 | **Unreachable / dead states** — states that can never be reached or escaped | Low | machine:validate-config (partial) |
| 1.6 | **Non-deterministic transitions** — overlapping guards, same event | Medium | None |
| 1.7 | **@always infinite loops** — all guards false, context-dependent guards | High | MaxTransitionDepthTest (basic only) |

### 2. Hierarchical (Nested) State Problems

| # | Problem | Risk | Existing Coverage |
|---|---------|------|-------------------|
| 2.1 | **Exit/entry ordering in 4+ level deep hierarchy** | Medium | 2-level tests only |
| 2.2 | **LCA calculation edge cases** — sibling, cousin, uncle, root-crossing transitions | Medium | HierarchyTest (partial) |
| 2.3 | **History states** (shallow/deep, parallel, default, first-visit) | N/A | Not implemented (future-proof) |
| 2.4 | **Initial state with @always chain** — transient initial state | Medium | InitialStateTest (basic) |
| 2.5 | **Parent vs child transition priority** — child wins, parent fallback | Medium | EventResolutionTest (partial) |

### 3. Parallel/Orthogonal State Problems (CRITICAL)

| # | Problem | Risk | Existing Coverage |
|---|---------|------|-------------------|
| 3.1 | **Race conditions between regions** — concurrent context mutation in dispatch mode | Critical | ParallelDispatchContextConflictTest (basic) |
| 3.2 | **Join semantics** — one region final, other stuck; late events to final region | High | Scenario 8 in spec (not implemented) |
| 3.3 | **Conflicting transitions across regions** — both regions try to exit parallel | High | Scenario 9 in spec (not implemented) |
| 3.4 | **Event broadcasting to ALL regions** — single event triggers transitions in all | High | Limited |
| 3.5 | **Done detection with nested compounds** — final state deeply nested in region | High | ConditionalOnDoneTest (basic) |
| 3.6 | **Failure propagation** — region A fails while region B still running | High | ParallelDispatchFailureTest (partial) |
| 3.7 | **Shared context mutation** — scalar last-writer-wins, array merge semantics | High | ParallelDispatchContextConflictTest (partial) |
| 3.8 | **Region processing order** — sync vs dispatch mode produce different results | Medium | None |
| 3.9 | **Deadlocks** — reentrant lock attempt from entry action raise() | High | None |
| 3.10 | **Memory consistency** — DB replication lag, write connection enforcement | Medium | None |
| 3.11 | **Region timeout races** — timeout fires just as region completes | High | ParallelDispatchRegionTimeoutTest (basic) |

### 4. Async/Concurrent Execution Problems

| # | Problem | Risk | Existing Coverage |
|---|---------|------|-------------------|
| 4.1 | **Event ordering** — raise() priority over send(), internal before external | Medium | EventProcessingOrderTest (basic) |
| 4.2 | **Lost events** — event during active processing, MachineAlreadyRunningException | High | None |
| 4.3 | **Stale state reads** — read replica, cached state | Medium | None |
| 4.4 | **Concurrent Machine::send()** — two events to same instance simultaneously | Critical | None |
| 4.5 | **Persistence race conditions** — read-modify-write without lock | High | MachineLockingTest (LocalQA) |
| 4.6 | **Lock TTL / dead lock cleanup** — worker crash leaves orphaned lock | High | ParallelDispatchLockInfraTest (partial) |
| 4.7 | **Event replay idempotency** — archive→restore round-trip fidelity | Medium | ArchiveLifecycleTest (basic) |
| 4.8 | **Async action completion after timeout** — child completes but parent already timed out | High | None |
| 4.9 | **Timer fire after state exit** — stale timer fires for exited state | Medium | E2E timer tests (basic) |

### 5. Communication Problems

| # | Problem | Risk | Existing Coverage |
|---|---------|------|-------------------|
| 5.1 | **Parent-child after timeout** — sendToParent to timed-out parent | High | None |
| 5.2 | **Cross-machine to archived machine** — dispatchTo triggers auto-restore | Medium | AutoRestoreTest (basic) |
| 5.3 | **Fire-and-forget reliability** — child fails silently, MachineChild tracking | Medium | FireAndForgetMachineDelegationTest |
| 5.4 | **Circular communication** — A→B→A infinite loop detection | Medium | None |
| 5.5 | **Event delivery ordering** — FIFO guarantee under retries | Low | None |

### 6. Persistence & Recovery Problems

| # | Problem | Risk | Existing Coverage |
|---|---------|------|-------------------|
| 6.1 | **Restore correctness** — round-trip from event history | Medium | PersistenceTest |
| 6.2 | **Context serialization edge cases** — PHP_INT_MAX, deep nesting, null vs missing | Medium | None |
| 6.3 | **Event sourcing consistency** — orphaned events, duplicate events | Medium | EventStoreTest (basic) |
| 6.4 | **Schema migration** — restore machine when definition changed | Low | None |
| 6.5 | **Archive integrity** — corrupted archive, partial archive | Medium | ArchiveEdgeCasesTest (basic) |

### 7. Timer & Scheduling Problems

| # | Problem | Risk | Existing Coverage |
|---|---------|------|-------------------|
| 7.1 | **Timer accuracy and drift** | Low | E2E timer tests |
| 7.2 | **Overlapping timer fires** — dedup via machine_timer_fires | Medium | TimerEdgeCasesTest |
| 7.3 | **Timer cleanup on state exit** | Medium | TimerTest (basic) |
| 7.4 | **Recurring timer (every) edge cases** — max count, cancelled by event | Medium | E2E TimerEveryTest |
| 7.5 | **Schedule fire during event processing** | Medium | ScheduledEventsTest (basic) |

---

## Part C: Prioritized Testing Gaps

### Critical (Must-Add)

| # | Gap | Source Inspiration | Test Type | Description |
|---|-----|-------------------|-----------|-------------|
| C1 | Concurrent Machine::send() to same instance | MassTransit deadlock tests | LocalQA | Two processes send events simultaneously; verify locking prevents corruption |
| C2 | Both parallel regions fail simultaneously | XState #427, Spec Scenario 9 | Feature | Double-guard in ParallelRegionJob prevents double @fail transition |
| C3 | Region that never completes (stuck machine) | Spec Scenario 8 | Feature + LocalQA | One region stalls, verify @timeout fires, machine doesn't hang |
| C4 | Machine::send() during parallel execution | Spec Scenario 10 | Feature + LocalQA | External event while regions are running |
| C5 | Parallel done detection with nested compound states | XState #1341, #2349 | Feature | `areAllRegionsFinal()` with deeply nested final states |

### High Priority

| # | Gap | Source Inspiration | Test Type | Description |
|---|-----|-------------------|-----------|-------------|
| H1 | Self-transition vs internal transition with children | XState #1885, #131, #1118 | Feature | External resets child to initial, internal preserves child state |
| H2 | Event broadcasting to all parallel regions | W3C SCXML, XState parallel.test.ts | Feature | Single event triggers transitions in ALL active regions |
| H3 | Shared context scalar conflict (last-writer-wins) | XState #4895 | Feature + LocalQA | Two regions write same key, verify deterministic outcome |
| H4 | @always chain with all guards false | XState eventless docs | Feature | Verify max_transition_depth catches it; clear error message |
| H5 | Entry/exit action ordering in 4+ level hierarchy | W3C SCXML, XState actions.test.ts | Feature | Logging actions at every level, verify SCXML ordering |
| H6 | Failure propagation: region A fails while B still running | MassTransit fault specs | Feature | Verify late-arriving region B results are discarded |
| H7 | Transition conflict: multiple guards true on same event | Apache Commons tie-breaker tests | Feature | First-match wins; verify order-dependent behavior |
| H8 | Async child completion after parent timeout | MassTransit request specs | Feature | simulateChildDone after parent @timeout; verify ignored |
| H9 | Region timeout race with completion | MassTransit composite events | Feature | Timeout fires just as region completes; lock decides winner |
| H10 | Lock TTL expiry / orphaned lock cleanup | MassTransit deadlock tests | LocalQA | Simulate worker crash; verify lock cleaned up after TTL |

### Medium Priority

| # | Gap | Source Inspiration | Test Type | Description |
|---|-----|-------------------|-----------|-------------|
| M1 | Guard side effects detection | Sismic design-by-contract | Feature | Guard returns false but mutated context; verify context unchanged |
| M2 | Parent vs child transition priority | W3C SCXML, Apache Commons | Feature | Same event at parent and child; verify child wins |
| M3 | raise() events processed before next external event | W3C SCXML, XState | Feature | Internal queue priority verification |
| M4 | Context serialization edge cases | python-statemachine | Feature | PHP_INT_MAX, float precision, deep nesting, null vs missing |
| M5 | Circular cross-machine communication | MassTransit choreography | Feature | A→B→A loop detection or depth limiting |
| M6 | Region processing order: sync vs dispatch | pytransitions test_parallel | Feature | Same config, both modes, verify identical final state |
| M7 | Timer fire after state exit | Erlang gen_statem | Feature | State changed, timer fires, verify no-op |
| M8 | Archive/restore round-trip fidelity | XState rehydration | Feature | Archive→restore→verify identical state and context |
| M9 | Initial state with @always chain | XState transient.test.ts | Feature | Entering compound triggers @always; verify single macrostep |
| M10 | LCA calculation for deep cousin transitions | W3C SCXML | Feature | Transition between deeply nested cousins; verify correct exit/entry set |

### Low Priority (Future Hardening)

| # | Gap | Source Inspiration | Test Type |
|---|-----|-------------------|-----------|
| L1 | Unreachable state detection in validate-config | Squirrel machine verification | Feature |
| L2 | Schema migration — restore with changed definition | python-statemachine | Feature |
| L3 | Event delivery ordering under retries | MassTransit outbox | LocalQA |
| L4 | Cross-machine sendTo to archived machine | EventMachine auto-restore | Feature |
| L5 | Memory leak in long-running machine instances | AASM memory tests | Feature |
| L6 | Event deduplication on concurrent send | MassTransit correlation | LocalQA |

---

## Part D: Notable Testing Patterns to Adopt

### 1. Action-Ordering Witnesses (from XState, W3C)
Create actions that append to a shared log array: `['exit:A', 'transition:A→B', 'entry:B']`. Verify the log matches expected SCXML ordering. This makes entry/exit ordering bugs visible.

### 2. Trace-Based Testing (from QP/C)
Record the full sequence of state transitions and verify the trace matches expectations. More powerful than checking only the final state.

### 3. Composite Event Testing (from MassTransit)
Test that N events across M parallel regions correctly combine to trigger @done. Verify partial completion (only some regions final) does NOT trigger @done.

### 4. Tie-Breaker Test Fixtures (from Apache Commons SCXML)
Create 6+ test machines that exercise transition priority edge cases: document order, guarded vs unguarded, parent vs child, internal vs external.

### 5. Mock Compatibility Testing (from python-statemachine)
Verify `Machine::fake()`, `CommunicationRecorder`, `InlineBehaviorFake` work correctly with all edge cases. Test the testability layer itself.

### 6. Property-Based Invariants (from Sismic)
For each test machine, verify:
- Reachability: every non-final state can reach a final state
- Determinism: at most one transition fires per (state, event) pair
- Safety: machine never enters invalid state combination
- Liveness: no infinite @always loops

---

## Part E: Existing Test Coverage Summary

**429 total test files** (including stubs):
- **~140 Feature tests** — core behavior, parallel states (42 files!), testability, timers, routing
- **~15 Integration tests** — archive, persistence, context, event store
- **~15 Definition tests** — hierarchy, transitions, machine definition, max depth
- **~12 Behavior tests** — actions, guards, calculators, events, DI
- **~8 E2E tests** — parallel dispatch, timers, schedules, infinite loop, forward endpoints
- **~20 LocalQA tests** — async delegation, cross-machine, parallel dispatch, locking, timers
- **~12 Routing tests** — endpoints, forwarding, controller
- **4 Command tests** — archive, xstate export, config validation

**Strong coverage areas**: Parallel dispatch (42 test files), testability, routing, timers, archive
**Weak coverage areas**: Hierarchical depth (only 2-level), self-transition semantics, guard edge cases, concurrent access, cross-machine communication edge cases

---

## Part F: Research Sources

### State Machine Implementation Test Suites
- Full survey of 20+ implementations: `research/state-machine-test-suites.md`

### Problem & Edge Case Research
- Detailed 50+ problems with scenarios: `spec/hardened-testing-research.md`

### Key External References
- [W3C SCXML Specification](https://www.w3.org/TR/scxml/)
- [W3C SCXML Conformance Tests](https://www.w3.org/Voice/2013/scxml-irp/)
- [XState Issue Tracker](https://github.com/statelyai/xstate/issues) — bugs #1885, #400, #1829, #2349, #753, #427, #4456, #335, #1508, #4895
- [MassTransit Saga Tests](https://github.com/MassTransit/MassTransit/tree/develop/tests/MassTransit.Tests/SagaStateMachineTests)
- [Apache Commons SCXML Tie-Breaker Tests](https://github.com/apache/commons-scxml)
- [Boost.Statechart Tests](https://github.com/boostorg/statechart/tree/develop/test) — event deferral, triggering event, compile-time validation
- [Harel's Original Statecharts Paper](https://www.state-machine.com/doc/Harel87.pdf)
- [XState Eventless Transitions Docs](https://stately.ai/docs/eventless-transitions)
- [python-statemachine W3C Conformance](https://github.com/fgmacedo/python-statemachine)
