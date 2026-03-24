<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\FailingChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildAMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildBMachine;

// ============================================================
// Parallel Child Delegation with Mixed Success/Failure Outcomes
// ============================================================
// Parent delegates to two children in parallel state regions.
// One succeeds (@done), other fails (@fail).
// Verify parent routes correctly based on combined outcome.

it('parallel region A succeeds and region B fails — parent transitions via @fail', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'mixed_parallel',
            'initial' => 'verifying',
            'context' => ['error' => null],
            'states'  => [
                'verifying' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'running_a',
                            'states'  => [
                                'running_a' => [
                                    'machine' => ImmediateChildAMachine::class,
                                    '@done'   => 'done_a',
                                ],
                                'done_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'running_b',
                            'states'  => [
                                'running_b' => [
                                    'machine' => FailingChildMachine::class,
                                    '@done'   => 'done_b',
                                    '@fail'   => [
                                        'target'  => 'error_b',
                                        'actions' => 'captureErrorAction',
                                    ],
                                ],
                                'done_b'  => ['type' => 'final'],
                                'error_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                    '@done' => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureErrorAction' => function (ContextManager $ctx, EventBehavior $event): void {
                    $ctx->set('error', $event->payload['error_message'] ?? 'unknown');
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Region A succeeds (ImmediateChildAMachine → done_a final).
    // Region B fails (FailingChildMachine throws → @fail → error_b final).
    // Both regions reach final states → @done fires on the parallel state → completed.
    expect($state->value)->toBe(['mixed_parallel.completed'])
        ->and($state->context->get('error'))->toBe('Payment gateway down');
});

it('parallel region A fails and region B succeeds — parent transitions via @fail then @done', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'mixed_parallel_flip',
            'initial' => 'verifying',
            'context' => ['error' => null],
            'states'  => [
                'verifying' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'running_a',
                            'states'  => [
                                'running_a' => [
                                    'machine' => FailingChildMachine::class,
                                    '@done'   => 'done_a',
                                    '@fail'   => [
                                        'target'  => 'error_a',
                                        'actions' => 'captureErrorAction',
                                    ],
                                ],
                                'done_a'  => ['type' => 'final'],
                                'error_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'running_b',
                            'states'  => [
                                'running_b' => [
                                    'machine' => ImmediateChildBMachine::class,
                                    '@done'   => 'done_b',
                                ],
                                'done_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                    '@done' => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureErrorAction' => function (ContextManager $ctx, EventBehavior $event): void {
                    $ctx->set('error', $event->payload['error_message'] ?? 'unknown');
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Region A fails → @fail → error_a (final).
    // Region B succeeds → done_b (final).
    // Both final → @done fires → completed.
    expect($state->value)->toBe(['mixed_parallel_flip.completed'])
        ->and($state->context->get('error'))->toBe('Payment gateway down');
});

it('both parallel regions fail — parent still transitions via @done when both reach final', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'mixed_parallel_both_fail',
            'initial' => 'verifying',
            'context' => ['error_count' => 0],
            'states'  => [
                'verifying' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'running_a',
                            'states'  => [
                                'running_a' => [
                                    'machine' => FailingChildMachine::class,
                                    '@done'   => 'done_a',
                                    '@fail'   => [
                                        'target'  => 'error_a',
                                        'actions' => 'incrementErrorAction',
                                    ],
                                ],
                                'done_a'  => ['type' => 'final'],
                                'error_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'running_b',
                            'states'  => [
                                'running_b' => [
                                    'machine' => FailingChildMachine::class,
                                    '@done'   => 'done_b',
                                    '@fail'   => [
                                        'target'  => 'error_b',
                                        'actions' => 'incrementErrorAction',
                                    ],
                                ],
                                'done_b'  => ['type' => 'final'],
                                'error_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                    '@done' => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'incrementErrorAction' => function (ContextManager $ctx): void {
                    $ctx->set('error_count', $ctx->get('error_count') + 1);
                },
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Both regions fail → both reach error final states → @done fires → completed.
    expect($state->value)->toBe(['mixed_parallel_both_fail.completed'])
        ->and($state->context->get('error_count'))->toBe(2);
});

it('parallel region without @fail re-throws when child fails', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'mixed_parallel_no_fail',
            'initial' => 'verifying',
            'context' => [],
            'states'  => [
                'verifying' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'running_a',
                            'states'  => [
                                'running_a' => [
                                    'machine' => ImmediateChildAMachine::class,
                                    '@done'   => 'done_a',
                                ],
                                'done_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'running_b',
                            'states'  => [
                                'running_b' => [
                                    // No @fail defined — exception should propagate
                                    'machine' => FailingChildMachine::class,
                                    '@done'   => 'done_b',
                                ],
                                'done_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                    '@done' => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    expect(fn () => $definition->getInitialState())
        ->toThrow(RuntimeException::class, 'Payment gateway down');
});

it('event-triggered parallel delegation with mixed outcomes works correctly', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'event_mixed_parallel',
            'initial' => 'idle',
            'context' => ['error' => null],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'verifying'],
                ],
                'verifying' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'running_a',
                            'states'  => [
                                'running_a' => [
                                    'machine' => ImmediateChildAMachine::class,
                                    '@done'   => 'done_a',
                                ],
                                'done_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'running_b',
                            'states'  => [
                                'running_b' => [
                                    'machine' => FailingChildMachine::class,
                                    '@done'   => 'done_b',
                                    '@fail'   => [
                                        'target'  => 'error_b',
                                        'actions' => 'captureErrorAction',
                                    ],
                                ],
                                'done_b'  => ['type' => 'final'],
                                'error_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                    '@done' => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'captureErrorAction' => function (ContextManager $ctx, EventBehavior $event): void {
                    $ctx->set('error', $event->payload['error_message'] ?? 'unknown');
                },
            ],
        ],
    );

    $state = $definition->getInitialState();
    expect($state->value)->toBe(['event_mixed_parallel.idle']);

    $state = $definition->transition(event: ['type' => 'START'], state: $state);

    // After transitioning into the parallel state, one child succeeds and one fails.
    // Both reach final states, so @done fires on the parallel state.
    expect($state->value)->toBe(['event_mixed_parallel.completed'])
        ->and($state->context->get('error'))->toBe('Payment gateway down');
});
