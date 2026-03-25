# Optimistic Concurrency Control (OCC) for Event Ordering

**Status:** Draft
**Date:** 2026-03-25
**Prerequisites:** Solution B (always-on lock) implemented and stable

---

## Problem

EventMachine uses pessimistic locking (`MachineLockManager`) to prevent concurrent state mutation. While effective, this approach has limitations:

1. **Lock contention** reduces throughput under high concurrency
2. **Re-entrant deadlocks** require special handling (sync queue exemption, `$heldLockIds`)
3. **No ordering guarantee** — events can be processed out of arrival order
4. **Lost-update risk** persists when locks expire (TTL) before processing completes
5. **No conflict detection** — if two processes modify the same machine, the last write wins silently

Real event stores (EventStoreDB, Kafka) solve this with optimistic concurrency: write with an expected version, fail if version has changed, retry with fresh state.

---

## Proposed Solution

### Version Column on machine_events

Add `expected_version` to the persist flow:

```sql
ALTER TABLE machine_events ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 0;
CREATE UNIQUE INDEX idx_machine_events_version
    ON machine_events (root_event_id, version);
```

Each persist calculates `next_version = max(version) + 1` for the root_event_id. The INSERT uses the unique index to detect conflicts:

```php
// In Machine::persist()
$currentVersion = DB::table('machine_events')
    ->where('root_event_id', $rootEventId)
    ->max('version') ?? 0;

foreach ($newEvents as $i => $event) {
    $event->version = $currentVersion + $i + 1;
}

try {
    MachineEvent::insert($newEvents);
} catch (UniqueConstraintViolationException) {
    // Conflict detected — another process wrote events concurrently.
    // Restore fresh state and retry the entire transition.
    throw new ConcurrencyConflictException($rootEventId, $currentVersion);
}
```

### Retry Loop in SendToMachineJob

```php
public function handle(): void
{
    $maxRetries = 3;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $machine = $this->machineClass::create(state: $this->rootEventId);
            $machine->send($this->event);
            return; // Success
        } catch (ConcurrencyConflictException) {
            if ($attempt === $maxRetries) {
                throw new EventDeliveryFailedException($this->rootEventId, $this->event);
            }
            usleep(50_000 * $attempt); // Exponential backoff: 50ms, 100ms, 150ms
        }
    }
}
```

### Migration from Pessimistic to Optimistic

1. **Phase 1**: Add version column, keep locks as primary mechanism
2. **Phase 2**: Add conflict detection alongside locks (dual-write safety net)
3. **Phase 3**: Remove locks, rely on OCC only
4. **Phase 4**: Remove `machine_locks` table

---

## Benefits

| Aspect | Current (Pessimistic) | Proposed (Optimistic) |
|--------|----------------------|----------------------|
| Throughput | Limited by lock contention | Higher — no blocking |
| Deadlocks | Possible (re-entrant handling needed) | Impossible — no locks |
| Event ordering | Not guaranteed | Version-based ordering |
| Conflict detection | None (last-write-wins) | Explicit (version mismatch) |
| Complexity | Lock management, TTL, cleanup | Retry logic, version tracking |

---

## Risks

1. **Retry storms** under very high contention (many events to same machine)
2. **Side effects in retried transitions** — actions must be idempotent
3. **Migration complexity** — version column needs to be populated for existing events
4. **Performance of MAX(version) query** — needs index optimization

---

## Scope

- `Machine::persist()` — add version check
- `SendToMachineJob` — add retry loop
- `ChildMachineCompletionJob` — add retry loop
- `ListenerJob` — add retry loop
- `ParallelRegionJob` — add retry loop (or keep lock for parallel regions)
- Migration — add version column + unique index
- Action idempotency — document requirement for retry-safe actions

---

## Decision Criteria

Proceed with OCC when:
1. Solution B (always-on lock) is proven stable in production
2. Lock contention becomes a measurable bottleneck
3. Event ordering bugs are reported despite locking

Do NOT proceed if:
- Current locking is sufficient for expected load
- Action side effects are not idempotent
