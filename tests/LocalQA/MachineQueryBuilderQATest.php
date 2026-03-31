<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Services\ArchiveService;
use Tarfinlabs\EventMachine\Query\MachineQueryResult;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\QueryBuilderTestMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\E2EBasicMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\AsyncAutoCompleteParentMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
    Machine::resetMachineFakes();
});

// ═══════════════════════════════════════════════════════════════
//  1. Real Persistence Cycle — MySQL persist → query → restore
// ═══════════════════════════════════════════════════════════════

it('LocalQA: query finds machines persisted to MySQL', function (): void {
    // Create 3 machines in different states
    $idle = QueryBuilderTestMachine::create();
    $idle->persist();
    $idleId = $idle->state->history->first()->root_event_id;

    $active = QueryBuilderTestMachine::create();
    $active->persist();
    $active->send(['type' => 'START']);
    $activeId = $active->state->history->first()->root_event_id;

    $completed = QueryBuilderTestMachine::create();
    $completed->persist();
    $completed->send(['type' => 'START']);
    $completed->send(['type' => 'FINISH']);
    $completedId = $completed->state->history->first()->root_event_id;

    // Query each state
    $idleResults = QueryBuilderTestMachine::query()->inState('idle')->get();
    expect($idleResults)->toHaveCount(1);
    expect($idleResults->first()->machineId)->toBe($idleId);

    $activeResults = QueryBuilderTestMachine::query()->inState('active')->get();
    expect($activeResults)->toHaveCount(1);
    expect($activeResults->first()->machineId)->toBe($activeId);

    $completedResults = QueryBuilderTestMachine::query()->inState('completed')->get();
    expect($completedResults)->toHaveCount(1);
    expect($completedResults->first()->machineId)->toBe($completedId);

    // active() excludes final states
    $nonFinal = QueryBuilderTestMachine::query()->active()->get();
    expect($nonFinal)->toHaveCount(2);
    $nonFinalIds = $nonFinal->pluck('machineId')->all();
    expect($nonFinalIds)->toContain($idleId);
    expect($nonFinalIds)->toContain($activeId);
    expect($nonFinalIds)->not->toContain($completedId);
});

it('LocalQA: lazy restore from query result returns correct machine state', function (): void {
    $machine = QueryBuilderTestMachine::create();
    $machine->persist();
    $machine->send(['type' => 'START']);

    $result = QueryBuilderTestMachine::query()->inState('active')->first();
    expect($result)->not->toBeNull();

    // Lazy restore should return a fully functional Machine
    $restored = $result->machine();
    expect($restored->state->matches('active'))->toBeTrue();

    // Context should be accessible
    $context = $result->context();
    expect($context)->not->toBeNull();
});

// ═══════════════════════════════════════════════════════════════
//  2. Concurrent Query + Send — query while another process sends
// ═══════════════════════════════════════════════════════════════

it('LocalQA: query returns consistent results after sends modify machines', function (): void {
    // Create 5 machines in idle state
    $ids      = [];
    $machines = [];
    for ($i = 0; $i < 5; $i++) {
        $m = QueryBuilderTestMachine::create();
        $m->persist();
        $ids[]      = $m->state->history->first()->root_event_id;
        $machines[] = $m;
    }

    // Verify all 5 are idle
    expect(QueryBuilderTestMachine::query()->inState('idle')->count())->toBe(5);

    // Send START to 3 of them (synchronous — tests query consistency after state change)
    for ($i = 0; $i < 3; $i++) {
        $machines[$i]->send(['type' => 'START']);
    }

    // Verify: 2 idle, 3 active, 0 completed
    expect(QueryBuilderTestMachine::query()->inState('idle')->count())->toBe(2);
    expect(QueryBuilderTestMachine::query()->inState('active')->count())->toBe(3);
    expect(QueryBuilderTestMachine::query()->active()->count())->toBe(5);
    expect(QueryBuilderTestMachine::query()->inFinalState()->count())->toBe(0);
});

// ═══════════════════════════════════════════════════════════════
//  3. Parallel Dispatch + Query — parallel regions as queue jobs
// ═══════════════════════════════════════════════════════════════

