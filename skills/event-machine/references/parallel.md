# Parallel States Cheat-Sheet (curated)

## Design checklist — BEFORE writing parallel config

- [ ] Each region's work is truly independent (no cross-region state coupling)
- [ ] Each region owns its context keys (`paymentStatus`, `shippingStatus` — not shared `status`)
- [ ] Regions coordinate only via events (`raise()`, `sendTo()`) — never via shared context mutation
- [ ] You know what `@done` means for this parallel: "all regions reach final" — is that the right semantic?
- [ ] Failure mode: `@fail` fires when ANY region fails — is that right, or do you need per-region recovery?
- [ ] If using dispatch mode: queue workers available, `parallel_dispatch.enabled = true` in `config/machine.php`

## Canonical config

```php
'processing' => [
    'type'   => 'parallel',
    '@done'  => 'fulfilled',           // all regions final
    '@fail'  => 'failed',              // any region failed
    'states' => [
        'payment' => [
            'initial' => 'pending',
            'states'  => [
                'pending' => ['on' => ['PAID' => 'done']],
                'done'    => ['type' => 'final'],
            ],
        ],
        'shipping' => [
            'initial' => 'preparing',
            'states'  => [
                'preparing' => ['on' => ['SHIPPED' => 'done']],
                'done'      => ['type' => 'final'],
            ],
        ],
    ],
],
```

## Dispatch mode — when to enable

Enable via `config/machine.php`:

```php
'parallel_dispatch' => [
    'enabled'        => true,
    'queue'          => 'parallel',
    'lock_timeout'   => 10,
    'lock_ttl'       => 300,
    'job_timeout'    => 120,
    'job_tries'      => 3,
    'job_backoff'    => [5, 15, 30],
    'region_timeout' => 600,
],
```

**Use dispatch mode when:**
- Entry actions are slow (API calls, heavy computation)
- You need true wall-clock parallelism (5s + 2s should take 5s, not 7s)
- You have queue workers available

**Don't use dispatch mode when:**
- Regions are cheap — overhead of queuing > savings
- You need guaranteed synchronous ordering (use actor-driven parallelism instead)
- Test environment with sync queue — start from `idle` state then transition via event

## Entry requirements for dispatch

`ParallelRegionJob`s only dispatch when:
1. `parallel_dispatch.enabled = true`
2. Entry actions exist on the region (no-op actions are fine)
3. Machine **transitions into** the parallel state (starting directly in parallel does NOT dispatch)
4. `should_persist = true` in machine config

Pattern for dispatch:
```php
'initial' => 'idle',                   // NOT the parallel state
'states' => [
    'idle' => ['on' => ['START' => 'processing']],
    'processing' => ['type' => 'parallel', ...],
],
```

## Cross-region communication

**Rejected at define-time:** transitions between sibling regions. Design regions to be independent.

**Correct way:** use events:

```php
// Region A reaches a milestone
'on' => [
    'MILESTONE' => ['actions' => NotifyOtherRegionAction::class],
],

class NotifyOtherRegionAction extends ActionBehavior {
    public function __invoke(ContextManager $context): void {
        $this->raise('REGION_A_READY');    // bubbles to other regions
    }
}
```

## Region timeout

```php
'shipping' => [
    'initial' => 'preparing',
    '@timeout' => ['after' => 60, 'target' => 'timeout_fallback'],
    'states' => [...],
],
```

`ParallelRegionTimeoutJob` fires after N seconds of region entry. Region transitions to target. Full parallel's `@done` / `@fail` respects the region's resolved state.

## Top 8 gotchas

| # | Gotcha | Implication / Fix |
|---|--------|-------------------|
| 1 | **Last-writer-wins on shared context keys** | Design disjoint keys per region. Document ownership. |
| 2 | **Partial failure: Region A context may be lost** | When A succeeds and B fails, A's changes may be overwritten by B's `failed()` handler (dispatch-time snapshot). Don't assert A's context survives under partial failure. |
| 3 | **`MachineCurrentState` lags under dispatch** | Restore machine via `MyMachine::create(state: $rootEventId)` for assertions. `MachineCurrentState` is OK inside `waitFor()` polling (converges). |
| 4 | **Concurrent `SendToMachineJob`s to same parallel machine** → lost-update | `Machine::send()` now acquires lock for all async queues (not just dispatch). Re-entrant via `$heldLockIds` prevents sync deadlock. |
| 5 | **Event type naming** uses dot-notation: `{machine}.parallel.{placeholder}.region.timeout`, not `PARALLEL_REGION_TIMEOUT` | Query with `LIKE '%region.timeout%'` not `%PARALLEL_REGION_TIMEOUT%` |
| 6 | **Dispatch errors don't propagate as validation** | `ValidationGuardBehavior` failure is synchronous only. Dispatch mode swallows validation-style errors. |
| 7 | **Cross-region transitions rejected at define-time** | This is enforcement, not a bug — use events between regions |
| 8 | **Starting initial state = parallel does NOT dispatch** | Machine must transition INTO the parallel state for `ParallelRegionJob` to fire |

## Testing parallel

```php
// Unit-level — actor-driven, no dispatch
OrderMachine::test()
    ->send('START_PROCESSING')
    ->assertInState('processing.payment.pending')
    ->assertInState('processing.shipping.preparing')
    ->send('PAID')
    ->assertInState('processing.payment.done')
    ->send('SHIPPED')
    ->assertState('fulfilled');              // @done fired

// Dispatch mode — requires LocalQA
// Use `references/qa-setup.md`
```

## See also

- `docs/advanced/parallel-states/index.md` — overview
- `docs/advanced/parallel-states/parallel-dispatch.md` — dispatch mechanics
- `docs/advanced/parallel-states/event-handling.md` — how events flow
- `docs/advanced/parallel-states/persistence.md` — state storage
- `docs/testing/parallel-testing.md` — test patterns
- `docs/best-practices/parallel-patterns.md` — design guidance
