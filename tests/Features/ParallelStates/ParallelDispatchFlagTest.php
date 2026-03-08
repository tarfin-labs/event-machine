<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

// ============================================================
// Grup 1: dispatched flag default
// ============================================================

it('dispatched flag is false by default', function (): void {
    $machine = ParallelDispatchMachine::create();

    expect($machine->dispatched)->toBeFalse();
});

// ============================================================
// Grup 2: dispatched flag set after dispatchPendingParallelJobs
// ============================================================

it('dispatched flag is true after dispatchPendingParallelJobs', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();

    expect($machine->dispatched)->toBeFalse();

    $machine->dispatchPendingParallelJobs();

    expect($machine->dispatched)->toBeTrue();
});

// ============================================================
// Grup 3: dispatched flag false in sequential mode
// ============================================================

it('dispatched flag is false in sequential mode (no pending dispatches)', function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $machine->dispatchPendingParallelJobs();

    // Sequential mode: no pending dispatches were populated
    expect($machine->dispatched)->toBeFalse();
    expect($machine->state->isInParallelState())->toBeTrue();
});

// ============================================================
// Grup 4: Controller pattern — distinguish sync vs async
// ============================================================

it('controller can distinguish sync completion from async dispatch', function (): void {
    // Scenario 1: Dispatch enabled → async
    config()->set('machine.parallel_dispatch.enabled', true);

    $asyncMachine = ParallelDispatchMachine::create();
    $asyncMachine->persist();
    $asyncMachine->dispatchPendingParallelJobs();

    expect($asyncMachine->dispatched)->toBeTrue();

    // Scenario 2: Dispatch disabled → sync
    config()->set('machine.parallel_dispatch.enabled', false);

    $syncMachine = ParallelDispatchMachine::create();
    $syncMachine->persist();
    $syncMachine->dispatchPendingParallelJobs();

    expect($syncMachine->dispatched)->toBeFalse();
});

// ============================================================
// Grup 5: dispatched flag after restore (not dispatching)
// ============================================================

it('dispatched flag is false when machine is restored from DB', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $machine->dispatchPendingParallelJobs();

    expect($machine->dispatched)->toBeTrue();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Restore from DB — no dispatch happens
    $restored = ParallelDispatchMachine::create(state: $rootEventId);

    expect($restored->dispatched)->toBeFalse();
});

// ============================================================
// Grup 6: dispatched flag not set when no pending dispatches
// ============================================================

it('dispatched flag stays false when dispatchPendingParallelJobs has nothing to dispatch', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchMachine::create();
    $machine->persist();
    $machine->dispatchPendingParallelJobs(); // dispatches and clears

    expect($machine->dispatched)->toBeTrue();

    // Reset for next assertion
    $machine->dispatched = false;

    // Second call — nothing pending
    $machine->dispatchPendingParallelJobs();

    expect($machine->dispatched)->toBeFalse();
});
