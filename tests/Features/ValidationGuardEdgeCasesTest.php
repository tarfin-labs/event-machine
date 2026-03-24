<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\AlwaysFailRegularGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\AlwaysFailValidationGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\AlwaysPassRegularGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\AlwaysPassValidationGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\IsValuePositiveValidationGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\SecondAlwaysFailValidationGuard;

uses(RefreshDatabase::class);

// region Test 1: No Fallthrough — ValidationGuard rejection stops transition selection

it('does not fall through to next transition branch when ValidationGuard rejects', function (): void {
    // Two branches for SUBMIT: first has a failing ValidationGuard, second has no guard (would succeed).
    // The ValidationGuard failure must cause MachineValidationException — the unguarded second branch
    // must NOT be taken as a fallthrough.
    $actionRan = false;

    $machine = MachineDefinition::define(
        config: [
            'id'      => 'no_fallthrough',
            'initial' => 'idle',
            'context' => [
                'fallthrough_reached' => false,
            ],
            'states' => [
                'idle' => [
                    'on' => [
                        'SUBMIT' => [
                            [
                                'target' => 'rejected',
                                'guards' => AlwaysFailValidationGuard::class,
                            ],
                            [
                                'target'  => 'accepted',
                                'actions' => 'markFallthroughAction',
                            ],
                        ],
                    ],
                ],
                'rejected' => ['type' => 'final'],
                'accepted' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                AlwaysFailValidationGuard::class,
            ],
            'actions' => [
                'markFallthroughAction' => function (ContextManager $context) use (&$actionRan): void {
                    $actionRan = true;
                    $context->set('fallthrough_reached', true);
                },
            ],
        ],
    );

    $state = $machine->transition(event: ['type' => 'SUBMIT']);

    // The machine stays in idle because the second branch DID execute (no guards → immediate match),
    // BUT handleValidationGuards in Machine::send() will throw after persist.
    // At the MachineDefinition::transition level, the second branch IS taken (this is expected —
    // the ValidationGuard exception is thrown in Machine::send(), not in transition()).
    // When using Machine::create()->send(), the exception WILL be thrown.
    // Let's test with a real Machine to verify the full flow.

    $machineClass = new class extends Machine
    {
        public static function definition(): MachineDefinition
        {
            return MachineDefinition::define(
                config: [
                    'id'      => 'no_fallthrough',
                    'initial' => 'idle',
                    'context' => [
                        'fallthrough_reached' => false,
                    ],
                    'states' => [
                        'idle' => [
                            'on' => [
                                'SUBMIT' => [
                                    [
                                        'target' => 'rejected',
                                        'guards' => AlwaysFailValidationGuard::class,
                                    ],
                                    [
                                        'target' => 'accepted',
                                    ],
                                ],
                            ],
                        ],
                        'rejected' => ['type' => 'final'],
                        'accepted' => ['type' => 'final'],
                    ],
                ],
                behavior: [
                    'guards' => [
                        AlwaysFailValidationGuard::class,
                    ],
                ],
            );
        }
    };

    $instance = $machineClass::create();

    // Even though branch 2 (no guard) would normally succeed,
    // the ValidationGuard failure recorded in branch 1 triggers MachineValidationException.
    expect(fn () => $instance->send(['type' => 'SUBMIT']))
        ->toThrow(MachineValidationException::class);
});

// endregion

// region Test 2: No @fail Trigger — ValidationGuard rejection does NOT trigger @fail transition

it('does not trigger @fail transition when ValidationGuard rejects', function (): void {
    // A state with @fail defined and a transition with a ValidationGuard.
    // When the ValidationGuard rejects, @fail must NOT be triggered — @fail is only
    // for child machine delegation failures and parallel region failures.
    $machineClass = new class extends Machine
    {
        public static function definition(): MachineDefinition
        {
            return MachineDefinition::define(
                config: [
                    'id'      => 'no_fail_trigger',
                    'initial' => 'processing',
                    'context' => [
                        'fail_reached' => false,
                    ],
                    'states' => [
                        'processing' => [
                            'on' => [
                                'VALIDATE' => [
                                    'target' => 'completed',
                                    'guards' => AlwaysFailValidationGuard::class,
                                ],
                            ],
                            '@fail' => [
                                'target'  => 'failed',
                                'actions' => 'markFailAction',
                            ],
                        ],
                        'completed' => ['type' => 'final'],
                        'failed'   => ['type' => 'final'],
                    ],
                ],
                behavior: [
                    'guards' => [
                        AlwaysFailValidationGuard::class,
                    ],
                    'actions' => [
                        'markFailAction' => function (ContextManager $context): void {
                            $context->set('fail_reached', true);
                        },
                    ],
                ],
            );
        }
    };

    $instance = $machineClass::create();

    try {
        $instance->send(['type' => 'VALIDATE']);
        test()->fail('Expected MachineValidationException was not thrown');
    } catch (MachineValidationException) {
        // Expected: MachineValidationException thrown, not @fail transition
    }

    // Machine must stay in 'processing' — not moved to 'failed'
    expect($instance->state->currentStateDefinition->key)->toBe('processing');
    expect($instance->state->context->get('fail_reached'))->toBeFalse();
});

