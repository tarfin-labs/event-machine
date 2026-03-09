<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\CanRetryGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Guards\AlwaysFailGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\SendAlertAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\ConditionalOnFailMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Parallel\Actions\IncrementRetryAction;

// Test 13: Backward compat — simple string @fail still works
test('it resolves simple string @fail unchanged', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test_string_fail',
        'initial' => 'processing',
        'states'  => [
            'processing' => [
                'type'   => 'parallel',
                '@done'  => 'completed',
                '@fail'  => 'failed',
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
            'failed'    => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Simulate @fail by calling processParallelOnFail directly
    $parallelState = $definition->idMap['test_string_fail.processing'];
    $state         = $definition->processParallelOnFail($parallelState, $state);

    expect($state->value)->toBe(['test_string_fail.failed']);
});

// Test 14: Conditional @fail — first guard passes → first target (retry)
test('it transitions to first matching guard branch on @fail', function (): void {
    SendAlertAction::reset();

    $definition = MachineDefinition::define([
        'id'      => 'test_fail_guard',
        'initial' => 'processing',
        'context' => [
            'retry_count' => 0,
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

    // retry_count=0 < 3, so CanRetryGuard passes → retrying
    $parallelState = $definition->idMap['test_fail_guard.processing'];
    $state         = $definition->processParallelOnFail($parallelState, $state);

    expect($state->value)->toBe(['test_fail_guard.retrying'])
        ->and($state->context->get('retry_count'))->toBe(1)
        ->and(SendAlertAction::wasExecuted())->toBeFalse();
});

// Test 15: @fail — guard fails → fallback to default
test('it falls through to default branch on @fail', function (): void {
    SendAlertAction::reset();

    $definition = MachineDefinition::define([
        'id'      => 'test_fail_fallback',
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
                    ['target' => 'retrying', 'guards' => CanRetryGuard::class],
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

    // retry_count=5 >= 3, so CanRetryGuard fails → failed
    $parallelState = $definition->idMap['test_fail_fallback.processing'];
    $state         = $definition->processParallelOnFail($parallelState, $state);

    expect($state->value)->toBe(['test_fail_fallback.failed'])
        ->and(SendAlertAction::wasExecuted())->toBeTrue()
        ->and($state->context->get('alert_sent'))->toBeTrue();
});

// Test 16: @fail — all guards fail, no default → machine stays
test('it aborts @fail when all guards fail and no default', function (): void {
    $definition = MachineDefinition::define([
        'id'      => 'test_fail_abort',
        'initial' => 'processing',
        'context' => [
            'retry_count' => 5,
        ],
        'states' => [
            'processing' => [
                'type'  => 'parallel',
                '@done' => 'completed',
                '@fail' => [
                    ['target' => 'retrying', 'guards' => CanRetryGuard::class],
                    ['target' => 'also_retrying', 'guards' => AlwaysFailGuard::class],
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
            'completed'     => ['type' => 'final'],
            'retrying'      => ['type' => 'final'],
            'also_retrying' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Both guards fail → machine stays in parallel state
    $parallelState = $definition->idMap['test_fail_abort.processing'];
    $state         = $definition->processParallelOnFail($parallelState, $state);

    // Still in the parallel state (values unchanged from initial)
    expect($state->value)->toContain('test_fail_abort.processing.region_a.working')
        ->and($state->value)->toContain('test_fail_abort.processing.region_b.working');
});

// Test 17: @fail actions run BEFORE exit (can inspect parallel state context)
test('it runs branch actions BEFORE exit on @fail', function (): void {
    SendAlertAction::reset();

    $definition = MachineDefinition::define([
        'id'      => 'test_fail_before_exit',
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
                    ['target' => 'failed', 'actions' => SendAlertAction::class],
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
            'failed'    => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    $parallelState = $definition->idMap['test_fail_before_exit.processing'];
    $state         = $definition->processParallelOnFail($parallelState, $state);

    // Actions run before exit → alert_sent should be true
    expect($state->value)->toBe(['test_fail_before_exit.failed'])
        ->and(SendAlertAction::wasExecuted())->toBeTrue()
        ->and($state->context->get('alert_sent'))->toBeTrue();
});

// Test 18: Realistic canRetry pattern — retry succeeds then exceeds
test('it works with canRetry pattern across multiple fail invocations', function (): void {
    SendAlertAction::reset();

    $definition = MachineDefinition::define([
        'id'      => 'test_retry_pattern',
        'initial' => 'processing',
        'context' => [
            'retry_count' => 2,
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

    // First fail: retry_count=2 < 3 → retrying, increment to 3
    $parallelState = $definition->idMap['test_retry_pattern.processing'];
    $state         = $definition->processParallelOnFail($parallelState, $state);

    expect($state->value)->toBe(['test_retry_pattern.retrying'])
        ->and($state->context->get('retry_count'))->toBe(3);

    // Reset state to simulate another fail (retry_count is now 3)
    // Create fresh initial state but with updated context
    $state2 = $definition->getInitialState();
    $state2->context->set('retry_count', 3);

    SendAlertAction::reset();
    $state2 = $definition->processParallelOnFail($parallelState, $state2);

    // retry_count=3 >= 3 → failed with alert
    expect($state2->value)->toBe(['test_retry_pattern.failed'])
        ->and(SendAlertAction::wasExecuted())->toBeTrue();
});

// Test 19: ConditionalOnFailMachine via Machine::create — @fail guard passes (can retry)
test('ConditionalOnFailMachine @fail with guard pass via Machine::create', function (): void {
    SendAlertAction::reset();

    $machine = ConditionalOnFailMachine::create();

    // retry_count starts at 0, CanRetryGuard passes (0 < 3)
    $parallelState = $machine->definition->idMap['conditional_on_fail.processing'];
    $state         = $machine->definition->processParallelOnFail($parallelState, $machine->state);

    expect($state->value)->toBe(['conditional_on_fail.retrying'])
        ->and($state->context->get('retry_count'))->toBe(1)
        ->and(SendAlertAction::wasExecuted())->toBeFalse();
});

// Test 20: ConditionalOnFailMachine via Machine::create — @fail guard fails (exhausted retries)
test('ConditionalOnFailMachine @fail with guard fail via Machine::create', function (): void {
    SendAlertAction::reset();

    $machine = ConditionalOnFailMachine::create();
    $machine->state->context->set('retry_count', 5);

    // retry_count=5 >= 3, CanRetryGuard fails → falls through to SendAlertAction
    $parallelState = $machine->definition->idMap['conditional_on_fail.processing'];
    $state         = $machine->definition->processParallelOnFail($parallelState, $machine->state);

    expect($state->value)->toBe(['conditional_on_fail.failed'])
        ->and(SendAlertAction::wasExecuted())->toBeTrue()
        ->and($state->context->get('alert_sent'))->toBeTrue();
});

// Test 21: ConditionalOnFailMachine — @done path via Machine::send (all regions succeed)
test('ConditionalOnFailMachine @done fires when all regions succeed via send', function (): void {
    $machine = ConditionalOnFailMachine::create();

    $machine->send(['type' => 'INVENTORY_CHECKED']);
    $state = $machine->send(['type' => 'PAYMENT_VALIDATED']);

    // Both regions final (done states) → @done fires → completed
    expect($state->value)->toBe(['conditional_on_fail.completed']);
});

// Test 22: ConditionalOnFailMachine — PAYMENT_FAILED also leads to @done (error is final)
test('ConditionalOnFailMachine @done fires even when payment fails via send', function (): void {
    $machine = ConditionalOnFailMachine::create();

    $machine->send(['type' => 'INVENTORY_CHECKED']);
    $state = $machine->send(['type' => 'PAYMENT_FAILED']);

    // payment.error is final, inventory.done is final → all regions final → @done fires
    expect($state->value)->toBe(['conditional_on_fail.completed']);
});
