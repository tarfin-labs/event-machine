# Machine Path Coverage Analysis — Implementation Plan

## Context

We manually enumerated 15 terminal paths for FindeksMachine by reading the definition line by line. This is error-prone, unverifiable, and unmaintainable. This feature adds automated path enumeration (static analysis), test-time path tracking, coverage assertions, and a coverage report command.

## File Structure

```
src/Analysis/
    PathType.php                — Enum: HAPPY, FAIL, TIMEOUT, LOOP, GUARD_BLOCK, DEAD_END
    PathStep.php                — VO: single step in a path (state, event, guards, actions, invoke type)
    MachinePath.php             — VO: ordered list of PathSteps with classification + signature()
    ParallelPathGroup.php       — VO: per-region path lists for parallel states
    PathEnumerationResult.php   — VO: all paths + parallel groups + derived stats
    PathEnumerator.php          — Core DFS algorithm over MachineDefinition graph
    PathCoverageTracker.php     — Static tracker accumulating state+event sequences during tests
    PathCoverageReport.php      — Coverage calculation: enumerated paths vs observed paths
src/Commands/
    MachinePathsCommand.php     — `machine:paths` artisan command (Layer 1)
    MachineCoverageCommand.php  — `machine:coverage` artisan command (Layer 4)
tests/Analysis/
    PathEnumeratorTest.php      — Tests using existing stubs
    PathCoverageTrackerTest.php — Tracker integration tests
    PathCoverageAssertionTest.php — assertAllPathsCovered / assertPathCoverage tests
```

---

## Phase 1: Foundation (Layer 1 — Path Enumeration)

### 1.1 `PathType` enum (`src/Analysis/PathType.php`)
```php
enum PathType: string {
    case HAPPY       = 'happy';       // reached a top-level FINAL state without @fail or timer
    case FAIL        = 'fail';        // path contains an @fail step
    case TIMEOUT     = 'timeout';     // path contains a timer-triggered step (after/every) or @timeout
    case LOOP        = 'loop';        // cycle detected — path revisits a state
    case GUARD_BLOCK = 'guard_block'; // all guards fail with no fallback — event swallowed, stays in state
    case DEAD_END    = 'dead_end';    // ATOMIC state with no transitions and not FINAL
}
```

### 1.2 `PathStep` VO (`src/Analysis/PathStep.php`)
Readonly properties: `stateId`, `stateKey`, `event` (null for initial), `branchIndex`, `guards` (string[]), `actions` (string[]), `timerType` ('after'/'every'/null), `invokeType` ('@done'/'@fail'/'@timeout'/'@done.{state}'/null).

### 1.3 `MachinePath` VO (`src/Analysis/MachinePath.php`)
- `steps: list<PathStep>`, `type: PathType`, `terminalStateId: ?string`
- `signature(): string` — deterministic key for coverage matching, e.g. `"idle→[@always]→done"`
- `stateIds(): array`, `guardNames(): array`, `actionNames(): array`

### 1.4 `ParallelPathGroup` VO (`src/Analysis/ParallelPathGroup.php`)
- `parallelStateId`, `regionPaths: array<string, list<MachinePath>>` (keyed by region key)
- `combinationCount(): int` — product of per-region path counts

### 1.5 `PathEnumerationResult` VO (`src/Analysis/PathEnumerationResult.php`)
- Contains `paths`, `parallelGroups`
- Filter methods: `happyPaths()`, `failPaths()`, `timeoutPaths()`, `loopPaths()`, `guardBlockPaths()`, `deadEndPaths()`
- Stat methods derived from `MachineDefinition::idMap` and behavior arrays:

| Stat | Source |
|---|---|
| States (atomic / compound / parallel / final) | `idMap` by `StateDefinitionType` |
| Events | `collectUniqueEvents()` — user-sendable event count |
| Guards | unique guard keys across all `TransitionBranch::guards` |
| Actions | unique action keys across entry/exit + `TransitionBranch::actions` |
| Calculators | unique calculator keys across `TransitionBranch::calculators` |
| Job actors | states where `machineInvokeDefinition->isJob()` |
| Child machines | states where `hasMachineInvoke() && !isJob()` |
| Timers | transitions where `timerDefinition !== null` (after/every breakdown) |
| Parallel regions | PARALLEL states' child count |
| Terminal paths | enumerated path count |

- Convenience: `MachineDefinition::enumeratePaths(): PathEnumerationResult` (thin wrapper)

### 1.6 `PathEnumerator` service (`src/Analysis/PathEnumerator.php`)

