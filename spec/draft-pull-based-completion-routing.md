# Pull-Based Completion Routing (draft, future consideration)

**Status:** Draft. No version assigned. To be considered alongside other v11 design topics.

## Background

The 9.8.5 release added retry-safe propagation recovery for `ChildMachineCompletionJob`. The 9.10.3 release fixed async scenario propagation. Both fixes are tactical patches around the same architectural choice: **push-based child→parent completion routing**. When a child machine reaches a final state, a queue job (`ChildMachineCompletionJob`) is dispatched to wake the parent and route its `@done`/`@fail` transition.

Backend feedback recurringly raises an "outbox pattern" to harden this flow against SIGTERM-during-Redis-push edge cases. While considering outbox, we noticed a deeper architectural insight: `machine_events` already provides the durability guarantee outbox is supposed to provide. The child writes its final state to `machine_events` transactionally — this is itself a durable notification. What outbox actually contributes is a *trigger* for parent wakeup.

This raises the question of whether the entire push-based dispatch model is necessary, or whether parent discovery can be reframed as a pull from `machine_events` + `machine_children` — eliminating an entire class of failure modes (lost dispatches, race conditions, queue retry tuning) without adding new tables.

## Current model (push-based)

- Child reaches final state → writes `state.{final}.enter` to `machine_events` (durability ✅)
- Same code path dispatches `ChildMachineCompletionJob` (trigger via Redis)
- Worker restores parent, calls `routeChildDoneEvent` / `routeChildFailEvent`, persists parent

Failure modes:
- SIGTERM between machine_events commit and Redis push → job lost (9.8.5 mitigated via retry recovery branch)
- Redis loses job (AOF disabled / corrupt / manual flush) → no recovery without outbox
- `retry_after` misconfigured → very long wakeup latency
- Worker crashes before handle() begins routing → reservation expires, job retries, recovery branch handles

## Proposed direction (pull-based primary, push-based fast-path)

**Notification stays the same.** Child writes final to `machine_events` — durable, transactional, already the source of truth.

**Discovery shifts to pull-with-push-fast-path:**

1. **Push fast-path (kept):** ChildMachineCompletionJob still dispatches at child completion. Latency-optimal when Redis path is healthy. Most cases hit this path and complete in milliseconds.

2. **Pull on parent restore (new primary safety net):** Whenever `Machine::create(state: $rootEventId)` restores a parent, if the parent's current state is a delegating state, the engine checks `machine_children` for that parent. For each `running` child, it inspects the child's most recent `machine_events` row. If the child reached a final state, the engine pushes an internal `CHILD_MACHINE_DONE` / `CHILD_MACHINE_FAIL` event into the parent's pipeline before processing the incoming external event.

3. **Pull via cron driver (opt-in for passive flows):** A `machine:reconcile-completions` artisan command — registered via `MachineScheduler`, configurable interval — scans `machine_children WHERE status='running'` cross-referenced against child machine completion. Only needed for parents that don't receive any external events. Default off; opt-in via config.

`machine_events` becomes canonical truth-of-state. Push remains the latency-optimal path. Pull guarantees correctness regardless of Redis health.

## Sketch of mechanism

```php
// Inside Machine::create(state: $rootEventId) or send() pipeline
if ($parent->isInDelegatingState()) {
    foreach (machineChildrenFor($parent) as $childRecord) {
        $latestEvent = MachineEvent::forRoot($childRecord->child_root_event_id)
            ->latest('sequence_number')
            ->first();

        if ($latestEvent && isStateEnterEvent($latestEvent) && isFinalState($latestEvent)) {
            $event = wasSuccessFinal($latestEvent)
                ? CHILD_MACHINE_DONE
                : CHILD_MACHINE_FAIL;

            $parent->pushInternalEvent($event, $latestEvent->payload);
            $childRecord->update(['status' => 'completed']);
        }
    }
}
```

Idempotency naturally falls out of `machine_children.status` — once marked `completed`/`failed`, the check skips.

## Trade-offs

**Wins:**
- Outbox felsefi olarak gereksizleşir. machine_events zaten transactional truth.
- Yeni tablo yok. machine_children + machine_events compose ediyor.
- SIGTERM-resistant. Push kayıpsa pull bir sonraki parent restore'da yakalar.
- Replay correctness güçlenir. Restoration sırasında child state görünür.
- Test edilebilirlik artar. Async job dependency'leri hafifler.
- Lock contention azalır. Daha az queue job dispatch'i.

