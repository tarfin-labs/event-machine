<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\NoStateDefinitionFoundException;

// region Cross-Region Transition Validation
// Source: Boost.Statechart InvalidTransitionTest1-2 — illegal transitions between sibling orthogonal regions.
//
// In SCXML / Boost.Statechart, transitions that cross orthogonal (parallel) region
// boundaries are illegal. A state in region_a must NOT target a state in region_b,
// because each region is an independent concurrent context.
//
// EventMachine rejects cross-region transitions at definition time via its target
// resolution algorithm (resolveStateRelativeToSource). The resolver walks up the
// hierarchy from the source state but never descends into sibling regions, so a
// target inside a sibling region is unresolvable. TransitionBranch throws
// NoStateDefinitionFoundException during MachineDefinition::define().
//
// Note: This is an implicit rejection (target resolution failure), not an explicit
// validation rule in StateConfigValidator. A dedicated validator message (e.g.,
// "cross-region transitions are not allowed") would improve the developer experience.
// endregion

test('cross-region transition from region_a to region_b is rejected at definition time', function (): void {
    // A state in region_a targets "received" which only exists in region_b.
    // The target resolver cannot find it — NoStateDefinitionFoundException is thrown.
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
        exception: NoStateDefinitionFoundException::class,
        exceptionMessage: "from state 'cross_region_target.active.region_a.idle' to state 'received'"
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
        exception: NoStateDefinitionFoundException::class,
        exceptionMessage: "from state 'cross_region_reverse.processing.region_b.pending' to state 'step_two'"
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
        exception: NoStateDefinitionFoundException::class,
        exceptionMessage: "from state 'cross_region_array.running.region_a.idle' to state 'finished'"
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
