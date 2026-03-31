# Scenario Runtime

Environment gating, async propagation, engine feature interactions, and error handling for scenarios at runtime.

## Environment Gating

Scenarios are disabled by default. Enable in staging only:

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

Scenario overrides live in the Laravel container — process-scoped. When async jobs (parallel regions, queued listeners, child completion) run in separate processes, the scenario must propagate.

**Solution:** `machine_current_states.scenario_class` and `scenario_params` columns. Written during scenario activation, read during `Machine::create()` restoration when `scenarios.enabled=true`. The restored job registers scenario overrides in its own container, then processes its event normally.

### Lifecycle

1. Machine running normally → `scenario_class = null`
2. QA sends event with `scenario` → `scenario_class = 'AtReviewScenario'`
3. Async jobs restore machine → find `scenario_class` → hydrate → register overrides
4. QA sends next event WITHOUT scenario → `scenario_class = null` → real behavior resumes
5. QA sends next event with DIFFERENT scenario → new overrides replace old

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
| **should_persist** | `scenario_class` column only written when `should_persist=true` |
| **Entry/exit actions** | Execute normally — overrides apply same as transition actions |
| **Event bubbling** | Override key must match state route where transition is **defined** |
| **raise() / sendTo()** | Work normally — scenarios scoped to single machine instance |
| **computedContext()** | Not overridable — override actions that populate dependent context fields |
| **Lock contention** | Existing lock handling — POST returns 423 Locked |
| **Machine::query()** | Filter by `scenario_class` column on `machine_current_states` |
| **Transient as target** | Invalid — `$target` must be a settleable state |
| **Path coverage** | Scenario-driven paths recorded normally by `PathCoverageTracker` |
| **Fire-and-forget** | Dispatches suppressed during scenario mode |
| **Parallel as target** | Target `'payment_verification'` matches child routes like `payment_verification.payment.completed` — segment containment check |
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

## ScenarioPlayer Static API

| Method | Purpose |
|--------|---------|
| `ScenarioPlayer::isActive()` | Returns `true` during scenario execution — engine checks this to suppress fire-and-forget dispatches |
| `ScenarioPlayer::getOutcome(string $stateRoute)` | Returns delegation outcome for a state (used by engine during delegation interception) |
| `ScenarioPlayer::getChildScenario(string $stateRoute)` | Returns child scenario class for nested delegation |
| `ScenarioPlayer::cleanupOverrides()` | Unbinds all container overrides, clears outcomes/childScenarios, resets inline fakes. Runs in `finally` block after every `execute()` |
| `ScenarioPlayer::deactivateScenario(string $rootEventId)` | Clears `scenario_class`/`scenario_params` columns in `machine_current_states` |

## Testing

The `InteractsWithMachines` trait auto-resets scenario state after each test:

- `ScenarioPlayer::cleanupOverrides()` — unbinds overrides, clears static state
- `ScenarioDiscovery::resetCache()` — clears discovery cache

No manual cleanup needed in tests that use `InteractsWithMachines`.
