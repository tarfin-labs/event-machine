# Scenario Runtime

Environment gating, async propagation, engine feature interactions, and error handling for scenarios at runtime.

## Environment Gating

Scenarios are disabled by default. Enable in staging only:

<!-- doctest-attr: ignore -->
```php
// config/machine.php
'scenarios' => [
    'enabled' => env('MACHINE_SCENARIOS_ENABLED', false),
],
```

| Gating point | Behavior when disabled |
|-------------|----------------------|
| `ScenarioPlayer` | Throws `ScenariosDisabledException` |
| `MachineController::buildResponse()` | `availableScenarios` field omitted from response |
| `MachineController::maybeRegisterScenarioOverrides()` | Returns `null` immediately — `scenario` field in request silently ignored |
| `Machine::create()` restoration | `scenario_class` column not read |

Zero overhead in production.

## Async Propagation

Scenario context is process-scoped — it lives in three pieces of static state inside `ScenarioPlayer`:

1. `self::$outcomes` and `self::$childScenarios` — the classified plan, read by `getOutcome()` and `getChildScenario()` during delegation interception
2. Behavior overrides — bound in the Laravel container, read by `InvokableBehaviorFake` when guards/actions/calculators run
3. `self::$isActive` — gates the interception in `MachineDefinition::handleMachineInvoke`

When work crosses a process boundary (queue dispatch, parallel region, child completion job), all three pieces must be re-established in the destination process. The scenario class travels via the `machine_current_states.scenario_class` column or job constructor payloads, depending on the path.

**Three async paths, three activation routines:**

| Path | Trigger | Activation | Source of scenario class |
|------|---------|------------|---------------------------|
| Existing-machine restoration | `Machine::create(state: $rootEventId)` from any worker | `restoreStateFromRootEventId` §9 block | `machine_current_states.scenario_class` row |
| Fresh async child boot (9.10.3+) | `ChildMachineJob::handle()` for `'queue:'` parent state | `ScenarioPlayer::activateForAsyncBoot()` in `try`, `deactivate()` in `finally` | `ChildMachineJob::$scenarioClass` payload (passed by `MachineDefinition` dispatch site) |
| Sync child scenario reference | Parent transitions into a state with a child scenario reference in its plan | `ScenarioPlayer::executeChildScenario()` in-process | Resolved from parent's plan; child runs without persistence and synthesizes `@done` if it reaches a final state |

All three paths converge on the same trio (outcomes + overrides + isActive). Without this, leaf-state delegation outcomes silently fail and the child runs full I/O.

### Lifecycle

1. Machine running normally → `scenario_class = null`
2. QA sends event with `scenario` → `scenario_class = 'AtReviewScenario'`
3. Async jobs restore machine → find `scenario_class` → hydrate → activate scenario context
4. Child machine dispatched async (9.10.3+) → `ChildMachineJob` carries `scenarioClass` → worker activates scenario context for child boot, persists `scenario_class` on child row
5. QA sends next event WITHOUT scenario → `scenario_class = null` → real behavior resumes
6. QA sends next event with DIFFERENT scenario → new context replaces old

**With continuation:** After step 2, if the scenario has `continuation()`, the `scenario_class` persists across requests. Step 5 changes: instead of clearing the scenario, the controller detects `hasContinuation() === true` and dispatches `executeContinuation()` with Phase 2 overrides. The scenario is only cleared when the machine reaches a final state or QA explicitly switches scenarios.

## Engine Feature Reference

| Feature | Scenario interaction |
|---------|---------------------|
| **Timers (after/every)** | No special handling — scenario replay is synchronous, timers never fire |
| **Scheduled events** | No special handling — replay is synchronous |
| **Queued listeners** | Overrides propagate via `scenario_class` in DB |
| **ValidationGuardBehavior** | Override return value determines pass/fail |
| **Machine locking** | No change — existing lock semantics apply |
| **Machine::fake()** | Incompatible — `ScenarioConfigurationException` if machine is faked |
| **Event history** | Real event history created — machine indistinguishable from organic |
| **Event archival** | Transparent — no special handling |
| **should_persist** | `scenario_class` column only written when `should_persist=true`. When false (unit tests), scenarios work but async propagation is unavailable. |
| **Entry/exit actions** | Execute normally — overrides apply same as transition actions |
| **Event bubbling** | Override key must match the state route where the transition is **defined**, not where it's inherited from. If a parent state defines a transition and a child state inherits it, the override key must use the parent's route. |
| **raise() / sendTo()** | Work normally — scenarios scoped to single machine instance |
| **computedContext()** | Not overridable — override actions that populate dependent context fields |
| **Lock contention** | Existing lock handling — POST returns 423 Locked |
| **Machine::query()** | Filter by `scenario_class` column on `machine_current_states` |
| **Transient as target** | Invalid — `$target` must be a settleable state |
| **Path coverage** | Scenario-driven paths recorded normally by `PathCoverageTracker` |
| **Fire-and-forget** | Dispatches suppressed during scenario mode |
| **Parallel as target** | When the target is a parallel state (e.g., `'payment_verification'`), validation uses segment containment: if any current route contains `'.payment_verification.'` as a segment, it matches. This means `$target = 'payment_verification'` succeeds when the machine is at `order.payment_verification.payment.completed`. |
| **`@start` event** | Creates fresh machine and processes `@always` chain — for transient initial states |

