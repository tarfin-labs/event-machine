<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\AlwaysFailGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ConditionalOnDoneMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\LogApprovalAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\IsAllSucceededGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\NotifyReviewerAction;

// Test 1: Backward compat — simple string @done still works
test('it resolves simple string @done unchanged', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test_string_done',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_B' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    expect($state->value)->toBe(['test_string_done.completed']);
});

// Test 2: Backward compat — array @done with target and actions
test('it resolves array @done with target and actions unchanged', function (): void {
    LogApprovalAction::reset();

    $definition = MachineDefinition::define([
        'id'      => 'test_array_done',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => ['target' => 'completed', 'actions' => LogApprovalAction::class],
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_B' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'completed' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    expect($state->value)->toBe(['test_array_done.completed'])
        ->and(LogApprovalAction::wasExecuted())->toBeTrue();
});

// Test 3: Conditional — first guard passes → first target
test('it transitions to first matching guard branch on @done', function (): void {
    LogApprovalAction::reset();
    NotifyReviewerAction::reset();

    $machine = ConditionalOnDoneMachine::create();

    // Both regions set success results via entry actions
    $machine->send(['type' => 'INVENTORY_CHECKED']);
    $state = $machine->send(['type' => 'PAYMENT_VALIDATED']);

    // Guard passes (both success) → approved branch with LogApprovalAction
    expect($state->value)->toBe(['conditional_on_done.approved'])
        ->and(LogApprovalAction::wasExecuted())->toBeTrue()
        ->and(NotifyReviewerAction::wasExecuted())->toBeFalse();
});

// Test 4: First guard fails → fallback to default branch
test('it falls through to default branch when first guard fails', function (): void {
    LogApprovalAction::reset();
    NotifyReviewerAction::reset();

    $definition = MachineDefinition::define([
        'id'      => 'test_fallback',
        'initial' => 'processing',
        'context' => [
            'inventory_result'  => null,
            'payment_result'    => null,
            'reviewer_notified' => false,
        ],
        'states' => [
            'processing' => [
                'type'  => 'parallel',
                '@done' => [
                    ['target' => 'approved',      'guards' => IsAllSucceededGuard::class],
                    ['target' => 'manual_review', 'actions' => NotifyReviewerAction::class],
                ],
                'states' => [
                    'inventory' => [
                        'initial' => 'checking',
                        'states'  => [
                            'checking' => ['on' => ['INVENTORY_CHECKED' => 'done']],
                            'done'     => ['type' => 'final'],
                        ],
                    ],
                    'payment' => [
                        'initial' => 'validating',
                        'states'  => [
                            'validating' => ['on' => ['PAYMENT_VALIDATED' => 'done']],
                            'done'       => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'approved'      => ['type' => 'final'],
            'manual_review' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Context values are null (not 'success'), so IsAllSucceededGuard fails
    $state = $definition->transition(['type' => 'INVENTORY_CHECKED'], $state);
    $state = $definition->transition(['type' => 'PAYMENT_VALIDATED'], $state);

    expect($state->value)->toBe(['test_fallback.manual_review'])
        ->and(NotifyReviewerAction::wasExecuted())->toBeTrue();
});

// Test 5: All guards fail + no default → machine stays in parallel state
test('it aborts @done when all guards fail and no default', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test_all_fail',
        'initial' => 'processing',
        'context' => [
            'inventory_result' => null,
            'payment_result'   => null,
        ],
        'states' => [
            'processing' => [
                'type'  => 'parallel',
                '@done' => [
                    ['target' => 'approved', 'guards' => IsAllSucceededGuard::class],
                    ['target' => 'also_approved', 'guards' => AlwaysFailGuard::class],
                ],
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_B' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'approved'      => ['type' => 'final'],
            'also_approved' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    // Both guards fail, no default → stays in parallel state (both regions at final)
    expect($state->value)->toContain('test_all_fail.processing.region_a.finished')
        ->and($state->value)->toContain('test_all_fail.processing.region_b.finished');
});

// Test 6: Winning branch runs its actions
test('it runs branch-specific actions on @done', function (): void {
    LogApprovalAction::reset();

    $machine = ConditionalOnDoneMachine::create();

    $machine->send(['type' => 'INVENTORY_CHECKED']);
    $state = $machine->send(['type' => 'PAYMENT_VALIDATED']);

    // Both entry actions set 'success', guard passes → approved with LogApprovalAction
    expect($state->value)->toBe(['conditional_on_done.approved'])
        ->and(LogApprovalAction::wasExecuted())->toBeTrue()
        ->and($state->context->get('approval_logged'))->toBeTrue();
});

// Test 7: Losing branch actions do NOT execute
test('it does not run losing branch actions', function (): void {
    LogApprovalAction::reset();
    NotifyReviewerAction::reset();

    $machine = ConditionalOnDoneMachine::create();

    // Entry actions set success → guard passes → approved branch wins
    $machine->send(['type' => 'INVENTORY_CHECKED']);
    $machine->send(['type' => 'PAYMENT_VALIDATED']);

    // NotifyReviewerAction (on losing manual_review branch) should NOT execute
    expect(NotifyReviewerAction::wasExecuted())->toBeFalse();
});

// Test 8: Compound state @done with guards (guard passes)
test('it works with compound state @done when guard passes', function (): void {
    LogApprovalAction::reset();
    NotifyReviewerAction::reset();

    // Use inline definition to control context (both success → guard passes)
    $definition = MachineDefinition::define([
        'id'      => 'compound_test',
        'initial' => 'verification',
        'context' => [
            'inventory_result'  => 'success',
            'payment_result'    => 'success',
            'approval_logged'   => false,
            'reviewer_notified' => false,
        ],
        'states' => [
            'verification' => [
                '@done' => [
                    ['target' => 'approved',      'guards' => IsAllSucceededGuard::class, 'actions' => LogApprovalAction::class],
                    ['target' => 'manual_review', 'actions' => NotifyReviewerAction::class],
                ],
                'initial' => 'checking',
                'states'  => [
                    'checking' => [
                        'on' => ['CHECK_COMPLETED' => 'done'],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            'approved'      => ['type' => 'final'],
            'manual_review' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'CHECK_COMPLETED'], $state);

    // Context has both success → guard passes → approved
    expect($state->value)->toBe(['compound_test.approved'])
        ->and(LogApprovalAction::wasExecuted())->toBeTrue()
        ->and(NotifyReviewerAction::wasExecuted())->toBeFalse();
});

// Test 9: Compound state @done with guards (guard fails → fallback)
test('it works with compound state @done when guard fails', function (): void {
    LogApprovalAction::reset();
    NotifyReviewerAction::reset();

    $definition = MachineDefinition::define([
        'id'      => 'compound_fail_test',
        'initial' => 'verification',
        'context' => [
            'inventory_result'  => null,
            'payment_result'    => null,
            'approval_logged'   => false,
            'reviewer_notified' => false,
        ],
        'states' => [
            'verification' => [
                '@done' => [
                    ['target' => 'approved',      'guards' => IsAllSucceededGuard::class, 'actions' => LogApprovalAction::class],
                    ['target' => 'manual_review', 'actions' => NotifyReviewerAction::class],
                ],
                'initial' => 'checking',
                'states'  => [
                    'checking' => [
                        'on' => ['CHECK_COMPLETED' => 'done'],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            'approved'      => ['type' => 'final'],
            'manual_review' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'CHECK_COMPLETED'], $state);

    // Context has null values → guard fails → manual_review
    expect($state->value)->toBe(['compound_fail_test.manual_review'])
        ->and(NotifyReviewerAction::wasExecuted())->toBeTrue()
        ->and(LogApprovalAction::wasExecuted())->toBeFalse();
});

// Test 10: Compound @done — all guards fail, no default → stays at final child
test('it aborts compound @done when all guards fail and no default', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'compound_abort',
        'initial' => 'verification',
        'context' => [
            'inventory_result' => null,
            'payment_result'   => null,
        ],
        'states' => [
            'verification' => [
                '@done' => [
                    ['target' => 'approved', 'guards' => IsAllSucceededGuard::class],
                    ['target' => 'also_approved', 'guards' => AlwaysFailGuard::class],
                ],
                'initial' => 'checking',
                'states'  => [
                    'checking' => [
                        'on' => ['CHECK_COMPLETED' => 'done'],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            'approved'      => ['type' => 'final'],
            'also_approved' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'CHECK_COMPLETED'], $state);

    // All guards fail → stays at compound's final child
    expect($state->value)->toBe(['compound_abort.verification.done']);
});

// Test 11: Multiple guarded branches — second guard passes
test('it falls through to second guarded branch when first guard fails', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test_second_branch',
        'initial' => 'processing',
        'context' => [
            'inventory_result' => null,
            'payment_result'   => 'success',
        ],
        'states' => [
            'processing' => [
                'type'  => 'parallel',
                '@done' => [
                    ['target' => 'approved',       'guards' => IsAllSucceededGuard::class],
                    ['target' => 'partial_review', 'guards' => AlwaysFailGuard::class],
                    ['target' => 'manual_review'],
                ],
                'states' => [
                    'region_a' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_A' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working'  => ['on' => ['DONE_B' => 'finished']],
                            'finished' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'approved'       => ['type' => 'final'],
            'partial_review' => ['type' => 'final'],
            'manual_review'  => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    $state = $definition->transition(['type' => 'DONE_B'], $state);

    // First guard fails (inventory null), second guard fails (AlwaysFailGuard), default → manual_review
    expect($state->value)->toBe(['test_second_branch.manual_review']);
});

// Test 12: ConditionalOnDoneMachine via Machine::create — full integration
test('it handles conditional @done via Machine::create with full lifecycle', function (): void {
    LogApprovalAction::reset();
    NotifyReviewerAction::reset();

    $machine = ConditionalOnDoneMachine::create();

    // First send: inventory checked → entry sets inventory_result=success
    $state = $machine->send(['type' => 'INVENTORY_CHECKED']);
    expect($state->value)->toContain('conditional_on_done.processing.inventory.done');

    // Second send: payment validated → entry sets payment_result=success, all regions final, @done fires
    $state = $machine->send(['type' => 'PAYMENT_VALIDATED']);
    expect($state->value)->toBe(['conditional_on_done.approved'])
        ->and($state->context->get('inventory_result'))->toBe('success')
        ->and($state->context->get('payment_result'))->toBe('success')
        ->and(LogApprovalAction::wasExecuted())->toBeTrue()
        ->and(NotifyReviewerAction::wasExecuted())->toBeFalse();
});
