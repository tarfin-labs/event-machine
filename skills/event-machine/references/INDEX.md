# Reference Index — by Task Type

Use this when the user's question points to a capability, not a file. Each entry: task → docs path(s) → one-line why.

## "How do I…"

| Task | Docs |
|------|------|
| Create my first machine | `docs/getting-started/your-first-machine.md` |
| Decide if EventMachine fits my problem | `docs/getting-started/when-not-to-use.md` + `docs/getting-started/comparison.md` |
| Upgrade to a new major version | `docs/getting-started/upgrading.md` |
| Name states, events, classes correctly | `docs/building/conventions.md` |
| Define hierarchical states | `docs/advanced/hierarchical-states.md` |
| Write a transition with guards | `docs/building/writing-transitions.md` + `docs/behaviors/guards.md` |
| Run entry/exit actions | `docs/advanced/entry-exit-actions.md` |
| Compute values before guards | `docs/behaviors/calculators.md` |
| Validate events / reject invalid state | `docs/behaviors/validation-guards.md` |
| Return a typed final result | `docs/behaviors/outputs.md` |
| Design typed context | `docs/advanced/custom-context.md` + `docs/advanced/typed-contracts.md` |
| Inject services into behaviors | `docs/advanced/dependency-injection.md` |
| Handle `@always` auto-transitions | `docs/advanced/always-transitions.md` |
| Raise internal events from actions | `docs/advanced/raised-events.md` |
| Send events across machines | `docs/advanced/sendto.md` |

## Delegation & Parallel

| Task | Docs |
|------|------|
| Delegate to a child machine | `docs/advanced/machine-delegation.md` |
| Common delegation patterns | `docs/advanced/delegation-patterns.md` |
| Design data flow parent ↔ child | `docs/advanced/delegation-data-flow.md` |
| Run delegation async on a queue | `docs/advanced/async-delegation.md` |
| Delegate to a Laravel Job | `docs/advanced/job-actors.md` |
| Build parallel (concurrent) states | `docs/advanced/parallel-states/index.md` |
| Enable dispatch mode | `docs/advanced/parallel-states/parallel-dispatch.md` |
| Handle events in parallel regions | `docs/advanced/parallel-states/event-handling.md` |
| Persist parallel machines | `docs/advanced/parallel-states/persistence.md` |

## Time

| Task | Docs |
|------|------|
| Transition after a timeout | `docs/advanced/time-based-events.md` |
| Recurring event (every N minutes) | `docs/advanced/time-based-events.md` |
| Renewable timer / sliding window (deadline resets on event) | `docs/best-practices/time-based-patterns.md#renewable-timers-sliding-windows` + `references/timers.md` |
| Cron-driven batch events | `docs/advanced/scheduled-events.md` |
| Test time-based flows | `docs/testing/time-based-testing.md` + `docs/testing/scheduled-testing.md` |

## Scenarios (QA tooling)

| Task | Docs |
|------|------|
| Scenario overview | `docs/advanced/scenarios.md` |
| Scenario behaviors override | `docs/advanced/scenario-behaviors.md` |
| Scenario plan & target state | `docs/advanced/scenario-plan.md` |
| Scenario CLI commands | `docs/advanced/scenario-commands.md` |
| Scenario runtime internals | `docs/advanced/scenario-runtime.md` |
| Expose scenarios via HTTP | `docs/advanced/scenario-endpoints.md` |

## Laravel

| Task | Docs |
|------|------|
| Attach machine to Eloquent | `docs/laravel-integration/eloquent-integration.md` |
| Persist & restore machines | `docs/laravel-integration/persistence.md` |
| Auto-generate HTTP endpoints | `docs/laravel-integration/endpoints.md` |
| Archive old events | `docs/laravel-integration/archival.md` + `docs/laravel-integration/compression.md` |
| Artisan commands reference | `docs/laravel-integration/artisan-commands.md` |
| Framework lifecycle events | `docs/laravel-integration/available-events.md` |

## Testing

| Task | Docs |
|------|------|
| Testing philosophy & layers | `docs/best-practices/testing-strategy.md` + `docs/testing/overview.md` |
| TestMachine fluent API | `docs/testing/test-machine.md` |
| Unit test one behavior | `docs/testing/isolated-testing.md` |
| Replace behaviors with fakes | `docs/testing/fakeable-behaviors.md` |
| Test async delegation | `docs/testing/delegation-testing.md` |
| Test parallel states | `docs/testing/parallel-testing.md` |
| Test timers/schedules | `docs/testing/time-based-testing.md` + `docs/testing/scheduled-testing.md` |
| Verify persistence / restore | `docs/testing/persistence-testing.md` |
| Assert transitions & paths | `docs/testing/transitions-and-paths.md` |
| Constructor DI in tests | `docs/testing/constructor-di.md` |
| Run against real MySQL/Redis | `docs/testing/localqa.md` + `references/qa-setup.md` |
| Troubleshoot flaky tests | `docs/testing/troubleshooting.md` |
| Test recipes cookbook | `docs/testing/recipes.md` |

## Reference

| Topic | Docs |
|-------|------|
| Full execution model (macrostep semantics) | `docs/reference/execution-model.md` |
| All package exceptions | `docs/reference/exceptions.md` |
| Upgrade guide (v6 → v7 → v8 → v9) | `docs/getting-started/upgrading.md` |
