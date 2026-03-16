# Overnight Work Log — 2026-03-16 03:25 → 09:00

## Completed QA Summary

### Real Infrastructure QA (MySQL + Redis + Horizon)

**42 Horizon QA tests, 1 bug found and fixed, 0 remaining bugs.**

| Test File | Tests | Status | Key Findings |
|-----------|-------|--------|-------------|
| `SyncDelegationQATest` | 8 | ✅ | @done, @fail, with-key, faking, identity |
| `AsyncDelegationQATest` | 4 | ✅ | Real Horizon: child creation, completion, parallel |
| `CrossMachineQATest` | 4 | ✅ | sendTo sync, dispatchTo async Horizon |
| `TimerBusBatchQATest` | 5 | ✅ | Bus::batch via Horizon, dedup, selective |
| `EveryTimerHorizonQATest` | 4 | ✅ | fire_count, increment, max/then, exhausted |
| `ScheduledEventsHorizonQATest` | 4 | ✅ | Resolver, auto-detect, cross-check |
| `MachineLockingQATest` | 3 | ✅ | Lock release, no deadlock, idempotent |
| `JobActorsHorizonQATest` | 3 | ✅ | ChildJobJob, ReturnsResult, parallel |
| `EdgeCasesHorizonQATest` | 5 | ✅ | @always child, dedup, max=3, identity, combo |
| `WebhookEndpointHorizonQATest` | 1 | ✅ | Full webhook auto-completion pattern |

### Bug Found and Fixed

**`machineId()` returns null after `Machine::create(state: $id)` restore**
- Root cause: `setMachineIdentity()` not called during restore
- Fix: `Machine::start()` now sets identity when `machineId() === null`
- Commit: `6097940`
- TDD: 2 tests in `MachineIdentityRestoreTest.php`

## Permanent Local QA Test Suite (Completed)

Built and committed `tests/LocalQA/` — 23 tests covering all async features:

| File | Tests | Features |
|------|-------|----------|
| `AsyncDelegationTest.php` | 2 | Async child via Horizon, faking |
| `TimerSweepTest.php` | 6 | after/every/max+then/dedup/selective |
| `ScheduledEventsTest.php` | 3 | Resolver Bus::batch, auto-detect, cross-check |
| `CrossMachineTest.php` | 2 | dispatchTo, non-existent target |
| `MachineLockingTest.php` | 2 | Lock release, 5 parallel no deadlock |
| `MachineIdentityTest.php` | 3 | machineId fresh/restore/after-async |
| `EdgeCasesTest.php` | 5 | Timer+schedule combo, idempotent, faking, 10 instances |

**Run locally:** `vendor/bin/pest tests/LocalQA/` (requires MySQL + Redis + Horizon)
**Excluded from CI** via `phpunit.xml.dist` `<exclude>tests/LocalQA</exclude>`

## Documentation Improvements

- Updated upgrading guide: all 6 features, 3 tables, 5 commands, 7 test helpers
- Updated `.doctest/db.php`: v7 migrations for CI doctest
- Fixed time-based-events.md Timer block attribute

## Codebase Maintenance

- Fixed `json('machine_value')->index()` MySQL compatibility (documented in memory)
- Made `$endpoints` and `$schedules` `private readonly` on MachineDefinition
- Added boot-time validation to `MachineScheduler::register()`
- Expanded `detectTargetStates()` for compound states
- Normalized TestMachine schedule error messages
- Skipped `MakeModelScopesProtectedRector` (Laravel scopes must be public)
- Added type hints for 100% type coverage

## Final Stats

- **1180 package tests** passing (SQLite, sync)
- **23 LocalQA tests** (MySQL, Redis, Horizon)
- **42 /tmp QA tests** (ad-hoc, non-permanent)
- **Type coverage**: 100%
- **DocTest**: 0 failures
- **CI**: All green on PR #122
- **Bugs found and fixed**: 1 (machineId null after restore)