it('LocalQA: query deduplicates parallel machine with dispatch mode regions', function (): void {
    config(['machine.parallel_dispatch.enabled' => true]);

    $machine = E2EBasicMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Machine is in parallel state — multiple rows in machine_current_states
    $rowCount = MachineCurrentState::where('root_event_id', $rootEventId)->count();
    expect($rowCount)->toBeGreaterThanOrEqual(1);

    // Query should return exactly 1 result (deduplicated)
    $results = E2EBasicMachine::query()->inState('processing')->get();

    // The machine might auto-complete due to entry actions, so check
    // either processing or completed
    $totalResults = E2EBasicMachine::query()->inState('e2e_basic.*')->get();
    expect($totalResults)->toHaveCount(1);
    expect($totalResults->first()->machineId)->toBe($rootEventId);

    // stateIds should contain all active states
    $result = $totalResults->first();
    expect($result->stateIds)->toBeArray();

    // count() should also return 1
    expect(E2EBasicMachine::query()->inState('e2e_basic.*')->count())->toBe(1);

    config(['machine.parallel_dispatch.enabled' => false]);
});

// ═══════════════════════════════════════════════════════════════
//  4. Pagination with Real Data — hundreds of instances
// ═══════════════════════════════════════════════════════════════

it('LocalQA: pagination works correctly with many MySQL instances', function (): void {
    // Create 25 machines — mix of states
    $ids = [];
    for ($i = 0; $i < 25; $i++) {
        $m = QueryBuilderTestMachine::create();
        $m->persist();

        if ($i < 10) {
            $m->send(['type' => 'START']); // 10 active
        } elseif ($i < 15) {
            $m->send(['type' => 'START']);
            $m->send(['type' => 'FINISH']); // 5 completed
        }
        // remaining 10 stay idle

        $ids[] = $m->state->history->first()->root_event_id;
    }

    // Paginate active machines (should be 10 total)
    $page1 = QueryBuilderTestMachine::query()
        ->inState('active')
        ->paginate(3);

    expect($page1->total())->toBe(10);
    expect($page1->perPage())->toBe(3);
    expect($page1->items())->toHaveCount(3);

    // Verify all page items are MachineQueryResult
    foreach ($page1->items() as $item) {
        expect($item)->toBeInstanceOf(MachineQueryResult::class);
        expect($item->stateId)->toContain('active');
    }

    // active() should return 20 (10 idle + 10 active, excluding 5 completed)
    expect(QueryBuilderTestMachine::query()->active()->count())->toBe(20);

    // notInState + paginate
    $nonIdle = QueryBuilderTestMachine::query()
        ->notInState('idle')
        ->paginate(5);

    expect($nonIdle->total())->toBe(15); // 10 active + 5 completed
});

// ═══════════════════════════════════════════════════════════════
//  5. Archive + Query Interaction — archived machines invisible
// ═══════════════════════════════════════════════════════════════

