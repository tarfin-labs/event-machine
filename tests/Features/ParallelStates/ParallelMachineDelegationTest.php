<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildAMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation\ImmediateChildBMachine;

// ============================================================
// Parallel Region Machine Delegation (Sequential Mode)
// ============================================================

it('invokes child machines on parallel region initial states', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parallel_delegation',
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
                                'done_a' => [
                                    'type' => 'final',
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'running_b',
                            'states'  => [
                                'running_b' => [
                                    'machine' => ImmediateChildBMachine::class,
                                    '@done'   => 'done_b',
                                ],
                                'done_b' => [
                                    'type' => 'final',
                                ],
                            ],
                        ],
                    ],
                    '@done' => 'completed',
                ],
                'completed' => [
                    'type' => 'final',
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Both child machines should complete, regions should reach final states,
    // and @done on the parallel state should fire, transitioning to 'completed'.
    expect($state->value)->toBe(['parallel_delegation.completed']);
});

it('invokes child machine on a single parallel region initial state', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parallel_single_delegation',
            'initial' => 'processing',
            'context' => [],
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'delegating',
                            'states'  => [
                                'delegating' => [
                                    'machine' => ImmediateChildMachine::class,
                                    '@done'   => 'done_a',
                                ],
                                'done_a' => [
                                    'type' => 'final',
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'type' => 'final',
                                ],
                            ],
                        ],
                    ],
                    '@done' => 'completed',
                ],
                'completed' => [
                    'type' => 'final',
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Region A's child machine completes, region B is already final.
    // @done on parallel state should fire.
    expect($state->value)->toBe(['parallel_single_delegation.completed']);
});

it('invokes child machine when transitioning into a parallel state', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'transition_to_parallel',
            'initial' => 'idle',
            'context' => [],
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
                                'done_a' => [
                                    'type' => 'final',
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'running_b',
                            'states'  => [
                                'running_b' => [
                                    'machine' => ImmediateChildBMachine::class,
                                    '@done'   => 'done_b',
                                ],
                                'done_b' => [
                                    'type' => 'final',
                                ],
                            ],
                        ],
                    ],
                    '@done' => 'completed',
                ],
                'completed' => [
                    'type' => 'final',
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();
    expect($state->value)->toBe(['transition_to_parallel.idle']);

    $state = $definition->transition(event: ['type' => 'START'], state: $state);

    // After transitioning to parallel state, child machines should invoke
    // and @done should propagate.
    expect($state->value)->toBe(['transition_to_parallel.completed']);
});

// ============================================================
// Machine Delegation via getInitialState (non-parallel)
// ============================================================

it('invokes child machine on non-parallel initial state', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'initial_delegation',
            'initial' => 'delegating',
            'context' => [],
            'states'  => [
                'delegating' => [
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => [
                    'type' => 'final',
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();

    // Child machine should complete immediately and @done should fire.
    expect($state->value)->toBe(['initial_delegation.completed']);
});

// ============================================================
// Machine Delegation via processCompoundOnDone
// ============================================================

it('invokes child machine on compound onDone target', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'compound_done_delegation',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'processing'],
                ],
                'processing' => [
                    'initial' => 'step_one',
                    'states'  => [
                        'step_one' => [
                            'on' => ['FINISH' => 'step_done'],
                        ],
                        'step_done' => [
                            'type' => 'final',
                        ],
                    ],
                    '@done' => 'verifying',
                ],
                'verifying' => [
                    'machine' => ImmediateChildMachine::class,
                    '@done'   => 'completed',
                ],
                'completed' => [
                    'type' => 'final',
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(event: ['type' => 'START'], state: $state);
    expect($state->value)->toBe(['compound_done_delegation.processing.step_one']);

    $state = $definition->transition(event: ['type' => 'FINISH'], state: $state);

    // step_done is final → compound @done → verifying → child machine → @done → completed
    expect($state->value)->toBe(['compound_done_delegation.completed']);
});

// ============================================================
// Machine Delegation within parallel region transitions
// ============================================================

it('invokes child machine when transitioning within a parallel region', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parallel_region_transition',
            'initial' => 'active',
            'context' => [],
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => ['GO_A' => 'delegating_a'],
                                ],
                                'delegating_a' => [
                                    'machine' => ImmediateChildAMachine::class,
                                    '@done'   => 'done_a',
                                ],
                                'done_a' => [
                                    'type' => 'final',
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'type' => 'final',
                                ],
                            ],
                        ],
                    ],
                    '@done' => 'completed',
                ],
                'completed' => [
                    'type' => 'final',
                ],
            ],
        ],
    );

    $state = $definition->getInitialState();
    expect($state->value)->toContain('parallel_region_transition.active.region_a.waiting');

    $state = $definition->transition(event: ['type' => 'GO_A'], state: $state);

    // Transitioning within a parallel region to a state with machine delegation
    // should invoke the child machine.
    expect($state->value)->toBe(['parallel_region_transition.completed']);
});