DFS with backtracking (all simple paths enumeration). Constructor takes `MachineDefinition`. DFS starts from `definition.initialStateDefinition`.

```php
public function enumerate(): PathEnumerationResult

private function dfs(StateDefinition $state, array $steps, array $visitedIds): void
{
    // 1. Cycle detection — very first check
    if (isset($visitedIds[$state->id])) {
        $this->recordPath($steps, PathType::LOOP);
        return;
    }
    $visitedIds[$state->id] = true;  // mark visited for THIS fork

    // 2. Dispatch by state type
    match ($state->type) {
        FINAL    => $this->handleFinal($state, $steps, $visitedIds),
        PARALLEL => $this->handleParallel($state, $steps, $visitedIds),
        COMPOUND => $this->dfs($state->findInitialStateDefinition(), $steps, $visitedIds),
        ATOMIC   => $this->handleAtomic($state, $steps, $visitedIds),
    };
    // PHP arrays are pass-by-value — $visitedIds is automatically copied
    // on each recursive call. No explicit backtracking (unset) needed.
}
```

Complexity: `O(P × V)` where P = total paths, V = state count. In practice P is small (10-50) and V is small (5-30).

#### handleFinal — Compound @done Continuation

At runtime, `processCompoundOnDone()` fires when a FINAL child state is reached inside a compound parent. The DFS replicates this:

```
When DFS reaches a FINAL state:
  1. Check finalState->parent
  2. If parent is COMPOUND (not PARALLEL) and parent->onDoneTransition exists:
     - Add the FINAL state as a step
     - Follow parent->onDoneTransition branches (may be guarded → fork per branch)
     - Continue DFS from each branch target
  3. If no compound @done: record as terminal path
```

Note: compound @done does NOT propagate to grandparent compounds (matches runtime). Only the immediate compound parent is checked.

#### handleParallel — Parallel @done Continuation

When the DFS reaches a PARALLEL state:
1. Enumerate per-region paths (stored in `ParallelPathGroup` for display).
2. For the outer path: treat the parallel state as a single step, then follow:
   - `onDoneTransition` / guarded `@done` branches — for the "all regions complete" path
   - `onFailTransition` — for the "a region failed" path
3. Continue DFS from each @done/@fail target.

#### handleAtomic — Transition Collection and Enumeration

**Step 1: Collect all transitions (event hierarchy)**

At runtime, `findTransitionDefinition()` walks up the parent chain when no transition is found on the current state. The enumerator replicates this:

```php
private function collectAllTransitions(StateDefinition $state): array
{
    // 1. Start with the state's own transitionDefinitions
    // 2. Walk up state->parent chain (until order === 0)
    // 3. At each ancestor, add transitions for events NOT already seen
    // This mirrors findTransitionDefinition() bubbling behavior
}
```

Also collect machine invoke transitions (`@done`, `@fail`, `@timeout`, `@done.{state}`) from the state itself — these don't bubble.

**Step 2: Dead-end detection**

If the state has zero transitions after collection (including inherited) AND is not FINAL → record as DEAD_END path and return.

**Step 3: @always priority**

At runtime, `processPostEntryTransitions()` tries `@always` immediately after entering a state. If the guard passes, the transition fires — no other events are reachable. If the guard fails, `transition()` returns silently and the machine waits for user events.

1. **Unguarded @always**: follow exclusively — skip all other transitions (unreachable).
2. **Guarded @always**: fork into:
   - **Guard-pass fork(s)**: for each branch with a target, create a path fork.
   - **Guard-fail continuation**: enumerate remaining non-@always transitions. If none exist → GUARD_BLOCK.
3. **No @always**: enumerate all transitions normally.

**Step 4: Enumerate remaining transitions**

For each non-@always transition:

