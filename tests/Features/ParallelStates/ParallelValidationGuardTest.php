<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Exceptions\NoTransitionDefinitionFoundException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ValidationGuardParallelMachine;

uses(RefreshDatabase::class);

// region Validation Guard Failure — Expected Behavior After Fix

it('throws MachineValidationException when validation guard fails in parallel state', function (): void {
    $machine = ValidationGuardParallelMachine::create();

    expect(fn () => $machine->send(['type' => 'SUBMIT_ALWAYS_FAIL']))
        ->toThrow(MachineValidationException::class);
});

it('includes guard error message in MachineValidationException', function (): void {
    $machine = ValidationGuardParallelMachine::create();

    try {
        $machine->send(['type' => 'SUBMIT_ALWAYS_FAIL']);
        $this->fail('Expected MachineValidationException was not thrown');
    } catch (MachineValidationException $e) {
        $errors             = $e->errors();
        $hasExpectedMessage = collect($errors)->flatten()->contains('Validation always fails in parallel.');
        expect($hasExpectedMessage)->toBeTrue();
    }
});

it('records GUARD_FAIL and TRANSITION_FAIL events in history when validation guard fails in parallel', function (): void {
    $machine = ValidationGuardParallelMachine::create();

    try {
        $machine->send(['type' => 'SUBMIT_ALWAYS_FAIL']);
    } catch (MachineValidationException) {
        // expected
    }

    $history = $machine->state->history;

    $guardFail = $history->filter(
        fn ($event) => str_contains($event->type, 'guard.') && str_contains($event->type, '.fail')
    );
    expect($guardFail)->not->toBeEmpty();

    $transitionFail = $history->filter(
        fn ($event) => str_contains($event->type, 'transition.') && str_contains($event->type, '.fail')
    );
    expect($transitionFail)->not->toBeEmpty();
});

it('blocks ALL regions from transitioning when validation guard fails in any region', function (): void {
    $machine = ValidationGuardParallelMachine::create();

    try {
        $machine->send(['type' => 'SUBMIT_ALWAYS_FAIL']);
    } catch (MachineValidationException) {
        // expected
    }

    // Both regions must remain at their initial states
    $stateValue = $machine->state->value;
    expect($stateValue)->toContain('validation_guard_parallel.collecting.data_entry.awaiting_input');
    expect($stateValue)->toContain('validation_guard_parallel.collecting.review.pending_review');
});

it('throws MachineValidationException with TestMachine fluent API', function (): void {
    expect(
        fn () => ValidationGuardParallelMachine::test()
            ->send(['type' => 'SUBMIT_ALWAYS_FAIL'])
    )->toThrow(MachineValidationException::class);
});

// endregion

// region Conditional Validation Guard

it('transitions both regions when validation guard passes', function (): void {
    $machine = ValidationGuardParallelMachine::create();
    $machine->send(['type' => 'SUBMIT_DATA', 'payload' => ['value' => 10]]);

    expect($machine->state->currentStateDefinition->id)->toBe('validation_guard_parallel.completed');
});

it('throws MachineValidationException when conditional validation guard fails', function (): void {
    $machine = ValidationGuardParallelMachine::create();

    try {
        $machine->send(['type' => 'SUBMIT_DATA', 'payload' => ['value' => -5]]);
        $this->fail('Expected MachineValidationException was not thrown');
    } catch (MachineValidationException $e) {
        $hasExpectedMessage = collect($e->errors())->flatten()->contains('Value must be positive.');
        expect($hasExpectedMessage)->toBeTrue();

        // Both regions must remain unchanged
        $stateValue = $machine->state->value;
        expect($stateValue)->toContain('validation_guard_parallel.collecting.data_entry.awaiting_input');
        expect($stateValue)->toContain('validation_guard_parallel.collecting.review.pending_review');
    }
});

// endregion

// region Regression — Existing Behavior Preserved

it('throws NoTransitionDefinitionFoundException when regular guard fails in parallel', function (): void {
    $machine = ValidationGuardParallelMachine::create();

    expect(fn () => $machine->send(['type' => 'SUBMIT_REGULAR_GUARD_FAIL']))
        ->toThrow(NoTransitionDefinitionFoundException::class);
});

it('throws NoTransitionDefinitionFoundException for unknown events in parallel', function (): void {
    $machine = ValidationGuardParallelMachine::create();

    expect(fn () => $machine->send(['type' => 'UNKNOWN_EVENT']))
        ->toThrow(NoTransitionDefinitionFoundException::class);
});

// endregion
