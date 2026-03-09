<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\CanRetryGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SendAlertAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\LogApprovalAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\IsAllSucceededGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\IncrementRetryAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\NotifyReviewerAction;

// Test 19: Conditional @done resolves correctly with null EventBehavior (async mode)
test('it resolves conditional @done in async mode with null EventBehavior', function (): void {
    LogApprovalAction::reset();
    NotifyReviewerAction::reset();

    $definition = MachineDefinition::define([
        'id'      => 'test_async_done',
        'initial' => 'processing',
        'context' => [
            'inventory_result'  => 'success',
            'payment_result'    => 'success',
            'approval_logged'   => false,
            'reviewer_notified' => false,
        ],
        'states' => [
            'processing' => [
                'type'  => 'parallel',
                '@done' => [
                    ['target' => 'approved',      'guards' => IsAllSucceededGuard::class, 'actions' => LogApprovalAction::class],
                    ['target' => 'manual_review', 'actions' => NotifyReviewerAction::class],
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
            'manual_review' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Simulate async: null EventBehavior (as ParallelRegionJob passes null)
    $parallelState = $definition->idMap['test_async_done.processing'];
    $state         = $definition->processParallelOnDone($parallelState, $state, null);

    // Guard evaluates with synthetic EventBehavior → approved
    expect($state->value)->toBe(['test_async_done.approved'])
        ->and(LogApprovalAction::wasExecuted())->toBeTrue()
        ->and(NotifyReviewerAction::wasExecuted())->toBeFalse();
});

// Test 20: Null EventBehavior with guard failure → fallback
test('it handles null EventBehavior with synthetic event on guard failure', function (): void {
    NotifyReviewerAction::reset();

    $definition = MachineDefinition::define([
        'id'      => 'test_async_fallback',
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
                    ['target' => 'approved', 'guards' => IsAllSucceededGuard::class],
                    ['target' => 'manual_review', 'actions' => NotifyReviewerAction::class],
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
            'manual_review' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Null EventBehavior, guard fails → fallback to manual_review
    $parallelState = $definition->idMap['test_async_fallback.processing'];
    $state         = $definition->processParallelOnDone($parallelState, $state, null);

    expect($state->value)->toBe(['test_async_fallback.manual_review'])
        ->and(NotifyReviewerAction::wasExecuted())->toBeTrue();
});

// Test 21: Conditional @fail with null EventBehavior (timeout job context)
test('it resolves conditional @fail in async timeout with null EventBehavior', function (): void {
    SendAlertAction::reset();

    $definition = MachineDefinition::define([
        'id'      => 'test_async_fail',
        'initial' => 'processing',
        'context' => [
            'retry_count' => 5,
            'alert_sent'  => false,
        ],
        'states' => [
            'processing' => [
                'type'  => 'parallel',
                '@done' => 'completed',
                '@fail' => [
                    ['target' => 'retrying', 'guards' => CanRetryGuard::class, 'actions' => IncrementRetryAction::class],
                    ['target' => 'failed',   'actions' => SendAlertAction::class],
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
            'completed' => ['type' => 'final'],
            'retrying'  => ['type' => 'final'],
            'failed'    => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Null EventBehavior (as ParallelRegionTimeoutJob passes null), retry_count=5 → failed
    $parallelState = $definition->idMap['test_async_fail.processing'];
    $state         = $definition->processParallelOnFail($parallelState, $state, null);

    expect($state->value)->toBe(['test_async_fail.failed'])
        ->and(SendAlertAction::wasExecuted())->toBeTrue()
        ->and($state->context->get('alert_sent'))->toBeTrue();
});