## Error Handling

| Exception | When |
|-----------|------|
| `ScenariosDisabledException` | `MACHINE_SCENARIOS_ENABLED=false` |
| `ScenarioConfigurationException` | Invalid state route, delegation outcome on non-delegation state, missing properties, invalid params, machine is faked |
| `ScenarioFailedException` | Guard rejection during replay, `@continue` event rejected, source mismatch (controller), event mismatch (controller) |
| `ScenarioTargetMismatchException` | Machine did not reach `$target` after execution |
| `NoScenarioPathFoundException` | Scaffold command: no path from source to target |
| `AmbiguousScenarioPathException` | Scaffold command: multiple paths exist |

When a `MissingMachineContextException` is thrown during replay, it is enriched with hints from `$requiredContext` properties on guards and entry actions at the current state:

```
`customer` is missing in context.

Hint: The following behaviors at state 'eligibility_check' require context keys:
  - IsEligibleGuard (guard): requires userId (int)
  - StoreApplicationAction (entry action): requires applicationId (string)
Add context overrides in plan() for the relevant state.
```

## Debugging Scenarios

### Validation Tiers

Scenario issues can be caught at different levels. Work through these tiers in order — each catches a different class of bug:

| Tier | Command / Pattern | Catches |
|------|-------------------|---------|
| 1. Structural | `machine:scenario-validate` | Source/event/target mismatch, non-existent state routes, path existence via BFS |
| 2. Path enumeration | `machine:paths <Machine>` | Verify override states are on reachable paths under intended guard conditions |
| 3. Unit | `(new ScenarioPlayer(...))->execute()` in a test | Typed injection failures (`TypeError`), unexpected action side-effects, guard/action interactions |
| 4. Integration | Full HTTP endpoint hit with `?scenario=slug` | End-to-end slug activation, context flow, response shape, real async behavior |

Tier 1 catches most structural problems. Tier 3 catches the remaining runtime issues (typed `MachineFailure` injection, I/O fallbacks in actions) that structural validation cannot detect. If you skip tier 3 and go straight to tier 4, debugging is harder because errors are obscured by HTTP response formatting and async queue timing.

### Step-by-Step

When a scenario fails or produces unexpected state:

1. **Check validation first:** `php artisan machine:scenario-validate --scenario=at-review-scenario` catches structural errors (wrong state routes, missing paths, event mismatches).

2. **Read the exception message:** `MissingMachineContextException` is enriched with `$requiredContext` hints from guards and entry actions. `ScenarioTargetMismatchException` shows expected vs actual state.

3. **Inspect `machine_events`:** Scenario execution produces real event history. Query the last events for the `root_event_id` to see which transitions fired and where the machine stopped.

4. **Check `machine_current_states`:** The `scenario_class` column shows if a scenario is still active. If it's `null` when you expect it to be set, the deactivation flow may have cleared it.

5. **Preview with `--dry-run`:** `php artisan machine:scenario AtReview OrderMachine pending SubmitEvent under_review --dry-run` shows the scaffolded plan without writing files — useful for understanding what path the BFS found.

6. **Async child runs full I/O instead of applying the scenario:** the dispatched child machine (parent state with `'queue:'`) made real external calls despite the scenario plan referencing it. Check three things:
   - **Package version >= 9.10.3** — earlier versions silently dropped child scenarios at async dispatch time
   - **`config('machine.scenarios.enabled')`** is `true` in the worker's environment (workers may load a different config than HTTP requests)
   - **`MachineCurrentState.scenario_class`** for the dispatched child is set to your scenario class — if `null`, the dispatch site couldn't resolve the active child scenario for that state route (verify the plan key matches the parent state ID, including any machine prefix)


### Verifying Scenario Interception via `machine_events`

After running a scenario, query `machine_events` for the `root_event_id` and check `child.*.start` / `child.*.done` timestamps:

