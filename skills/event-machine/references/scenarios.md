# Scenarios Cheat-Sheet (curated)

Synthesis of `docs/advanced/scenarios.md`, `docs/advanced/scenario-plan.md`, `docs/advanced/scenario-runtime.md`, `docs/advanced/scenario-behaviors.md`. Optimized for agent lookup.

## What scenarios are

QA-only tooling for **arriving at a target state with a fully functional machine**, by overriding behaviors / outcomes that would otherwise require slow real I/O, multi-step setup, or production credentials. Activated via HTTP endpoints in staging/QA environments. **Never enable in production.**

Activation gate: `config('machine.scenarios.enabled')` must be `true`. Off in production.

## Anatomy of a `MachineScenario`

```php
class AtAllocationUnderReviewScenario extends MachineScenario
{
    protected string $machine     = OrderMachine::class;
    protected string $source      = 'pending';
    protected string $event       = SubmitOrderEvent::class;
    protected string $target      = 'allocation.under_review';
    protected string $description = 'Order at under-review with all checks passed';

    protected function plan(): array
    {
        return [/* state route => override */];
    }
}
```

`plan()` keys are **state routes** (full machine-prefixed IDs). Values can be: behavior overrides, delegation outcomes, child scenario references, or `@continue` directives.

## plan() value forms

| Value | Meaning | Side effect |
|-------|---------|-------------|
| `[GuardClass::class => true]` | Guard returns true without running | Mocked guard |
| `[ActionClass::class => []]` | Action no-op without running | Mocked action |
| `[ActionClass::class => ['key' => 'val']]` | Action sets context keys without running | Context proxy |
| `'@done'` / `'@done.X'` / `'@fail'` / `'@timeout'` | Synthesize delegation outcome (child does NOT run) | No child dispatched, no DB rows |
| `['outcome' => '@done.X', 'output' => [...]]` | Same with parent's @done action receiving output | No child dispatched |
| `['outcome' => '@done', GuardClass::class => true]` | Outcome with guard override on the @done transition | No child dispatched |
| `ChildScenarioClass::class` | Child machine runs with the child's plan applied | Child runs; may pause at interactive state |
| `['@continue' => SomeEvent::class]` | Player auto-sends event from this state | Drives traversal forward |
| `['@continue' => fn (...) => $payload]` | Closure resolves payload at fire time | Same with dynamic data |

## Inline outcome vs child scenario class — decision rule

Both forms work for delegation states. Pick deliberately:

| You want… | Use |
|-----------|-----|
| Skip the child entirely; pretend it returned X | **Inline outcome** — no child runs, no MachineChild row, no queue dispatch |
| Walk the child's state graph but mock its leaf actions | **Child scenario class** — child runs with overrides, may pause, parent transitions when child reaches its target |

Inline form is faster and stricter (no child code path exercised). Class form is correct when the child's own logic — `@always` chain, guards, parallel regions — is part of what the scenario should verify.

## Async (queue:) delegation — 9.10.3+ required

Both inline outcomes and child scenario classes work transparently for `'queue:'` parents in 9.10.3 and later. Earlier versions silently dropped the scenario at dispatch time — async children booted without scenario context and ran full I/O.

If a `'queue:'` parent's child runs full I/O despite the scenario plan referencing it:
- Confirm package version is 9.10.3 or later
- Confirm `config('machine.scenarios.enabled') === true` in the worker's environment (workers may load a different config than HTTP requests)
- Inspect `MachineCurrentState.scenario_class` for the dispatched child; if `null`, the dispatch site couldn't resolve the active child scenario for that state route

## Common patterns

### Skip a delegation that does I/O

```php
'resolving_findeks_phones' => [
    'outcome' => '@done.phones_resolved',
    'output'  => ['findeksPhones' => [['phone' => '599*****99']], 'findeksPhonesStatus' => 'resolved'],
],
```

Parent's `@done.phones_resolved` action runs with the synthetic output, child never dispatches.

### Simulate a failure

