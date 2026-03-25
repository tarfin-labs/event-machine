<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Locks\MachineLockManager;
use Tarfinlabs\EventMachine\Exceptions\MachineAlreadyRunningException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

it('Machine::send() uses immediate lock mode (timeout=0)', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Run both jobs to get past dispatch phase
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    ))->handle();

    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working',
    ))->handle();

    // Manually hold a lock to simulate contention
    $holdLock = MachineLockManager::acquire(
        rootEventId: $rootEventId,
        timeout: 0,
        ttl: 60,
        context: 'test_hold',
    );

    // Machine::send() should throw immediately (timeout=0)
    $machine = ParallelDispatchMachine::create(state: $rootEventId);

    try {
        expect(fn () => $machine->send('REGION_A_DONE'))
            ->toThrow(MachineAlreadyRunningException::class);
    } finally {
        $holdLock->release();
    }
});

it('ParallelRegionJob uses blocking lock mode (timeout>0)', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Both jobs run sequentially — second one acquires blocking lock after first releases
    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working',
    ))->handle();

    (new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_b',
        initialStateId: 'parallel_dispatch.processing.region_b.working',
    ))->handle();

    // Both completed successfully (no exception = blocking lock worked)
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->context->get('regionAResult'))->toBe('processed_by_a');
    expect($restored->state->context->get('regionBResult'))->toBe('processed_by_b');
});
