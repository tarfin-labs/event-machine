<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ValidationGuardDispatchIsolationMachine;

uses(RefreshDatabase::class);

// ═══════════════════════════════════════════════════════════════
//  ValidationGuard failure in one region does NOT corrupt sibling
// ═══════════════════════════════════════════════════════════════

it('throws MachineValidationException and leaves sibling region state untouched', function (): void {
    $machine = ValidationGuardDispatchIsolationMachine::create();

    // Initial state: both regions at their initial sub-states
    $stateValue = $machine->state->value;
    expect($stateValue)->toContain('vg_dispatch_isolation.collecting.region_with_guard.awaiting_input');
    expect($stateValue)->toContain('vg_dispatch_isolation.collecting.region_without_guard.idle');

    // Context should be at initial values
    expect($machine->state->context->get('guarded_region_entered'))->toBeFalse();
    expect($machine->state->context->get('sibling_region_entered'))->toBeFalse();

    // Send event that triggers ValidationGuard failure in one region
    try {
        $machine->send(['type' => 'SUBMIT']);
        test()->fail('Expected MachineValidationException was not thrown');
    } catch (MachineValidationException $e) {
        $hasExpectedMessage = collect($e->errors())->flatten()->contains('Guarded region rejects submission.');
        expect($hasExpectedMessage)->toBeTrue('Expected validation error message not found');
    }

    // Sibling region's state must be completely untouched — still at initial
    $stateValue = $machine->state->value;
    expect($stateValue)->toContain('vg_dispatch_isolation.collecting.region_with_guard.awaiting_input');
    expect($stateValue)->toContain('vg_dispatch_isolation.collecting.region_without_guard.idle');

    // Neither region's entry actions should have executed
    expect($machine->state->context->get('guarded_region_entered'))->toBeFalse();
    expect($machine->state->context->get('sibling_region_entered'))->toBeFalse();

    // Machine should NOT have advanced to completed
    expect($machine->state->currentStateDefinition->id)->not->toBe('vg_dispatch_isolation.completed');
});

it('sibling region context is pristine after validation guard rejection', function (): void {
    $machine = ValidationGuardDispatchIsolationMachine::create();

    // Capture context snapshot before the failed send
    $contextBefore = $machine->state->context->toArray();

    try {
        $machine->send(['type' => 'SUBMIT']);
    } catch (MachineValidationException) {
        // expected
    }

    // Context must be identical to before — no partial writes from either region
    $contextAfter = $machine->state->context->toArray();
    expect($contextAfter)->toBe($contextBefore);
});

it('sibling region transitions normally after a prior validation guard rejection', function (): void {
    $machine = ValidationGuardDispatchIsolationMachine::create();

    // First: rejected send
    try {
        $machine->send(['type' => 'SUBMIT']);
    } catch (MachineValidationException) {
        // expected
    }

    // Second: valid send that passes the guard
    $machine->send(['type' => 'SUBMIT_VALID']);

    // Both regions should now have transitioned to final states
    expect($machine->state->currentStateDefinition->id)->toBe('vg_dispatch_isolation.completed');

    // Both regions' entry actions should have run exactly once
    expect($machine->state->context->get('guarded_region_entered'))->toBeTrue();
    expect($machine->state->context->get('sibling_region_entered'))->toBeTrue();
});

it('MachineValidationException is thrown via TestMachine fluent API', function (): void {
    expect(
        fn () => ValidationGuardDispatchIsolationMachine::test()
            ->send(['type' => 'SUBMIT'])
    )->toThrow(MachineValidationException::class);
});

it('multiple consecutive validation guard failures do not accumulate side effects in sibling region', function (): void {
    $machine = ValidationGuardDispatchIsolationMachine::create();

    // Send the failing event multiple times
    for ($i = 0; $i < 3; $i++) {
        try {
            $machine->send(['type' => 'SUBMIT']);
        } catch (MachineValidationException) {
            // expected each time
        }
    }

    // Sibling region state must still be at initial
    $stateValue = $machine->state->value;
    expect($stateValue)->toContain('vg_dispatch_isolation.collecting.region_without_guard.idle');

    // Context must remain pristine — no accumulated side effects
    expect($machine->state->context->get('guarded_region_entered'))->toBeFalse();
    expect($machine->state->context->get('sibling_region_entered'))->toBeFalse();
});