**Costs:**
- **Per-restore overhead.** Her parent restore'da `machine_children` scan + N child machine_events query. Çok aktif machines için non-trivial. İndekslerle hafifletilebilir ama bedava değil.
- **Behavior shift.** Mevcut sync delegation davranışı parent'ı hemen ilerletir; yeni model send() içinde de child'ı sorgular. Subtle semantic farklar olabilir (örneğin context order).
- **Cron driver opens a "reconciler" door** that we earlier preferred not to open. Pasif flows kaçınılmaz olarak periodic check gerektirir.
- **Race condition shift.** Push race'leri eleniyor ama "pull check sırasında child henüz son row'u yazmamış" race'leri ortaya çıkıyor. Daha dar pencereler ama mevcut.
- **Migration ağrısı.** Mevcut ChildMachineCompletionJob path'i optional veya legacy yapılmalı. Backward compat hassas.

## Open questions

1. **Per-restore overhead kabul edilebilir mi?** Benchmark gerek — çok aktif parents için pull check her send() çağrısında ne kadar yavaşlatır? İhmal edilebilir mi yoksa optimize gerekli mi?

2. **Pull check kapsamı nedir?** Her restore'da mı, yalnızca delegating-state'te mi, sadece çocuğu olan parents için mi? Dar kapsam = az overhead, geniş kapsam = daha sağlam discovery.

3. **Cron driver "reconciler" sınırı.** Outbox ile karıştırılmasın diye semantik net olmalı: stuck-recovery değil, completion-driver. Sadece running children + final state event'i olan rows'u trigger eder. Geri dönük cleanup yapmaz.

4. **Push'u tamamen kaldırma seçeneği var mı?** ChildMachineCompletionJob legacy fast-path olarak kalsın mı, opt-out olabilir mi, yoksa v12'de tamamen kaldırılsın mı?

5. **Deep delegation chain'leri.** Grandparent → parent → child zincirinde, her seviye kendi pull'unu yapar. Cumulative latency ne kadar olur? Acceptable mı yoksa cascade trigger gerekir mi?

6. **Parallel regions.** Parallel parent'ın multi-region completion check'i pull modelde nasıl çalışır? "All regions final" hesaplaması her parent restore'da mı tekrarlanır?

7. **Forward endpoint flow.** HTTP forward endpoint pattern (parent endpoint → forward to running child → child responds) pull modelinde nasıl etkileşir? Latency-critical bir path.

## Out of scope

- Outbox tablosu eklemek. Bu spec outbox'a alternatif olarak konumlanıyor.
- Push mekanizmasını acilen kaldırmak. Geçiş aşamalı olmalı.
- Stuck-recovery reconciler'ı. machine_children.status='running' AND completed_at < NOW() - X gibi geriye dönük cleanup bu spec'in dışında.
- Child machine event'lerinin parent'a "subscription" mekanizması (PostgreSQL LISTEN/NOTIFY vb.). Database-portable kalmak istiyoruz.

## Decision criteria for adoption

Bu yön benimsenmeli mi?

**Evet, eğer:**
- Production'dan outbox-gerektiren somut başarısızlık raporları geliyorsa
- Per-restore overhead acceptable seviyede (<10ms ek latency) çıkarsa
- Migration path'i geriye uyumlu tasarlanabiliyorsa

**Hayır veya ertelet, eğer:**
- 9.8.5 + 9.10.3 mevcut prod kullanım durumlarını kapsıyorsa
- Per-restore overhead kabul edilemez yüksekse
- Sadece teorik durability için bu kadar büyük refactor istemiyorsak

## Related work

- `spec/9.10.3-async-scenario-propagation-fix.md` — async scenario propagation (related: scenario_class threading)
- `src/Jobs/ChildMachineCompletionJob.php` — current push implementation, with 9.8.5 retry recovery
- `src/Actor/Machine.php` §9 Async Propagation — existing pull-on-restore for scenario context (pattern to extend)

## Estimated scope (rough)

If adopted as v11 epic:
- ~300-500 LOC core changes (Machine::create + handleMachineInvoke updates)
- Optional: machine:reconcile-completions command (~100 LOC)
- ~500-800 LOC test coverage (unit + LocalQA + benchmark)
- Documentation: scenario-runtime.md, machine-delegation.md, async-delegation.md major updates
- Skill: references/delegation.md decision section

Realistic 2-3 week implementation if all open questions resolve favorably.