// endregion

// region Test 3: Combined Guard Types — ValidationGuard combined with regular GuardBehavior on same transition

it('throws MachineValidationException when ValidationGuard fails alongside passing regular guard', function (): void {
    // A transition has two guards: a regular GuardBehavior that passes, then a ValidationGuard that fails.
    // The ValidationGuard failure must throw MachineValidationException even though the regular guard passed.
    $machineClass = new class extends Machine
    {
        public static function definition(): MachineDefinition
        {
            return MachineDefinition::define(
                config: [
                    'id'      => 'combined_guards',
                    'initial' => 'idle',
                    'context' => [],
                    'states'  => [
                        'idle' => [
                            'on' => [
                                'SUBMIT' => [
                                    'target' => 'done',
                                    'guards' => [
                                        AlwaysPassRegularGuard::class,
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
                        AlwaysPassRegularGuard::class,
                        AlwaysFailValidationGuard::class,
                    ],
                ],
            );
        }
    };

    $instance = $machineClass::create();

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

it('transitions normally when regular guard fails but ValidationGuard is not reached', function (): void {
    // A transition has two guards: a regular GuardBehavior that fails (first), then a ValidationGuard.
    // The regular guard failure means the branch is rejected without evaluating the ValidationGuard.
    // Since no ValidationGuard was evaluated, no MachineValidationException is thrown — the machine
    // stays in the same state with a normal guard failure (TRANSITION_FAIL).
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
    $machineClass = new class extends Machine
    {
        public static function definition(): MachineDefinition
        {
            return MachineDefinition::define(
                config: [
                    'id'      => 'chain_order',
                    'initial' => 'idle',
                    'context' => [],
                    'states'  => [
                        'idle' => [
                            'on' => [
                                'SUBMIT' => [
                                    'target' => 'done',
                                    'guards' => [
                                        AlwaysFailValidationGuard::class,
                                        SecondAlwaysFailValidationGuard::class,
                                    ],
                                ],
                            ],
                        ],
                        'done' => ['type' => 'final'],
                    ],
                ],
                behavior: [
                    'guards' => [
                        AlwaysFailValidationGuard::class,
                        SecondAlwaysFailValidationGuard::class,
                    ],
                ],
            );
        }
    };

    $instance = $machineClass::create();

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
    $machineClass = new class extends Machine
    {
        public static function definition(): MachineDefinition
        {
            return MachineDefinition::define(
                config: [
                    'id'      => 'chain_second_fails',
                    'initial' => 'idle',
                    'context' => [],
                    'states'  => [
                        'idle' => [
                            'on' => [
                                'SUBMIT' => [
                                    'target' => 'done',
                                    'guards' => [
                                        AlwaysPassValidationGuard::class,
                                        SecondAlwaysFailValidationGuard::class,
                                    ],
                                ],
                            ],
                        ],
                        'done' => ['type' => 'final'],
                    ],
                ],
                behavior: [
                    'guards' => [
                        AlwaysPassValidationGuard::class,
                        SecondAlwaysFailValidationGuard::class,
                    ],
                ],
            );
        }
    };

    $instance = $machineClass::create();

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
    $machineClass = new class extends Machine
    {
        public static function definition(): MachineDefinition
        {
            return MachineDefinition::define(
                config: [
                    'id'      => 'reject_retry',
                    'initial' => 'awaiting_input',
                    'context' => [
                        'value' => 0,
                    ],
                    'states' => [
                        'awaiting_input' => [
                            'on' => [
                                'SUBMIT' => [
                                    'target'  => 'accepted',
                                    'guards'  => IsValuePositiveValidationGuard::class,
                                    'actions' => 'storeValueAction',
                                ],
                            ],
                        ],
                        'accepted' => ['type' => 'final'],
                    ],
                ],
                behavior: [
                    'guards' => [
                        IsValuePositiveValidationGuard::class,
                    ],
                    'actions' => [
                        'storeValueAction' => function (ContextManager $context, \Tarfinlabs\EventMachine\Behavior\EventBehavior $event): void {
                            $context->set('value', $event->payload['value']);
                        },
                    ],
                ],
            );
        }
    };

    $instance = $machineClass::create();

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