```php
'processing_payment' => [
    'outcome' => '@fail',
    'output'  => ['reason' => 'gateway_timeout'],
],
```

Parent's `@fail` handler fires. Note: synthetic `@fail` does NOT inject a typed `MachineFailure` — if the action type-hints a subclass, use a context-write proxy override instead.

### Simulate a timeout

```php
'awaiting_webhook' => ['outcome' => '@timeout'],
```

Parent's `@timeout` handler fires.

### Walk a child machine with leaf overrides

```php
'verification' => AtSomeChildState::class,
```

`AtSomeChildState` is a separate `MachineScenario` for the child machine. Child runs through its own plan; may pause at interactive state. Forward endpoints become active so QA can drive the child further.

### `@continue` for multi-step traversal

```php
'reviewing' => ['@continue' => ApproveEvent::class],
```

Player auto-sends `ApproveEvent` when the machine arrives at `reviewing`. Typical for moving past states that wait for a UI action.

## Async propagation — what travels where

| Path | Trigger | Activation site | Source of scenario class |
|------|---------|-----------------|---------------------------|
| Existing-machine restoration | `Machine::create(state: $rootEventId)` | `restoreStateFromRootEventId` §9 block | `machine_current_states.scenario_class` row |
| Fresh async child boot (9.10.3+) | `ChildMachineJob::handle()` for `'queue:'` parent | `ScenarioPlayer::activateForAsyncBoot()` in `try`, `deactivate()` in `finally` | `ChildMachineJob::$scenarioClass` payload |
| Sync child scenario reference | Parent transitions into delegation state with child scenario reference in plan | `ScenarioPlayer::executeChildScenario()` in-process | Resolved from parent's plan |

The trio that must be reactivated: classified outcomes (`self::$outcomes`/`self::$childScenarios`), behavior overrides (Laravel container), `self::$isActive = true`. Without all three, leaf-state delegation outcomes silently fail.

## Top gotchas

| # | Gotcha | Fix |
|---|--------|-----|
| 1 | Simulated `@fail` doesn't inject typed `MachineFailure` | Use context-write proxy: `StoreFailureAction::class => ['failureReason' => '...']` |
| 2 | Overrides not reachable if guards route around them | Override branch-controlling guards in the same plan entry |
| 3 | Transition actions with I/O fallbacks run during scenarios | Override the action explicitly |
| 4 | Missing `continuation()` → real dispatches after target | Add `continuation()` for retry/resend states |
| 5 | Parallel `@continue` on parent silently no-ops | Declare on a leaf state of one region; player walks regions round-robin |
| 6 | Async child runs full I/O despite scenario | Upgrade to 9.10.3+ |
| 7 | Plan key prefix mismatch → no override applied | Use full state route (`car_sales.allocation.checking`) — suffix matching is permissive but explicit is safer |

## Validator + diagnostics

```bash
php artisan machine:scenario-validate                # all scenarios
php artisan machine:scenario-validate --scenario=at-review-scenario
php artisan machine:scenario AtReview ... --dry-run  # scaffold preview
```

Validator catches: structural plan errors, target unreachability, parallel `@continue` on parents, missing scenario behaviors. Run before committing scenarios.

After running a scenario, query `machine_events.type LIKE 'child.%.start'` and `'child.%.done'` for the same `root_event_id` — same-second timestamps = scenario intercepted; visible gap = real delegation fired (silent bug).

## See also

- `docs/advanced/scenarios.md` — overview and quick-start
- `docs/advanced/scenario-plan.md` — full `plan()` reference (override forms, delegation outcomes, child scenarios, @continue, parallel patterns, pitfalls)
- `docs/advanced/scenario-behaviors.md` — when to write a `MachineScenario`-aware behavior class
- `docs/advanced/scenario-runtime.md` — engine internals, async propagation, debugging
- `docs/advanced/scenario-commands.md` — artisan command reference
- `docs/advanced/scenario-endpoints.md` — HTTP activation
- `references/delegation.md` — child machine delegation patterns (sync/async/job actors)
