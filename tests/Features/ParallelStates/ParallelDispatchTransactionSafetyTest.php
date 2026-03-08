<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;

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
        ['region_id' => 'test.region_a', 'initial_state_id' => 'test.region_a.working_a'],
        ['region_id' => 'test.region_b', 'initial_state_id' => 'test.region_b.working_b'],
    ];

    // 3. Drop machine_events table to make persist() fail inside send()
    //    (machine_state_locks table still exists, so lock works)
    Schema::drop('machine_events');

    // 4. Call send() — transition succeeds (in-memory), persist fails (table gone),
    //    finally block runs dispatchPendingParallelJobs()
    try {
        $machine->send('REGION_A_DONE');
    } catch (\Throwable) {
        // Expected — persist fails because machine_events table is dropped
    }

    // 5. BUG: In current code, dispatchPendingParallelJobs() runs in finally{} even
    //    though persist failed, dispatching jobs to a ghost state (DB has old state).
    //    After Fix A, no jobs should be dispatched.
    Bus::assertNotDispatched(ParallelRegionJob::class);

    // 6. pendingParallelDispatches should be cleared regardless
    expect($machine->definition->pendingParallelDispatches)->toBe([]);
});
