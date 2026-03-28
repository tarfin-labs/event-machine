<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\SuccessfulTestJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\RaiseOutputReadyAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;

// ============================================================
// 1. Compound @done: raise() and @always
// ============================================================

it('processes raised events from entry actions after compound @done transition', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'compound_raise_test',
            'initial' => 'idle',
            'context' => ['protocolResult' => null],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'review'],
                ],
                'review' => [
                    'initial' => 'checking',
                    'states'  => [
                        'checking' => [
                            'on' => ['APPROVE' => 'approved'],
                        ],
                        'approved' => [
                            'type' => 'final',
                        ],
                    ],
                    '@done' => 'evaluating',
                ],
                'evaluating' => [
                    'entry' => 'raiseResultAction',
                    'on'    => [
                        'RESULT_READY' => 'completed',
                    ],
                ],
                'completed' => [
                    'type' => 'final',
                ],
            ],
        ],
        behavior: [
            'actions' => [
                'raiseResultAction' => RaiseOutputReadyAction::class,
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'START'], state: $state);
    expect($state->value)->toBe(['compound_raise_test.review.checking']);

    $state = $definition->transition(event: ['type' => 'APPROVE'], state: $state);

    // approved (final) → compound @done → evaluating → entry raises RESULT_READY → completed
    expect($state->value)->toBe(['compound_raise_test.completed']);
    expect($state->context->get('protocolResult'))->toBe('decided');
});

it('processes @always transitions after compound @done transition', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'compound_always_test',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'review'],
                ],
                'review' => [
                    'initial' => 'checking',
                    'states'  => [
                        'checking' => [
                            'on' => ['APPROVE' => 'approved'],
                        ],
                        'approved' => [
                            'type' => 'final',
                        ],
                    ],
                    '@done' => 'routing',
                ],
                'routing' => [
                    'on' => [
                        '@always' => 'completed',
                    ],
                ],
                'completed' => [
                    'type' => 'final',
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'START'], state: $state);
    $state = $definition->transition(event: ['type' => 'APPROVE'], state: $state);

    expect($state->value)->toBe(['compound_always_test.completed']);
});

// ============================================================
// 2. Parallel @done: raise() and @always
// ============================================================

it('processes @always after parallel @done (exitParallelStateAndTransitionToTarget)', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parallel_done_always',
            'initial' => 'processing',
            'context' => [],
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working_a',
                            'states'  => [
                                'working_a' => [
                                    'on' => ['DONE_A' => 'finished_a'],
                                ],
                                'finished_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working_b',
                            'states'  => [
                                'working_b' => [
                                    'on' => ['DONE_B' => 'finished_b'],
                                ],
                                'finished_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                    '@done' => 'routing',
                ],
                'routing' => [
                    'on' => [
                        '@always' => 'completed',
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'DONE_A'], state: $state);
    $state = $definition->transition(event: ['type' => 'DONE_B'], state: $state);

    // Both regions done → parallel @done → routing → @always → completed
    expect($state->value)->toBe(['parallel_done_always.completed']);
});

it('processes raised events after parallel @done', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parallel_done_raise',
            'initial' => 'processing',
            'context' => ['protocolResult' => null],
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working_a',
                            'states'  => [
                                'working_a' => [
                                    'on' => ['DONE_A' => 'finished_a'],
                                ],
                                'finished_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working_b',
                            'states'  => [
                                'working_b' => [
                                    'on' => ['DONE_B' => 'finished_b'],
                                ],
                                'finished_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                    '@done' => 'evaluating',
                ],
                'evaluating' => [
                    'entry' => 'raiseResultAction',
                    'on'    => [
                        'RESULT_READY' => 'completed',
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'raiseResultAction' => RaiseOutputReadyAction::class,
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'DONE_A'], state: $state);
    $state = $definition->transition(event: ['type' => 'DONE_B'], state: $state);

    // Both regions done → parallel @done → evaluating → raise RESULT_READY → completed
    expect($state->value)->toBe(['parallel_done_raise.completed']);
    expect($state->context->get('protocolResult'))->toBe('decided');
});

// ============================================================
// 3. Edge Cases
// ============================================================

it('guarded @always after compound @done — guard fails, stays at target', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'guarded_always',
            'initial' => 'wrapper',
            'context' => ['eligible' => false],
            'states'  => [
                'wrapper' => [
                    'initial' => 'inner',
                    'states'  => [
                        'inner' => [
                            'on' => ['FINISH' => 'done'],
                        ],
                        'done' => ['type' => 'final'],
                    ],
                    '@done' => 'routing',
                ],
                'routing' => [
                    'on' => [
                        '@always' => [
                            'target' => 'approved',
                            'guards' => 'isEligibleGuard',
                        ],
                    ],
                ],
                'approved' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'guards' => [
                'isEligibleGuard' => function (ContextManager $ctx): bool {
                    return $ctx->get('eligible') === true;
                },
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'FINISH'], state: $state);

    // Guard fails → stays at routing (not approved)
    expect($state->value)->toBe(['guarded_always.routing']);
});

it('compound @done → @always → child delegation chain', function (): void {
    Queue::fake();

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'done_always_delegation',
            'initial' => 'wrapper',
            'context' => [],
            'states'  => [
                'wrapper' => [
                    'initial' => 'inner',
                    'states'  => [
                        'inner' => [
                            'on' => ['FINISH' => 'done'],
                        ],
                        'done' => ['type' => 'final'],
                    ],
                    '@done' => 'routing',
                ],
                'routing' => [
                    'on' => [
                        '@always' => 'delegating',
                    ],
                ],
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'FINISH'], state: $state);

    // compound @done → routing → @always → delegating → child completes → @done → completed
    expect($state->value)->toBe(['done_always_delegation.completed']);
});

it('fire-and-forget job with @always on target state', function (): void {
    Queue::fake();

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'ff_always',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'dispatching'],
                ],
                'dispatching' => [
                    'job'    => SuccessfulTestJob::class,
                    'target' => 'routing',
                ],
                'routing' => [
                    'on' => [
                        '@always' => 'completed',
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'START'], state: $state);

    // Fire-and-forget transitions to 'routing' immediately → @always → completed
    expect($state->value)->toBe(['ff_always.completed']);
});
