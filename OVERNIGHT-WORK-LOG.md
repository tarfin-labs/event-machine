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

## Work In Progress

### Permanent Local QA Test Suite
Building comprehensive test suite that:
- Lives in `tests/LocalQA/` in the event-machine repo
- Requires real MySQL + Redis + Horizon
- Covers ALL features end-to-end
- User runs on-demand: `vendor/bin/pest tests/LocalQA/`

## Codebase Stats After All Work

- **1180 package tests** passing
- **42 Horizon QA tests** passing
- **Type coverage**: 100%
- **DocTest**: 0 failures
- **CI**: All green on PR #122
