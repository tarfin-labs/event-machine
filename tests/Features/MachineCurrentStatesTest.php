<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TimerMachines\AfterTimerMachine;

// ─── Basic Sync ──────────────────────────────────────────────────

it('creates current state row on persist', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    $rows = MachineCurrentState::forInstance($rootEventId)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->machine_class)->toBe(AfterTimerMachine::class)
        ->and($rows->first()->state_id)->toBe('after_timer.awaiting_payment')
        ->and($rows->first()->state_entered_at)->not->toBeNull();
});

it('updates current state row on transition', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Transition to processing
    $machine->send(['type' => 'PAY']);
    $machine->persist();

    $rows = MachineCurrentState::forInstance($rootEventId)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->state_id)->toBe('after_timer.processing');
});

it('removes old state row and adds new one on transition', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Capture original state_entered_at
    $originalEntry = MachineCurrentState::forInstance($rootEventId)->first()->state_entered_at;

    // Small delay to ensure timestamps differ
    sleep(1);

    // Transition
    $machine->send(['type' => 'PAY']);
    $machine->persist();

    $newEntry = MachineCurrentState::forInstance($rootEventId)->first()->state_entered_at;

    // state_entered_at should be different (new state)
    expect($newEntry->greaterThan($originalEntry))->toBeTrue();
});

it('preserves state_entered_at on self-loop (no state change)', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();

    $rootEventId   = $machine->state->history->first()->root_event_id;
    $originalEntry = MachineCurrentState::forInstance($rootEventId)->first()->state_entered_at;

    // Persist again without state change (self-loop equivalent)
    $machine->persist();

    $afterEntry = MachineCurrentState::forInstance($rootEventId)->first()->state_entered_at;

    // state_entered_at should be the same (no state change)
    expect($afterEntry->equalTo($originalEntry))->toBeTrue();
});

it('deletes current state rows when machine reaches final state', function (): void {
    $machine = AfterTimerMachine::create();
    $machine->persist();

    $rootEventId = $machine->state->history->first()->root_event_id;

    expect(MachineCurrentState::forInstance($rootEventId)->count())->toBe(1);

    // Transition to final
    $machine->send(['type' => 'PAY']);
    $machine->send(['type' => 'COMPLETE']);
    $machine->persist();

    // Final state still tracked (it's a valid state)
    $rows = MachineCurrentState::forInstance($rootEventId)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->state_id)->toBe('after_timer.completed');
});

it('handles forSweep scope correctly', function (): void {
    // Create two machines
    $machine1 = AfterTimerMachine::create();
    $machine1->persist();

    $machine2 = AfterTimerMachine::create();
    $machine2->persist();

    // Transition machine2 to processing
    $machine2->send(['type' => 'PAY']);
    $machine2->persist();

    // Sweep for awaiting_payment should find only machine1
    $awaiting = MachineCurrentState::forSweep(AfterTimerMachine::class, 'after_timer.awaiting_payment')->get();
    expect($awaiting)->toHaveCount(1);

    // Sweep for processing should find only machine2
    $processing = MachineCurrentState::forSweep(AfterTimerMachine::class, 'after_timer.processing')->get();
    expect($processing)->toHaveCount(1);
});
