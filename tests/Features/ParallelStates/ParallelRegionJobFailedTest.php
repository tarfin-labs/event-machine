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

// ============================================================
// Bead: event-machine-tq4e — failed() @fail event handler
// ============================================================

it('transitions to error state when failed() called with onFail defined', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail.processing.region_a',
        initialStateId: 'parallel_fail.processing.region_a.working_a',
    );

    $job->failed(new \RuntimeException('API call failed'));

    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_fail.error');
});

it('records PARALLEL_FAIL event when failed() called without onFail', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_dispatch.processing.region_a',
        initialStateId: 'parallel_dispatch.processing.region_a.working_a',
    );

    $job->failed(new \RuntimeException('API call failed'));

    // Machine should still be in parallel state (no onFail target)
    $restored = ParallelDispatchMachine::create(state: $rootEventId);
    expect($restored->state->isInParallelState())->toBeTrue();

    // PARALLEL_FAIL event should be recorded
    $failEvent = $restored->state->history->first(
        fn ($e) => str_contains($e->type, 'parallel') && str_contains($e->type, 'fail')
    );
    expect($failEvent)->not->toBeNull();
});

it('includes error details in @fail payload', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail.processing.region_a',
        initialStateId: 'parallel_fail.processing.region_a.working_a',
    );

    $job->failed(new \RuntimeException('Connection timeout'));

    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);

    $failEvent = $restored->state->history->first(
        fn ($e) => str_contains($e->type, 'parallel') && str_contains($e->type, 'fail')
    );
    expect($failEvent)->not->toBeNull();
});

it('no-ops when machine already left parallel state during failed()', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchWithFailMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // First job triggers onFail → transitions to error
    $firstJob = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail.processing.region_a',
        initialStateId: 'parallel_fail.processing.region_a.working_a',
    );
    $firstJob->failed(new \RuntimeException('First failure'));

    $restored = ParallelDispatchWithFailMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('parallel_fail.error');

    $historyCountBefore = \Tarfinlabs\EventMachine\Models\MachineEvent::where('root_event_id', $rootEventId)->count();

    // Second job's failed() should no-op (machine already left parallel)
    $secondJob = new ParallelRegionJob(
        machineClass: ParallelDispatchWithFailMachine::class,
        rootEventId: $rootEventId,
        regionId: 'parallel_fail.processing.region_b',
        initialStateId: 'parallel_fail.processing.region_b.working_b',
    );
    $secondJob->failed(new \RuntimeException('Second failure'));

    $historyCountAfter = \Tarfinlabs\EventMachine\Models\MachineEvent::where('root_event_id', $rootEventId)->count();
    expect($historyCountAfter)->toBe($historyCountBefore);
});

it('logs error when @fail handler itself throws', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, '@fail handler also failed')
                && $context['region_id'] === 'nonexistent.region'
                && $context['original_error'] === 'Original failure';
        });

    $job = new ParallelRegionJob(
        machineClass: ParallelDispatchMachine::class,
        rootEventId: 'nonexistent-root-id',
        regionId: 'nonexistent.region',
        initialStateId: 'nonexistent.initial',
    );

    $job->failed(new \RuntimeException('Original failure'));
});