1. **Skip self-transitions**: if `target === null`, skip (runs actions but state doesn't change).
2. **Branch with target**: recurse to target, noting guards/actions/timerType.
3. **Multi-branch (guarded)**: each branch with a target is a separate path fork.
4. **GUARD_BLOCK (per-event)**: if every branch has `guards !== null` (no unguarded fallback), generate a GUARD_BLOCK path.
5. **Machine invoke** (`hasMachineInvoke()`) — enumerate **in addition to** regular transitions:
   - `onDoneStateTransitions`: each `@done.{state}` is a path fork
   - `onDoneTransition`: follow `@done` target (if guarded, each branch is a fork)
   - `onFailTransition`: follow `@fail` target (if guarded, each branch is a fork)
   - `onTimeoutTransition`: follow `@timeout` target
   - Fire-and-forget (`MachineInvokeDefinition::target !== null`): resolve target via `getNearestStateDefinitionByString()`, transition directly

Timer transitions (`timerDefinition !== null`) are regular events with `timerType` metadata ('after'/'every') — distinct from `@timeout` on machine invoke. Design choice: all timer-triggered paths are classified as TIMEOUT regardless of target state semantics.

#### Path Type Classification

Determined by scanning the **full path** (priority order):

| Rule | PathType |
|---|---|
| Path revisits a state (cycle) | `LOOP` |
| All guards fail, no fallback branch | `GUARD_BLOCK` |
| Any step has `invokeType === '@fail'` | `FAIL` |
| Any step has `timerType !== null` OR `invokeType === '@timeout'` | `TIMEOUT` |
| Terminal state is ATOMIC, no transitions, not FINAL | `DEAD_END` |
| Reached a FINAL state via none of the above | `HAPPY` |

#### Static Analysis Limitations

Entry actions can raise events at runtime (`raise()`), triggering transitions that static analysis cannot predict. This is an inherent limitation of static graph traversal.

### 1.7 Tests (`tests/Analysis/PathEnumeratorTest.php`)

Test against existing stubs:
- `AbcMachine` — initial=state_b, @always(unguarded)→state_c (no transitions, not FINAL): 1 DEAD_END path
- `GuardedMachine` — CHECK fallback branch→processed (DEAD_END); targetless branches and INCREASE self-transition skipped: 1 path
- `AlwaysGuardMachine` — @always guard-pass→done (HAPPY) + @always guard-fail→remaining: GO guard-pass→done (HAPPY) + GO guard-fail (GUARD_BLOCK): 3 paths
- `JobActorParentMachine` — @done→completed + @fail→failed: 2 paths (HAPPY + FAIL)
- `DoneDotParentMachine` — @done.approved + @done.rejected + @fail: 3 paths
- `ConditionalCompoundOnDoneMachine` — compound @done with guards: 2 paths (HAPPY)
- `ConditionalOnDoneMachine` — parallel @done with guards after region completion
- `AfterTimerMachine` — PAY→processing→completed (HAPPY) + ORDER_EXPIRED(timer)→cancelled (TIMEOUT): 2 paths
- `AlwaysLoopMachine` — idle→TRIGGER→loop_a→@always→loop_b→@always→loop_a (LOOP): 1 path
- `FireAndForgetTargetParentMachine` — fire-and-forget with target, then RETRY loop

---

## Phase 2: Command — `machine:paths`

### 2.1 `MachinePathsCommand` (`src/Commands/MachinePathsCommand.php`)

Signature: `machine:paths {machine} {--json}`

Copy `resolveClassFromFile()` from `ExportXStateCommand` as a private method. Calls `$machineClass::definition()` (no DB needed, pure static analysis).

#### Console output format

All paths use state-per-line format:

```
JobActorParentMachine — Path Analysis
═════════════════════════════════════

  States: 4 (2 atomic, 2 final)
  Events: 1
  Guards: 0
  Actions: 1
  Calculators: 0
  Job actors: 1
  Child machines: 0
  Timers: 0
  Terminal paths: 2

HAPPY PATHS (→ completed): 1 path
──────────────────────────────────
  #1  → idle
      → [START] processing (SuccessfulTestJob)
      → [@done] completed
      Actions: capturePaymentAction

FAIL PATHS (→ failed): 1 path
──────────────────────────────
  #2  → idle
      → [START] processing (SuccessfulTestJob)
      → [@fail] failed
```

Parallel state example:

```
ConditionalOnDoneMachine — Path Analysis
════════════════════════════════════════

  States: 7 (2 atomic, 3 final, 1 parallel)
  Events: 2
  Guards: 1
  Actions: 4
  Calculators: 0
  Job actors: 0
  Child machines: 0
  Timers: 0
  Terminal paths: 2
  Parallel regions: 1 (2 regions, 1×1 = 1 combination)

PARALLEL: processing (2 regions)
────────────────────────────────
  inventory region: 1 path
    → checking
    → [INVENTORY_CHECKED] done

  payment region: 1 path
    → validating
    → [PAYMENT_VALIDATED] done

HAPPY PATHS (→ approved): 1 path
────────────────────────────────
  #1  → processing (parallel)
      → [@done, IsAllSucceededGuard = pass] approved
      Actions: LogApprovalAction

HAPPY PATHS (→ manual_review): 1 path
─────────────────────────────────────
  #2  → processing (parallel)
      → [@done fallback] manual_review
      Actions: NotifyReviewerAction
```

Timer example:

```
AfterTimerMachine — Path Analysis
═════════════════════════════════

  States: 4 (2 atomic, 2 final)
  Events: 3
  Guards: 0
  Actions: 0
  Calculators: 0
  Job actors: 0
  Child machines: 0
  Timers: 1
  Terminal paths: 2

HAPPY PATHS (→ completed): 1 path
──────────────────────────────────
  #1  → awaiting_payment
      → [PAY] processing
      → [COMPLETE] completed

TIMEOUT PATHS (→ cancelled): 1 path
────────────────────────────────────
  #2  → awaiting_payment
      → [ORDER_EXPIRED] (after 7d) cancelled
```

#### JSON output (`--json`)

```json
{
  "machine": "JobActorParentMachine",
  "stats": {
    "states": 4,
    "atomic_states": 2,
    "final_states": 2,
    "events": 1,
    "guards": 0,
    "actions": 1,
    "calculators": 0,
    "job_actors": 1,
    "child_machines": 0,
    "timers": 0,
    "terminal_paths": 2
  },
  "paths": [
    {
      "id": 1,
      "type": "happy",
      "signature": "idle→[START]→processing→[@done]→completed",
      "steps": [
        {"state": "idle", "event": null},
        {"state": "processing", "event": "START"},
        {"state": "completed", "event": "@done", "invoke_type": "@done"}
      ],
      "terminal_state": "completed",
      "guards": [],
      "actions": ["capturePaymentAction"]
    }
  ]
}
```

### 2.2 Register in `MachineServiceProvider`

Add `->hasCommand(MachinePathsCommand::class)`

---

## Phase 3: Tracking (Layer 2 — Test-Time)

### 3.1 `PathCoverageTracker` (`src/Analysis/PathCoverageTracker.php`)

Static singleton (like `InlineBehaviorFake` in `src/Testing/`):

```php
class PathCoverageTracker
{
    private static bool $enabled = false;

    /** @var array<class-string, list<array{state: string, event: ?string}>> active path per machine */
    private static array $activePaths = [];

    /** @var array<class-string, list<array{signature: string, test: string, steps: list}>> completed paths */
    private static array $observedPaths = [];

    public static function enable(): void;
    public static function disable(): void;
    public static function isEnabled(): bool;
    public static function reset(): void;

    /** Append a (stateId, eventType) step to the active path */
    public static function recordTransition(string $machineClass, string $stateId, ?string $eventType): void;

    /** Move active path to observed, record test name */
    public static function completePath(string $machineClass): void;

    /** @return list<array{signature: string, test: string}> */
    public static function observedPaths(string $machineClass): array;

    public static function exportToFile(string $path): void;
    public static function importFromFile(string $path): void;
}
```

`completePath()` builds a signature from the active steps (same format as `MachinePath::signature()`) and records the current test name via `debug_backtrace()` or Pest's test name API.

### 3.2 How coverage matching works

The enumerator and tracker both produce signatures in the same format:

**Enumerator** (static analysis):
```
Path #1 signature: "idle→[@always]→done"
Path #2 signature: "idle→[GO]→done"
Path #3 signature: "idle→[GO]→stays"
```

**Tracker** (runtime, during tests):
```php
// Test: "always_guard_auto_transitions_to_done"
TestMachine::create(AlwaysGuardMachine::class, guards: ['isAllowedGuard' => true]);
// trackStateEntry → (idle, null)       ← initial
// trackStateEntry → (done, @always)    ← @always fired
// assertFinished() → completePath()
// Observed signature: "idle→[@always]→done", test: "always_guard_auto_transitions_to_done"
```

**Matching**: signature string equality.

```
Enumerated                Observed                              Covered?
──────────────────────────────────────────────────────────────────────────
#1 "idle→[@always]→done"  "idle→[@always]→done"                 ✓
#2 "idle→[GO]→done"       (no match)                            ✗
#3 "idle→[GO]→stays"      (no match)                            ✗
```

Coverage = 1/3 = 33.3%

### 3.3 TestMachine integration (`src/Testing/TestMachine.php`)

Three integration points:

```php
// 1. In trackStateEntry(), after line 1161 (inside the if-block where state changed):
if (PathCoverageTracker::isEnabled()) {
    PathCoverageTracker::recordTransition(
        machineClass: $this->machine->definition->machineClass ?? get_class($this->machine),
        stateId: $currentId,
        eventType: $this->machine->state->currentEventBehavior?->type,
    );
}

// 2. In assertFinished(), after the assertion passes:
if (PathCoverageTracker::isEnabled()) {
    PathCoverageTracker::completePath(
        $this->machine->definition->machineClass ?? get_class($this->machine),
    );
}

// 3. In assertState(), when matched state is FINAL:
if (PathCoverageTracker::isEnabled()) {
    $stateDefinition = $this->machine->definition->idMap[$expected] ?? null;
    if ($stateDefinition?->type === StateDefinitionType::FINAL) {
        PathCoverageTracker::completePath(
            $this->machine->definition->machineClass ?? get_class($this->machine),
        );
    }
}
```

### 3.4 Export timing

`PathCoverageTracker` writes accumulated data to JSON at test suite teardown:

```php
// Via Pest plugin, phpunit.xml afterSuite hook, or register_shutdown_function:
PathCoverageTracker::exportToFile(
    storage_path('framework/testing/machine-path-coverage.json')
);
```

### 3.5 Tests (`tests/Analysis/PathCoverageTrackerTest.php`)

---

## Phase 4: Assertions (Layer 3)

### 4.1 `PathCoverageReport` (`src/Analysis/PathCoverageReport.php`)
- Takes `PathEnumerationResult` + observed paths (from tracker)
- `coveredPaths(): array` — enumerated paths whose signature matches an observed signature
- `uncoveredPaths(): array` — enumerated paths with no matching observation
- `coveragePercentage(): float` — `count(covered) / count(all) * 100`
- `testedBy(MachinePath $path): array` — test names that covered this path

### 4.2 Static assertion methods on `Machine` (`src/Actor/Machine.php`)

```php
public static function assertAllPathsCovered(): void
public static function assertPathCoverage(float $minimum): void
```

Both call `PathEnumerator::enumerate()` on `static::definition()`, then cross-reference with `PathCoverageTracker::observedPaths()`. These read directly from the static tracker state — no JSON file needed (same process).

Usage:
```php
#[Test]
public function all_findeks_machine_paths_are_covered(): void
{
    FindeksMachine::assertAllPathsCovered();
    // Fails with:
    // "2 untested paths:
    //   #3  → idle → [GO] done
    //   #4  → idle → [GO] stays (GUARD_BLOCK)"
}
```

### 4.3 Tests (`tests/Analysis/PathCoverageAssertionTest.php`)

---

## Phase 5: Report Command — `machine:coverage`

### 5.1 `MachineCoverageCommand` (`src/Commands/MachineCoverageCommand.php`)

Signature: `machine:coverage {machine} {--json} {--min=} {--from=}`

The command does NOT run tests. It reads the coverage JSON that tests produced:

```bash
# Step 1: Tests run, tracker accumulates data, exports JSON at suite end
composer test

# Step 2: Command reads JSON, runs enumerator, matches, reports
php artisan machine:coverage JobActorParentMachine
```

`--from=` path to coverage JSON (default: `storage/framework/testing/machine-path-coverage.json`)
`--min=` minimum coverage percentage (exit code 1 if below — for CI gates)

#### Console output format

```
JobActorParentMachine — Path Coverage
═════════════════════════════════════

  Coverage: 1/2 paths (50.0%)

  ✓ #1  → idle → [START] processing → [@done] completed
         Tested by: job_actor_completes_successfully

  ✗ #2  → idle → [START] processing → [@fail] failed

UNTESTED: 1 path
  #2  → idle
      → [START] processing (SuccessfulTestJob)
      → [@fail] failed
```

Covered paths: one-line summary + "Tested by" with test name(s).
Untested paths: state-per-line detail at the bottom.

#### JSON output (`--json`)

```json
{
  "machine": "JobActorParentMachine",
  "total_paths": 2,
  "tested_paths": 1,
  "coverage": 50.0,
  "paths": [
    {"id": 1, "type": "happy", "signature": "...", "tested": true, "tests": ["job_actor_completes_successfully"]},
    {"id": 2, "type": "fail",  "signature": "...", "tested": false, "tests": []}
  ]
}
```

#### CI usage

```yaml
- run: composer test
- run: php artisan machine:coverage FindeksMachine --min=100
# exit code 1 if coverage < 100% → CI fails
```

### 5.2 Register in `MachineServiceProvider`

Add `->hasCommand(MachineCoverageCommand::class)`

### 5.3 Tests (`tests/Analysis/MachineCoverageCommandTest.php`)

---

## Verification

1. **Unit tests**: `composer test` — all new tests in `tests/Analysis/` pass
2. **Static analysis**: `composer larastan` passes at level 5
3. **Code style**: `composer pint && composer rector` clean
4. **Manual smoke test**: `php artisan machine:paths` against test stub machines
5. **Integration test**: Enable tracker in a test, run machine through paths, call `assertAllPathsCovered()`
