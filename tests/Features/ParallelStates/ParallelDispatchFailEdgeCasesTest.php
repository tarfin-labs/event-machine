<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Jobs\ParallelRegionJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchWithFailMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

it('@fail handler throws → last-resort logging', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, '@fail handler also failed')
                && $context['machine_class'] === ParallelDispatchMachine::class
                && $context['root_event_id'] === 'nonexistent-root-id'
                && $context['region_id'] === 'nonexistent.region'
                && $context['original_error'] === 'Original failure'
                && isset($context['fail_handler_error']);
        });

    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: 'nonexistent-root-id',
        regionId: 'nonexistent.region',
        initialStateId: 'nonexistent.initial',
    );

    // This will fail to reconstruct machine → catch logs both errors
    $job->failed(new RuntimeException('Original failure'));
});

it('multiple sequential failures all handled correctly', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // First failure → transitions to error
    $job1 = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_a',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_a.working',
    );
    $job1->failed(new RuntimeException('First failure'));

    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_dispatch_with_fail.failed');

    // Second failure → no-op (already in error)
    $job2 = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_b',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_b.working',
    );
    $job2->failed(new RuntimeException('Second failure'));

    // Still in error state, not crashed
    $final = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($final->state->currentStateDefinition->id)->toBe('parallel_dispatch_with_fail.failed');
});

it('failed job with handle completing before failed() → no double processing', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Region A completes successfully first
    (new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_a',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_a.working',
    ))->handle();

    // Region B fails
    $jobB = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch_with_fail.processing.region_b',
        initialStateId: 'parallel_dispatch_with_fail.processing.region_b.working',
    );
    $jobB->failed(new RuntimeException('Region B failure'));

    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_dispatch_with_fail.failed');

    // Region A's context preserved
    expect($restored->state->context->get('regionAResult'))->toBe('processed_by_a');
});