```sql
SELECT type, created_at FROM machine_events
WHERE root_event_id = '<root_event_id>'
  AND type LIKE '%child.%'
ORDER BY created_at;
```

**Same-second timestamps** confirm synchronous scenario interception — no queue dispatch, no real I/O:

```
00:18:20 | car_sales.child.FindeksMachine.start
00:18:20 | car_sales.child.FindeksMachine.done   ← same second = intercepted
```

**A gap between start and done** proves the scenario did NOT intercept and a real delegation fired:

```
00:09:37 | car_sales.child.FindeksMachine.start
00:25:07 | car_sales.child.FindeksMachine.done   ← 15 min gap = real dispatch
```

In integration tests, assert this directly:

<!-- doctest-attr: ignore -->
```php
$start = MachineEvent::where('root_event_id', $rootId)
    ->where('type', 'like', '%child.%.start')->first();
$done = MachineEvent::where('root_event_id', $rootId)
    ->where('type', 'like', '%child.%.done')->first();

expect($done->created_at->diffInSeconds($start->created_at))->toBe(0);
```

This check should be in every scenario integration test — it is the most reliable way to confirm that the scenario actually intercepted the delegation instead of letting it run for real.

## Continuation Execution

`ScenarioPlayer::executeContinuation()` handles Phase 2 of multi-request scenarios. It differs from `execute()` in several ways:

| Aspect | `execute()` | `executeContinuation()` |
|--------|-------------|------------------------|
| Overrides source | `plan()` | `continuation()` |
| Source validation | Validates machine is at `$source` | No source validation |
| Target validation | Validates machine reached `$target` | No target validation |
| Event type | Must match scenario's `$event` | Accepts any event (whatever QA sends) |
| Final state | N/A (target is non-final) | Auto-deactivates scenario |
| Interactive state | N/A | Keeps scenario active for next request |

**Override lifecycle across phases:**
1. Phase 1: `registerOverrides(plan())` → machine reaches target → `cleanupOverrides()` → DB retains `scenario_class`
2. Phase 2: `registerOverrides(continuation())` → machine advances → `cleanupOverrides()` → DB cleared if final state

Phase 1 overrides never leak into Phase 2. Each phase gets fresh overrides from its respective method.

The `$isContinuation` flag on `MachineScenario` is set by `MachineController` when it restores an active scenario from the database and detects `hasContinuation() === true`. The controller uses this flag to dispatch to `executeContinuation()` instead of `execute()`.

## ScenarioPlayer Static API

| Method | Purpose |
|--------|---------|
| `ScenarioPlayer::isActive()` | Returns `true` during scenario execution — engine checks this to suppress fire-and-forget dispatches |
| `ScenarioPlayer::getOutcome(string $stateRoute)` | Returns delegation outcome for a state (used by engine during delegation interception) |
| `ScenarioPlayer::getChildScenario(string $stateRoute)` | Returns child scenario class for nested delegation |
| `ScenarioPlayer::executeChildScenario(...)` | Runs a child scenario synchronously. Includes `@continue` loop, delegation outcome interception, parent outcome save/restore. When child pauses at interactive state: persists child to DB, creates `machine_children` record, persists `scenario_class` for continuation. Parent context passed via `resolveChildContext()` |
| `ScenarioPlayer::cleanupOverrides()` | Unbinds all container overrides, clears outcomes/childScenarios, resets inline fakes. Runs in `finally` block after every `execute()` |
| `ScenarioPlayer::deactivateScenario(string $rootEventId)` | Clears `scenario_class`/`scenario_params` columns in `machine_current_states` |
| `ScenarioPlayer::executeContinuation(...)` | Phase 2 execution — applies `continuation()` overrides, sends QA's event, deactivates on final state |

## Testing

The `InteractsWithMachines` trait auto-resets scenario state after each test:

- `ScenarioPlayer::cleanupOverrides()` — unbinds overrides, clears static state
- `ScenarioDiscovery::resetCache()` — clears discovery cache

No manual cleanup needed in tests that use `InteractsWithMachines`.

**Unit test example — verify scenario reaches target:**

<!-- doctest-attr: ignore -->
```php
test('at-review scenario reaches under_review', function (): void {
    config()->set('machine.scenarios.enabled', true);

    $machine = OrderMachine::create(context: ['orderId' => 1]);
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $scenario = new AtReviewScenario();
    $player   = new ScenarioPlayer($scenario);
    $state    = $player->execute(machine: $machine, rootEventId: $rootEventId);

    expect($state->value)->toContain('order.under_review');
});
```

**Validating scenarios in CI:**

```bash
php artisan machine:scenario-validate --ansi
# Exit code 1 if any scenario is structurally invalid
```
