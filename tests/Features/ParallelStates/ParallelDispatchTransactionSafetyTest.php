<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchDbWriteMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

// ============================================================
// Fix A: Machine::send() — persist failure must NOT dispatch jobs
// ============================================================

it('send() does not dispatch pending parallel jobs when persist fails', function (): void {
    Bus::fake();
    config()->set('machine.parallel_dispatch.enabled', true);

    // 1. Create and persist a machine in parallel state
    $machine = ParallelDispatchMachine::create();
    $machine->persist();

    // 2. Clear dispatches from create(), then set fake pending dispatches
    //    to simulate what happens when a transition creates new parallel entries
    //    (e.g., nested parallel in transitionParallelState line 1279-1292)
    $machine->definition->pendingParallelDispatches = [
        ['region_id' => 'test.region_a', 'initial_state_id' => 'test.region_a.working'],
        ['region_id' => 'test.region_b', 'initial_state_id' => 'test.region_b.working'],
    ];

    // 3. Drop machine_events table to make persist() fail inside send()
    //    (machine_state_locks table still exists, so lock works)
    Schema::drop('machine_events');

    // 4. Call send() — transition succeeds (in-memory), persist fails (table gone),
    //    finally block runs dispatchPendingParallelJobs()
    try {
        $machine->send('REGION_A_DONE');
    } catch (Throwable) {
        // Expected — persist fails because machine_events table is dropped
    }

    // 5. Fixed: $shouldDispatch guard in Machine::send() prevents dispatch after persist failure.
    //    dispatchPendingParallelJobs() runs in finally{} but $shouldDispatch stays false.
    Bus::assertNotDispatched(ParallelRegionJob::class);

    // 6. pendingParallelDispatches should be cleared regardless
    expect($machine->definition->pendingParallelDispatches)->toBe([]);
});

// ============================================================
// Fix B: ParallelRegionJob::handle() — critical section must run
//        inside DB::transaction for atomicity
// ============================================================

it('ParallelRegionJob critical section runs inside a DB transaction', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    // 1. Create test_side_effects table (WriteToDbAction inserts into it)
    Schema::create('test_side_effects', function ($table): void {
        $table->id();
        $table->string('value');
    });

    // 2. Create and persist the machine
    $machine = ParallelDispatchDbWriteMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // 3. Capture transaction level when WriteToDbAction runs inside the lock.
    //    WriteToDbAction is the entry action on finished. It runs when the
    //    RAISED event (REGION_A_PROCESSED) is processed inside the lock-protected
    //    section via transition().
    $transactionLevelDuringInsert = 0;

    DB::listen(function ($query) use (&$transactionLevelDuringInsert): void {
        if (str_contains($query->sql, 'test_side_effects')) {
            $transactionLevelDuringInsert = DB::transactionLevel();
        }
    });

    // 4. Run region A job
    (new ParallelRegionJob(
        machineClass: ParallelDispatchDbWriteMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_db_write.processing.region_a',
        initialStateId: 'parallel_dispatch_db_write.processing.region_a.working',
    ))->handle();

    // 5. Fixed: ParallelRegionJob critical section now runs inside DB::transaction().
    //    Level 1 = RefreshDatabase wrapper, Level 2 = our explicit transaction.
    expect($transactionLevelDuringInsert)->toBeGreaterThanOrEqual(2);

    // 6. Verify the job completed successfully (happy path works)
    expect(DB::table('test_side_effects')->count())->toBe(1);

    $restored = ParallelDispatchDbWriteMachine::create(state: $rootEventId);
    expect($restored->state->context->get('regionAResult'))->toBe('processed_by_a');

    // Clean up
    Schema::drop('test_side_effects');
});
