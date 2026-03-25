<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

it('dispatch mode correctly defers entry actions when enabled', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();

    // In dispatch mode, entry actions are deferred
    expect($machine->state->context->get('regionAResult'))->toBeNull();
    expect($machine->state->context->get('regionBResult'))->toBeNull();
    expect($machine->definition->pendingParallelDispatches)->toHaveCount(2);
});

it('sequential mode runs entry actions inline', function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);

    $machine = ParallelDispatchMachine::create();

    // In sequential mode, entry actions run immediately
    expect($machine->state->context->get('regionAResult'))->toBe('processed_by_a');
    expect($machine->state->context->get('regionBResult'))->toBe('processed_by_b');
    expect($machine->definition->pendingParallelDispatches)->toBe([]);
});

it('Bus::fake prevents actual dispatch while allowing lifecycle test', function (): void {
    Bus::fake();
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $machine->dispatchPendingParallelJobs();

    Bus::assertDispatched(ParallelRegionJob::class, 2);
});
