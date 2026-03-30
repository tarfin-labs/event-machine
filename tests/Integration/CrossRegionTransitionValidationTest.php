<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\InvalidStateConfigException;

// region Cross-Region Transition Validation
// Source: Boost.Statechart InvalidTransitionTest1-2 — illegal transitions between sibling orthogonal regions.
//
// In SCXML / Boost.Statechart, transitions that cross orthogonal (parallel) region
// boundaries are illegal. A state in region_a must NOT target a state in region_b,
// because each region is an independent concurrent context.
//
// EventMachine rejects cross-region transitions at definition time via explicit
// validation in StateConfigValidator. The validator walks each parallel region's
// states' transitions and checks if any target matches a state in a sibling region.
// If so, it throws an InvalidArgumentException with a clear error message explaining
// the cross-region violation and suggesting events (raise/sendTo) as the alternative.
// endregion

test('cross-region transition from region_a to region_b is rejected at definition time', function (): void {
    // A state in region_a targets "received" which only exists in region_b.
    // StateConfigValidator detects this and throws with a clear cross-region message.
    expect(fn () => MachineDefinition::define(
        config: [
            'id'      => 'cross_region_target',
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'on' => [
                                        'CROSS' => 'received',
                                    ],
                                ],
                                'done_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        'FINISH_B' => 'received',
                                    ],
                                ],
                                'received' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ))->toThrow(
        exception: InvalidStateConfigException::class,
        exceptionMessage: 'Cross-region transition not allowed: state "active.region_a.idle" in region "region_a" cannot target state "received" in sibling region "region_b". Use events (raise/sendTo) to coordinate between regions.'
    );
});

test('cross-region transition from region_b to region_a is also rejected', function (): void {
    // A state in region_b targets "step_two" which only exists in region_a.
    expect(fn () => MachineDefinition::define(
        config: [
            'id'      => 'cross_region_reverse',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'step_one',
                            'states'  => [
                                'step_one' => [
                                    'on' => ['NEXT_A' => 'step_two'],
                                ],
                                'step_two' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'pending',
                            'states'  => [
                                'pending' => [
                                    'on' => [
                                        'JUMP' => 'step_two',
                                    ],
                                ],
                                'done_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ))->toThrow(
        exception: InvalidStateConfigException::class,
        exceptionMessage: 'Cross-region transition not allowed: state "processing.region_b.pending" in region "region_b" cannot target state "step_two" in sibling region "region_a". Use events (raise/sendTo) to coordinate between regions.'
    );
});

test('cross-region transition with array config is also rejected', function (): void {
    // Same scenario but using array-style transition config with explicit target key.
    expect(fn () => MachineDefinition::define(
        config: [
            'id'      => 'cross_region_array',
            'initial' => 'running',
            'states'  => [
                'running' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'on' => [
                                        'ILLEGAL_CROSS' => [
                                            'target'  => 'finished',
                                            'actions' => 'someAction',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting'  => [],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ))->toThrow(
        exception: InvalidStateConfigException::class,
        exceptionMessage: 'Cross-region transition not allowed: state "running.region_a.idle" in region "region_a" cannot target state "finished" in sibling region "region_b". Use events (raise/sendTo) to coordinate between regions.'
    );
});

test('cross-region transition with guarded conditions is also rejected', function (): void {
    // Guarded transition array where one branch targets a sibling region state.
    expect(fn () => MachineDefinition::define(
        config: [
            'id'      => 'cross_region_guarded',
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    'states' => [
                        'region_a' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'on' => [
                                        'GUARDED_CROSS' => [
                                            ['target' => 'done_b', 'guards' => 'someGuard'],
                                            ['target' => 'done_a'],
                                        ],
                                    ],
                                ],
                                'done_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [],
                                'done_b'  => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ))->toThrow(
        exception: InvalidStateConfigException::class,
        exceptionMessage: 'Cross-region transition not allowed: state "active.region_a.idle" in region "region_a" cannot target state "done_b" in sibling region "region_b". Use events (raise/sendTo) to coordinate between regions.'
    );
});

test('intra-region transitions remain valid within parallel states', function (): void {
    // Sanity check: transitions within the same region work fine.
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'intra_region_valid',
            'initial' => 'active',
            'states'  => [
                'active' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'on' => [
                                        'GO_A' => 'done_a',
                                    ],
                                ],
                                'done_a' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        'GO_B' => 'done_b',
                                    ],
                                ],
                                'done_b' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    expect($definition)->toBeInstanceOf(MachineDefinition::class);

    // Verify both intra-region transitions resolve correctly.
    $idleState    = $definition->idMap['intra_region_valid.active.region_a.idle'] ?? null;
    $waitingState = $definition->idMap['intra_region_valid.active.region_b.waiting'] ?? null;

    expect($idleState)->not->toBeNull()
        ->and($waitingState)->not->toBeNull();

    $goATransition = $idleState->transitionDefinitions['GO_A'] ?? null;
    $goBTransition = $waitingState->transitionDefinitions['GO_B'] ?? null;

    expect($goATransition)->not->toBeNull()
        ->and($goBTransition)->not->toBeNull();
});

test('intra-region transitions with shared state names across regions remain valid', function (): void {
    // Both regions have a state named "finished" — transitions to own region's "finished" must work.
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'shared_names_valid',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'region_a' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => ['DONE_A' => 'finished'],
                                ],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => ['DONE_B' => 'finished'],
                                ],
                                'finished' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
    );

    expect($definition)->toBeInstanceOf(MachineDefinition::class);
});