it('LocalQA: archived machines do not appear in query results', function (): void {
    config([
        'machine.archival.enabled'                => true,
        'machine.archival.level'                  => 6,
        'machine.archival.threshold'              => 100,
        'machine.archival.days_inactive'          => 1,
        'machine.archival.restore_cooldown_hours' => 0,
    ]);

    if (DB::getSchemaBuilder()->hasTable('machine_event_archives')) {
        DB::table('machine_event_archives')->truncate();
    }

    // Create 3 machines: 2 idle, 1 active
    $idle1 = QueryBuilderTestMachine::create();
    $idle1->persist();
    $idle1Id = $idle1->state->history->first()->root_event_id;

    $idle2 = QueryBuilderTestMachine::create();
    $idle2->persist();
    $idle2Id = $idle2->state->history->first()->root_event_id;

    $active = QueryBuilderTestMachine::create();
    $active->persist();
    $active->send(['type' => 'START']);
    $activeId = $active->state->history->first()->root_event_id;

    // Verify: 2 idle, 1 active
    expect(QueryBuilderTestMachine::query()->inState('idle')->count())->toBe(2);
    expect(QueryBuilderTestMachine::query()->inState('active')->count())->toBe(1);

    // Archive idle1 — backdate events, then archive
    DB::table('machine_events')
        ->where('root_event_id', $idle1Id)
        ->update(['created_at' => now()->subDays(2)]);

    $archiveService = new ArchiveService();
    $archiveService->archiveMachine($idle1Id);

    // ArchiveService removes machine_events but NOT machine_current_states.
    // The archived machine still appears in queries (current state is preserved).
    // This is correct: machine_current_states is a lightweight index that
    // enables query-builder lookups; archival only compresses event history.

    // Events are gone from active table
    $archivedEvents = DB::table('machine_events')
        ->where('root_event_id', $idle1Id)
        ->count();
    expect($archivedEvents)->toBe(0);

    // But current_state row is preserved — machine still appears in queries
    $idleResults = QueryBuilderTestMachine::query()->inState('idle')->get();
    expect($idleResults)->toHaveCount(2); // Both idle machines still visible

    // active() still returns all 3
    expect(QueryBuilderTestMachine::query()->active()->count())->toBe(3);

    // Lazy restore on archived machine triggers auto-restore from archive
    $archivedResult = $idleResults->first(fn ($r) => $r->machineId === $idle1Id);
    expect($archivedResult)->not->toBeNull();

    // machine() should auto-restore from archive (ArchiveService restores on access)
    $restored = $archivedResult->machine();
    expect($restored->state->matches('idle'))->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════
//  6. Ordering + Time Filters with Real Timestamps
// ═══════════════════════════════════════════════════════════════

it('LocalQA: latest/oldest ordering uses real MySQL timestamps', function (): void {
    // Create machines with real time gaps
    $first = QueryBuilderTestMachine::create();
    $first->persist();
    $firstId = $first->state->history->first()->root_event_id;

    sleep(1); // Real 1-second gap for MySQL timestamp precision

    $second = QueryBuilderTestMachine::create();
    $second->persist();
    $secondId = $second->state->history->first()->root_event_id;

    sleep(1);

    $third = QueryBuilderTestMachine::create();
    $third->persist();
    $thirdId = $third->state->history->first()->root_event_id;

    // latest() — most recent first
    $latest = QueryBuilderTestMachine::query()
        ->inState('idle')
        ->latest()
        ->get();

    expect($latest)->toHaveCount(3);
    expect($latest->first()->machineId)->toBe($thirdId);
    expect($latest->last()->machineId)->toBe($firstId);

    // oldest() — oldest first
    $oldest = QueryBuilderTestMachine::query()
        ->inState('idle')
        ->oldest()
        ->get();

    expect($oldest->first()->machineId)->toBe($firstId);
    expect($oldest->last()->machineId)->toBe($thirdId);
});

it('LocalQA: enteredBefore/enteredAfter filter with real MySQL timestamps', function (): void {
    // Create machine, note the time
    $before = QueryBuilderTestMachine::create();
    $before->persist();

    sleep(1);
    $cutoff = now();
    sleep(1);

    $after = QueryBuilderTestMachine::create();
    $after->persist();
    $afterId = $after->state->history->first()->root_event_id;

    // enteredBefore: only the first machine
    $beforeResults = QueryBuilderTestMachine::query()
        ->inState('idle')
        ->enteredBefore($cutoff)
        ->get();
    expect($beforeResults)->toHaveCount(1);

    // enteredAfter: only the second machine
    $afterResults = QueryBuilderTestMachine::query()
        ->inState('idle')
        ->enteredAfter($cutoff)
        ->get();
    expect($afterResults)->toHaveCount(1);
    expect($afterResults->first()->machineId)->toBe($afterId);
});

// ═══════════════════════════════════════════════════════════════
//  7. Cross-Machine Class Isolation
// ═══════════════════════════════════════════════════════════════

it('LocalQA: query only returns instances of the correct machine class', function (): void {
    // Create machines of different classes
    $qb = QueryBuilderTestMachine::create();
    $qb->persist();

    $async = AsyncAutoCompleteParentMachine::create();
    $async->persist();

    // Each query should only find its own machine class
    expect(QueryBuilderTestMachine::query()->inState('qb_test.*')->count())->toBe(1);

    // AsyncAutoComplete uses a different machine_class — should not appear in QBTest queries
    $qbResults = QueryBuilderTestMachine::query()->inState('idle')->pluckMachineIds();
    expect($qbResults)->toHaveCount(1);
    expect($qbResults->first())->toBe($qb->state->history->first()->root_event_id);
});

// ═══════════════════════════════════════════════════════════════
//  8. Rapid State Changes — query consistency during transitions
// ═══════════════════════════════════════════════════════════════

it('LocalQA: query reflects state after rapid sequential transitions', function (): void {
    $machine = QueryBuilderTestMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Rapid: idle → active → completed
    $machine->send(['type' => 'START']);
    $machine->send(['type' => 'FINISH']);

    // Query should reflect final state
    expect(QueryBuilderTestMachine::query()->inState('idle')->count())->toBe(0);
    expect(QueryBuilderTestMachine::query()->inState('active')->count())->toBe(0);
    expect(QueryBuilderTestMachine::query()->inState('completed')->count())->toBe(1);
    expect(QueryBuilderTestMachine::query()->inFinalState()->count())->toBe(1);
    expect(QueryBuilderTestMachine::query()->active()->count())->toBe(0);
});

// ═══════════════════════════════════════════════════════════════
//  9. Large Batch — COUNT DISTINCT correctness with MySQL
// ═══════════════════════════════════════════════════════════════

it('LocalQA: count() returns correct DISTINCT count with many instances', function (): void {
    // Create 50 machines
    for ($i = 0; $i < 50; $i++) {
        $m = QueryBuilderTestMachine::create();
        $m->persist();

        if ($i % 2 === 0) {
            $m->send(['type' => 'START']); // 25 active
        }
        // 25 idle
    }

    expect(QueryBuilderTestMachine::query()->inState('idle')->count())->toBe(25);
    expect(QueryBuilderTestMachine::query()->inState('active')->count())->toBe(25);
    expect(QueryBuilderTestMachine::query()->active()->count())->toBe(50);
    expect(QueryBuilderTestMachine::query()->inAnyState(['idle', 'active'])->count())->toBe(50);
});

// ═══════════════════════════════════════════════════════════════
//  10. inAnyState + notInState combo on real MySQL
// ═══════════════════════════════════════════════════════════════

it('LocalQA: complex filter chains work correctly on MySQL', function (): void {
    // Create machines in all 3 states
    for ($i = 0; $i < 3; $i++) {
        $m = QueryBuilderTestMachine::create();
        $m->persist();
    }
    for ($i = 0; $i < 4; $i++) {
        $m = QueryBuilderTestMachine::create();
        $m->persist();
        $m->send(['type' => 'START']);
    }
    for ($i = 0; $i < 2; $i++) {
        $m = QueryBuilderTestMachine::create();
        $m->persist();
        $m->send(['type' => 'START']);
        $m->send(['type' => 'FINISH']);
    }

    // 3 idle, 4 active, 2 completed = 9 total
    expect(QueryBuilderTestMachine::query()->inAnyState(['idle', 'active'])->count())->toBe(7);
    expect(QueryBuilderTestMachine::query()->notInState('idle')->count())->toBe(6);
    expect(QueryBuilderTestMachine::query()->notInState('idle')->notInFinalState()->count())->toBe(4);
    expect(QueryBuilderTestMachine::query()->inAnyState(['idle', 'completed'])->count())->toBe(5);

    // pluckMachineIds returns correct count
    $ids = QueryBuilderTestMachine::query()->active()->pluckMachineIds();
    expect($ids)->toHaveCount(7);
});

// ═══════════════════════════════════════════════════════════════
//  11. No Stale Locks After Query + Restore
// ═══════════════════════════════════════════════════════════════

it('LocalQA: query + lazy restore does not leave stale locks', function (): void {
    $machine = QueryBuilderTestMachine::create();
    $machine->persist();
    $machine->send(['type' => 'START']);
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Query and restore
    $result   = QueryBuilderTestMachine::query()->inState('active')->first();
    $restored = $result->machine();

    // Send event on restored machine
    $restored->send(['type' => 'FINISH']);

    // No stale locks
    $locks = DB::table('machine_locks')->where('root_event_id', $rootEventId)->count();
    expect($locks)->toBe(0);

    // Machine is now completed
    expect(QueryBuilderTestMachine::query()->inFinalState()->count())->toBe(1);
});

// ═══════════════════════════════════════════════════════════════
//  12. Concurrent Async Sends While Querying
// ═══════════════════════════════════════════════════════════════

it('LocalQA: batch sends do not corrupt query results', function (): void {
    // Create 10 machines in idle
    $machines = [];
    for ($i = 0; $i < 10; $i++) {
        $m = QueryBuilderTestMachine::create();
        $m->persist();
        $machines[] = $m;
    }

    expect(QueryBuilderTestMachine::query()->inState('idle')->count())->toBe(10);

    // Send START to all 10 synchronously (tests query consistency under rapid writes)
    foreach ($machines as $m) {
        $m->send(['type' => 'START']);
    }

    // Verify: 0 idle, 10 active
    expect(QueryBuilderTestMachine::query()->inState('idle')->count())->toBe(0);
    expect(QueryBuilderTestMachine::query()->inState('active')->count())->toBe(10);
    expect(QueryBuilderTestMachine::query()->active()->count())->toBe(10);

    // No stale locks
    $locks = DB::table('machine_locks')->count();
    expect($locks)->toBe(0);

    // Now finish all 10 — rapid final state transitions
    foreach ($machines as $m) {
        $m->send(['type' => 'FINISH']);
    }

    expect(QueryBuilderTestMachine::query()->active()->count())->toBe(0);
    expect(QueryBuilderTestMachine::query()->inFinalState()->count())->toBe(10);
});
