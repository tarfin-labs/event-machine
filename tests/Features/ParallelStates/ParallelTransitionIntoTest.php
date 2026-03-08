<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ParallelDispatchViaEventMachine;

uses(RefreshDatabase::class);

afterEach(function (): void {
    config()->set('machine.parallel_dispatch.enabled', false);
});

// ============================================================
// Group 1: Transition into parallel state enters all regions
// ============================================================

it('enters all regions when transitioning into a parallel state via send()', function (): void {
    $machine = ParallelDispatchViaEventMachine::create();

    // Initially at idle
    expect($machine->state->matches('idle'))->toBeTrue();

    // Transition into parallel state
    $machine->send(['type' => 'START_PROCESSING']);

    // Should be in parallel state with both regions active
    expect($machine->state->isInParallelState())->toBeTrue();
    expect($machine->state->matches('processing.region_a.working'))->toBeTrue();
    expect($machine->state->matches('processing.region_b.working'))->toBeTrue();
});

it('runs region entry actions when transitioning into parallel state', function (): void {
    $machine = ParallelDispatchViaEventMachine::create();
    $machine->send(['type' => 'START_PROCESSING']);

    // Entry actions should have set context values
    expect($machine->state->context->get('region_a_result'))->toBe('processed_by_a');
    expect($machine->state->context->get('region_b_result'))->toBe('processed_by_b');
});

// ============================================================
// Group 2: Parallel dispatch via send()
// ============================================================

it('populates pending dispatches when transitioning into parallel state with dispatch enabled', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchViaEventMachine::create();
    $machine->send(['type' => 'START_PROCESSING']);

    // In dispatch mode, entry actions should NOT have run (they are dispatched)
    expect($machine->state->context->get('region_a_result'))->toBeNull();
    expect($machine->state->context->get('region_b_result'))->toBeNull();

    // Machine should be in parallel state
    expect($machine->state->isInParallelState())->toBeTrue();
});

it('sets dispatched flag when transitioning into parallel state via send()', function (): void {
    config()->set('machine.parallel_dispatch.enabled', true);

    $machine = ParallelDispatchViaEventMachine::create();
    $machine->send(['type' => 'START_PROCESSING']);

    expect($machine->dispatched)->toBeTrue();
});

// ============================================================
// Group 3: Region events work after transition into parallel
// ============================================================

it('can send region events after transitioning into parallel state', function (): void {
    $machine = ParallelDispatchViaEventMachine::create();
    $machine->send(['type' => 'START_PROCESSING']);

    // Now send events to individual regions
    $machine->send(['type' => 'REGION_A_DONE']);
    expect($machine->state->matches('processing.region_a.finished'))->toBeTrue();
    expect($machine->state->matches('processing.region_b.working'))->toBeTrue();

    $machine->send(['type' => 'REGION_B_DONE']);

    // Both regions final → onDone → completed
    expect($machine->state->matches('completed'))->toBeTrue();
});

// ============================================================
// Group 4: Persistence after transition into parallel
// ============================================================

it('persists and restores after transitioning into parallel state', function (): void {
    $machine = ParallelDispatchViaEventMachine::create();
    $machine->send(['type' => 'START_PROCESSING']);

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Restore from DB
    $restored = ParallelDispatchViaEventMachine::create(state: $rootEventId);

    expect($restored->state->isInParallelState())->toBeTrue();
    expect($restored->state->matches('processing.region_a.working'))->toBeTrue();
    expect($restored->state->matches('processing.region_b.working'))->toBeTrue();
    expect($restored->state->context->get('region_a_result'))->toBe('processed_by_a');
    expect($restored->state->context->get('region_b_result'))->toBe('processed_by_b');
});
