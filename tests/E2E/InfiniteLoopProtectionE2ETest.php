<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Exceptions\MaxTransitionDepthExceededException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\LoopMachines\AlwaysLoopMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\LoopMachines\AlwaysLoopOnTimerMachine;

// ═══════════════════════════════════════════════════════════════
//  Timer + Loop Protection
// ═══════════════════════════════════════════════════════════════

it('E2E: timer fires on loop machine — exception caught, machine unchanged', function (): void {
    $machine = AlwaysLoopOnTimerMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Machine should be in waiting state
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toBe('always_loop_timer.waiting');

    // Backdate past timer deadline
    MachineCurrentState::forInstance($rootEventId)
        ->update(['state_entered_at' => now()->subSeconds(10)]);

    // Run timer sweep — dispatches SendToMachineJob via Bus::batch.
    // In test (sync queue), the job executes inline and the exception propagates.
    // The exception is caught by Bus::batch allowFailures() — but in sync mode
    // it may still throw. We catch it to verify state is unchanged.
    try {
        $this->artisan('machine:process-timers', ['--class' => AlwaysLoopOnTimerMachine::class]);
    } catch (MaxTransitionDepthExceededException) {
        // Expected in sync queue mode — job ran inline
    }

    // Machine should still be in waiting state (exception prevented transition)
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toBe('always_loop_timer.waiting');
});

// ═══════════════════════════════════════════════════════════════
//  Exception + State Corruption
// ═══════════════════════════════════════════════════════════════

it('E2E: exception does not corrupt machine state', function (): void {
    $machine = AlwaysLoopMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    // Count events before
    $eventsBefore = MachineEvent::where('root_event_id', $rootEventId)->count();

    // Try to trigger the loop
    try {
        $machine->send(['type' => 'TRIGGER']);
    } catch (MaxTransitionDepthExceededException) {
        // Expected
    }

    // Machine should still be restorable at idle
    $restored = AlwaysLoopMachine::create(state: $rootEventId);
    expect($restored->state->currentStateDefinition->id)->toBe('always_loop.idle');

    // No new events should be persisted (transition was never committed)
    $eventsAfter = MachineEvent::where('root_event_id', $rootEventId)->count();
    expect($eventsAfter)->toBe($eventsBefore);
});

it('E2E: MaxTransitionDepthExceededException is catchable without side effects', function (): void {
    $machine = AlwaysLoopMachine::create();
    $machine->persist();
    $rootEventId = $machine->state->history->first()->root_event_id;

    $caught = false;

    try {
        $machine->send(['type' => 'TRIGGER']);
    } catch (MaxTransitionDepthExceededException $e) {
        $caught = true;
        expect($e)->toBeInstanceOf(LogicException::class)
            ->and($e->getMessage())->toContain('Maximum transition depth');
    }

    expect($caught)->toBeTrue();

    // State in DB unchanged
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toBe('always_loop.idle');
});
