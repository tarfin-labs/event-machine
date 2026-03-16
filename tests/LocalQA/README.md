# Local QA Tests

Comprehensive end-to-end QA tests that run against **real infrastructure**:
- **MySQL** (not SQLite)
- **Redis** (not array/sync)
- **Horizon** (multiple workers, real async processing)

These tests are NOT for CI. They are run locally on-demand.

## Prerequisites

```bash
# MySQL running (root, no password)
mysql -u root -e "CREATE DATABASE IF NOT EXISTS qa_event_machine_v7;"

# Redis running
redis-cli PING  # should return PONG

# Horizon running (in separate terminal)
cd /path/to/event-machine
# Start a temporary Horizon for QA:
php artisan horizon --env=localqa
```

## Running

```bash
# Run all local QA tests
vendor/bin/pest tests/LocalQA/

# Run a specific feature area
vendor/bin/pest tests/LocalQA/AsyncDelegationTest.php
```

## Environment

Tests use `.env.localqa` for database/queue configuration. Create it:

```bash
cp .env.localqa.example .env.localqa
```

## Test Structure

| File | Feature | Async? |
|------|---------|--------|
| `AsyncDelegationTest.php` | Async child via Horizon | Yes |
| `AsyncEdgeCasesTest.php` | @timeout, @fail, sequential delegation, concurrent sends | Yes |
| `CrossMachineTest.php` | sendTo/dispatchTo | Yes |
| `EdgeCasesTest.php` | Combo features, regression | Yes |
| `InfiniteLoopQATest.php` | Loop protection under Horizon | Yes |
| `JobActorsTest.php` | Job actor success + failure via Horizon | Yes |
| `MachineLockingTest.php` | Lock acquire/release | Yes |
| `MachineIdentityTest.php` | machineId after restore | Mixed |
| `ReviewFixesTest.php` | Timer dedup, forward routing, context isolation | Yes |
| `ScheduledEventsTest.php` | Resolver + auto-detect | Yes |
| `TimerSweepTest.php` | after/every via Bus::batch | Yes |
