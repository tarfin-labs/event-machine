<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tarfinlabs\EventMachine\Models\MachineEvent;
use Tarfinlabs\EventMachine\Models\MachineCurrentState;
use Tarfinlabs\EventMachine\Tests\LocalQA\LocalQATestCase;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ValidationGuardParallelMachine;

uses(LocalQATestCase::class);

beforeEach(function (): void {
    LocalQATestCase::cleanTables();
});

// ═══════════════════════════════════════════════════════════════
//  ValidationGuardBehavior in Parallel States — Real MySQL
// ═══════════════════════════════════════════════════════════════

it('LocalQA: validation guard failure persists GUARD_FAIL + TRANSITION_FAIL events', function (): void {
    $machine = ValidationGuardParallelMachine::create();

    $rootEventId = $machine->state->history->first()->root_event_id;

    try {
        $machine->send(['type' => 'SUBMIT_ALWAYS_FAIL']);
    } catch (MachineValidationException) {
        // expected
    }

    // Query machine_events directly from MySQL
    $events = MachineEvent::where('root_event_id', $rootEventId)
        ->pluck('type')
        ->toArray();

    // GUARD_FAIL event must be persisted
    $guardFails = collect($events)->filter(fn (string $type) => str_contains($type, 'guard.') && str_contains($type, '.fail'));
    expect($guardFails)->not->toBeEmpty();

    // TRANSITION_FAIL event must be persisted
    $transitionFails = collect($events)->filter(fn (string $type) => str_contains($type, 'transition.') && str_contains($type, '.fail'));
    expect($transitionFails)->not->toBeEmpty();

    // Machine should still be in parallel state
    $cs = MachineCurrentState::where('root_event_id', $rootEventId)->first();
    expect($cs->state_id)->toContain('collecting');
});

it('LocalQA: validation guard failure followed by successful retry transitions normally', function (): void {
    $machine = ValidationGuardParallelMachine::create();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // First attempt: validation fails
    try {
        $machine->send(['type' => 'SUBMIT_DATA', 'payload' => ['value' => -5]]);
    } catch (MachineValidationException) {
        // expected
    }

    // Machine still in parallel state
    expect($machine->state->value)->toContain('validation_guard_parallel.collecting.data_entry.awaiting_input');

    // Second attempt: validation passes
    $machine->send(['type' => 'SUBMIT_DATA', 'payload' => ['value' => 10]]);

    // Machine should have transitioned past parallel
    expect($machine->state->currentStateDefinition->id)->toBe('validation_guard_parallel.completed');

    // Event history shows both attempts
    $events = MachineEvent::where('root_event_id', $rootEventId)->pluck('type')->toArray();
    $guardFails  = collect($events)->filter(fn (string $type) => str_contains($type, 'guard.') && str_contains($type, '.fail'));
    $guardPasses = collect($events)->filter(fn (string $type) => str_contains($type, 'guard.') && str_contains($type, '.pass'));
    expect($guardFails)->not->toBeEmpty();
    expect($guardPasses)->not->toBeEmpty();
});

it('LocalQA: validation guard failure does not leave orphan locks', function (): void {
    $machine = ValidationGuardParallelMachine::create();

    $rootEventId = $machine->state->history->first()->root_event_id;

    // Trigger validation failure
    try {
        $machine->send(['type' => 'SUBMIT_ALWAYS_FAIL']);
    } catch (MachineValidationException) {
        // expected
    }

    // No orphan locks
    $lockCount = DB::table('machine_locks')
        ->where('root_event_id', $rootEventId)
        ->count();
    expect($lockCount)->toBe(0);

    // Can send another event without deadlock
    try {
        $machine->send(['type' => 'SUBMIT_ALWAYS_FAIL']);
    } catch (MachineValidationException) {
        // expected — still fails validation but no deadlock
    }

    // Machine is still responsive
    expect($machine->state->value)->toContain('validation_guard_parallel.collecting.data_entry.awaiting_input');
});
