<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\ChainOrderMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\RejectRetryMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\NoFailTriggerMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\NoFallthroughMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\CombinedGuardsMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\ChainSecondFailsMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\AlwaysFailRegularGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\AlwaysFailValidationGuard;

uses(RefreshDatabase::class);

// region Test 1: No Fallthrough — ValidationGuard rejection stops transition selection

it('does not fall through to next transition branch when ValidationGuard rejects', function (): void {
    // Two branches for SUBMIT: first has a failing ValidationGuard, second has no guard.
    // Even though branch 2 would normally succeed via fallthrough, the ValidationGuard
    // failure recorded in branch 1 causes handleValidationGuards to throw MachineValidationException.
    $instance = NoFallthroughMachine::create();

    expect(fn () => $instance->send(['type' => 'SUBMIT']))
        ->toThrow(MachineValidationException::class);
});

// endregion

// region Test 2: No @fail Trigger — ValidationGuard rejection does NOT trigger @fail transition

it('does not trigger @fail transition when ValidationGuard rejects', function (): void {
    // A state with @fail defined and a transition with a ValidationGuard.
    // When the ValidationGuard rejects, @fail must NOT be triggered — @fail is only
    // for child machine delegation failures and parallel region failures.
    $instance = NoFailTriggerMachine::create();

    try {
        $instance->send(['type' => 'VALIDATE']);
        test()->fail('Expected MachineValidationException was not thrown');
    } catch (MachineValidationException) {
        // Expected: MachineValidationException thrown, not @fail transition
    }

    // Machine must stay in 'processing' — not moved to 'failed'
    expect($instance->state->currentStateDefinition->key)->toBe('processing');
    expect($instance->state->context->get('failReached'))->toBeFalse();
});

// endregion

// region Test 3: Combined Guard Types — ValidationGuard combined with regular GuardBehavior on same transition

it('throws MachineValidationException when ValidationGuard fails alongside passing regular guard', function (): void {
    // A transition has two guards: a regular GuardBehavior that passes, then a ValidationGuard that fails.
    // The ValidationGuard failure must throw MachineValidationException even though the regular guard passed.
    $instance = CombinedGuardsMachine::create();

    try {
        $instance->send(['type' => 'SUBMIT']);
        test()->fail('Expected MachineValidationException was not thrown');
    } catch (MachineValidationException $e) {
        $hasExpectedMessage = collect($e->errors())->flatten()->contains('Validation always fails.');
        expect($hasExpectedMessage)->toBeTrue();
    }

    // Machine stays in idle
    expect($instance->state->currentStateDefinition->key)->toBe('idle');
});

it('does not throw MachineValidationException when regular guard fails before ValidationGuard', function (): void {
    // A transition has two guards: a regular GuardBehavior that fails (first), then a ValidationGuard.
    // The regular guard failure means the branch is rejected without evaluating the ValidationGuard.
    // Since no ValidationGuard was evaluated, no MachineValidationException is thrown.
    $machine = MachineDefinition::define(
        config: [
            'id'      => 'regular_guard_first',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => [
                        'SUBMIT' => [
                            'target' => 'done',
                            'guards' => [
                                AlwaysFailRegularGuard::class,
                                AlwaysFailValidationGuard::class,
                            ],
                        ],
                    ],
                ],
                'done' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                AlwaysFailRegularGuard::class,
                AlwaysFailValidationGuard::class,
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'SUBMIT']);

    // Machine stays in idle — no MachineValidationException because the regular guard
    // failed first and broke out of the guard chain before the ValidationGuard ran.
    expect($state->currentStateDefinition->key)->toBe('idle');
});

// endregion

// region Test 4: Chain Order — Multiple ValidationGuards on same transition, first failure stops chain

it('stops at first failing ValidationGuard in a chain of multiple ValidationGuards', function (): void {
    // Two ValidationGuards on the same transition: first always fails, second also always fails.
    // Only the FIRST ValidationGuard's error message should appear — the second is never evaluated.
    $instance = ChainOrderMachine::create();

    try {
        $instance->send(['type' => 'SUBMIT']);
        test()->fail('Expected MachineValidationException was not thrown');
    } catch (MachineValidationException $e) {
        $allErrors = collect($e->errors())->flatten();

        // First guard's message IS present
        expect($allErrors->contains('Validation always fails.'))->toBeTrue();

        // Second guard's message is NOT present — chain broke at first failure
        expect($allErrors->contains('Second validation also fails.'))->toBeFalse();
    }

    // Machine stays in idle
    expect($instance->state->currentStateDefinition->key)->toBe('idle');
});

it('evaluates second ValidationGuard when first passes', function (): void {
    // Two ValidationGuards: first passes, second fails. The second's error message should appear.
    $instance = ChainSecondFailsMachine::create();

    try {
        $instance->send(['type' => 'SUBMIT']);
        test()->fail('Expected MachineValidationException was not thrown');
    } catch (MachineValidationException $e) {
        $allErrors = collect($e->errors())->flatten();

        // Second guard's message IS present
        expect($allErrors->contains('Second validation also fails.'))->toBeTrue();
    }
});

// endregion

// region Test 5: Reject-Retry — After ValidationGuard rejection, machine stays and can retry with valid data

it('allows retry after ValidationGuard rejection with corrected data', function (): void {
    $instance = RejectRetryMachine::create();

    // First attempt: negative value — should throw MachineValidationException
    try {
        $instance->send(['type' => 'SUBMIT', 'payload' => ['value' => -5]]);
        test()->fail('Expected MachineValidationException was not thrown');
    } catch (MachineValidationException $e) {
        $hasExpectedMessage = collect($e->errors())->flatten()->contains('Value must be positive.');
        expect($hasExpectedMessage)->toBeTrue();
    }

    // Machine must still be in awaiting_input
    expect($instance->state->currentStateDefinition->key)->toBe('awaiting_input');
    expect($instance->state->context->get('value'))->toBe(0);

    // Second attempt: positive value — should succeed
    $instance->send(['type' => 'SUBMIT', 'payload' => ['value' => 42]]);

    expect($instance->state->currentStateDefinition->key)->toBe('accepted');
    expect($instance->state->context->get('value'))->toBe(42);
});

// endregion
