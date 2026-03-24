<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Enums\StateDefinitionType;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

// ---------------------------------------------------------------------------
// XState #2349 — nested parallel done events don't bubble
//
// Verifies that when a parallel state is nested inside a region of an
// outer parallel state, @done events cascade correctly:
//   1. Inner parallel's sub-regions all complete → inner @done fires
//   2. Inner @done transitions the outer region to its final state
//   3. When both outer regions complete → outer @done fires
// ---------------------------------------------------------------------------

test('nested parallel-within-parallel @done cascade fires correctly', function (): void {
    // Structure:
    //   outer_parallel (type: parallel, @done → all_complete)
    //     region_a: contains inner_parallel (type: parallel, @done → region_a_done[final])
    //       inner_parallel:
    //         sub_region_1: active → FINISH_SUB_1 → finished[final]
    //         sub_region_2: active → FINISH_SUB_2 → finished[final]
    //     region_b: working → FINISH_B → done[final]

    $definition = MachineDefinition::define([
        'id'      => 'nested_cascade',
        'initial' => 'outer_parallel',
        'states'  => [
            'outer_parallel' => [
                'type'   => 'parallel',
                '@done'  => 'all_complete',
                'states' => [
                    'region_a' => [
                        'initial' => 'inner_parallel',
                        'states'  => [
                            'inner_parallel' => [
                                'type'   => 'parallel',
                                '@done'  => 'region_a_done',
                                'states' => [
                                    'sub_region_1' => [
                                        'initial' => 'active',
                                        'states'  => [
                                            'active' => [
                                                'on' => ['FINISH_SUB_1' => 'finished'],
                                            ],
                                            'finished' => ['type' => 'final'],
                                        ],
                                    ],
                                    'sub_region_2' => [
                                        'initial' => 'active',
                                        'states'  => [
                                            'active' => [
                                                'on' => ['FINISH_SUB_2' => 'finished'],
                                            ],
                                            'finished' => ['type' => 'final'],
                                        ],
                                    ],
                                ],
                            ],
                            'region_a_done' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['FINISH_B' => 'done'],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'all_complete' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Initially: both inner sub-regions active, region_b working
    expect($state->matches('outer_parallel.region_a.inner_parallel.sub_region_1.active'))->toBeTrue();
    expect($state->matches('outer_parallel.region_a.inner_parallel.sub_region_2.active'))->toBeTrue();
    expect($state->matches('outer_parallel.region_b.working'))->toBeTrue();

    // Complete sub_region_1 — inner parallel NOT yet done (sub_region_2 still active)
    $state = $definition->transition(['type' => 'FINISH_SUB_1'], $state);
    expect($state->matches('outer_parallel.region_a.inner_parallel.sub_region_1.finished'))->toBeTrue();
    expect($state->matches('outer_parallel.region_a.inner_parallel.sub_region_2.active'))->toBeTrue();
    expect($state->matches('outer_parallel.region_b.working'))->toBeTrue();

    // Complete sub_region_2 — inner parallel @done fires → region_a transitions to region_a_done
    $state = $definition->transition(['type' => 'FINISH_SUB_2'], $state);
    expect($state->matches('outer_parallel.region_a.region_a_done'))->toBeTrue(
        'Inner parallel @done should fire when both sub-regions reach final, transitioning region_a to region_a_done'
    );
    // Outer parallel NOT yet done — region_b still working
    expect($state->matches('outer_parallel.region_b.working'))->toBeTrue();
    expect($state->matches('all_complete'))->toBeFalse(
        'Outer parallel @done should NOT fire while region_b is still active'
    );

    // Complete region_b — outer parallel @done fires → all_complete
    $state = $definition->transition(['type' => 'FINISH_B'], $state);
    expect($state->matches('all_complete'))->toBeTrue(
        'Outer parallel @done should fire when both outer regions (including cascade-completed region_a) are final'
    );
    expect($state->currentStateDefinition->type)->toBe(StateDefinitionType::FINAL);
});

test('nested parallel @done cascade works when outer region completes first', function (): void {
    // Same structure but region_b completes before the inner parallel finishes.
    // Verifies order-independence of the cascade.

    $definition = MachineDefinition::define([
        'id'      => 'nested_cascade_order',
        'initial' => 'outer_parallel',
        'states'  => [
            'outer_parallel' => [
                'type'   => 'parallel',
                '@done'  => 'all_complete',
                'states' => [
                    'region_a' => [
                        'initial' => 'inner_parallel',
                        'states'  => [
                            'inner_parallel' => [
                                'type'   => 'parallel',
                                '@done'  => 'region_a_done',
                                'states' => [
                                    'sub_region_1' => [
                                        'initial' => 'active',
                                        'states'  => [
                                            'active' => [
                                                'on' => ['FINISH_SUB_1' => 'finished'],
                                            ],
                                            'finished' => ['type' => 'final'],
                                        ],
                                    ],
                                    'sub_region_2' => [
                                        'initial' => 'active',
                                        'states'  => [
                                            'active' => [
                                                'on' => ['FINISH_SUB_2' => 'finished'],
                                            ],
                                            'finished' => ['type' => 'final'],
                                        ],
                                    ],
                                ],
                            ],
                            'region_a_done' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'working',
                        'states'  => [
                            'working' => [
                                'on' => ['FINISH_B' => 'done'],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'all_complete' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // Complete region_b first
    $state = $definition->transition(['type' => 'FINISH_B'], $state);
    expect($state->matches('outer_parallel.region_b.done'))->toBeTrue();
    expect($state->matches('all_complete'))->toBeFalse(
        'Outer @done must not fire while inner parallel in region_a is still active'
    );

    // Complete first inner sub-region
    $state = $definition->transition(['type' => 'FINISH_SUB_1'], $state);
    expect($state->matches('all_complete'))->toBeFalse();

    // Complete second inner sub-region → inner @done → region_a final → outer @done
    $state = $definition->transition(['type' => 'FINISH_SUB_2'], $state);
    expect($state->matches('all_complete'))->toBeTrue(
        'Outer @done should cascade: inner parallel done → region_a final → outer parallel done'
    );
});

test('nested parallel @done cascade runs actions at each level', function (): void {
    // Verifies that @done actions fire at both the inner and outer parallel levels.
    $actionLog = [];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'nested_cascade_actions',
            'initial' => 'outer_parallel',
            'states'  => [
                'outer_parallel' => [
                    'type'  => 'parallel',
                    '@done' => [
                        'target'  => 'all_complete',
                        'actions' => 'logOuterDoneAction',
                    ],
                    'states' => [
                        'region_a' => [
                            'initial' => 'inner_parallel',
                            'states'  => [
                                'inner_parallel' => [
                                    'type'  => 'parallel',
                                    '@done' => [
                                        'target'  => 'region_a_done',
                                        'actions' => 'logInnerDoneAction',
                                    ],
                                    'states' => [
                                        'sub_region_1' => [
                                            'initial' => 'active',
                                            'states'  => [
                                                'active' => [
                                                    'on' => ['FINISH_SUB_1' => 'finished'],
                                                ],
                                                'finished' => ['type' => 'final'],
                                            ],
                                        ],
                                        'sub_region_2' => [
                                            'initial' => 'active',
                                            'states'  => [
                                                'active' => [
                                                    'on' => ['FINISH_SUB_2' => 'finished'],
                                                ],
                                                'finished' => ['type' => 'final'],
                                            ],
                                        ],
                                    ],
                                ],
                                'region_a_done' => ['type' => 'final'],
                            ],
                        ],
                        'region_b' => [
                            'initial' => 'done',
                            'states'  => [
                                'done' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'all_complete' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'actions' => [
                'logInnerDoneAction' => function () use (&$actionLog): void {
                    $actionLog[] = 'inner_done';
                },
                'logOuterDoneAction' => function () use (&$actionLog): void {
                    $actionLog[] = 'outer_done';
                },
            ],
        ]
    );

    $state = $definition->getInitialState();

    // region_b starts already final. Complete inner parallel sub-regions.
    $state = $definition->transition(['type' => 'FINISH_SUB_1'], $state);
    expect($actionLog)->toBe([]);

    $state = $definition->transition(['type' => 'FINISH_SUB_2'], $state);

    // Both inner and outer @done should have fired in cascade order
    expect($actionLog)->toContain('inner_done');
    expect($actionLog)->toContain('outer_done');

    // Inner @done should fire before outer @done
    $innerIdx = array_search('inner_done', $actionLog, true);
    $outerIdx = array_search('outer_done', $actionLog, true);
    expect($innerIdx)->toBeLessThan($outerIdx);

    expect($state->matches('all_complete'))->toBeTrue();
});

test('partial inner parallel completion does not trigger outer @done', function (): void {
    // Only one of two inner sub-regions completes. Inner @done must NOT fire,
    // therefore outer @done must NOT fire even if the other outer region is final.

    $definition = MachineDefinition::define([
        'id'      => 'nested_partial',
        'initial' => 'outer_parallel',
        'states'  => [
            'outer_parallel' => [
                'type'   => 'parallel',
                '@done'  => 'all_complete',
                'states' => [
                    'region_a' => [
                        'initial' => 'inner_parallel',
                        'states'  => [
                            'inner_parallel' => [
                                'type'   => 'parallel',
                                '@done'  => 'region_a_done',
                                'states' => [
                                    'sub_region_1' => [
                                        'initial' => 'active',
                                        'states'  => [
                                            'active' => [
                                                'on' => ['FINISH_SUB_1' => 'finished'],
                                            ],
                                            'finished' => ['type' => 'final'],
                                        ],
                                    ],
                                    'sub_region_2' => [
                                        'initial' => 'active',
                                        'states'  => [
                                            'active' => [
                                                'on' => ['FINISH_SUB_2' => 'finished'],
                                            ],
                                            'finished' => ['type' => 'final'],
                                        ],
                                    ],
                                ],
                            ],
                            'region_a_done' => ['type' => 'final'],
                        ],
                    ],
                    'region_b' => [
                        'initial' => 'done',
                        'states'  => [
                            'done' => ['type' => 'final'],
                        ],
                    ],
                ],
            ],
            'all_complete' => ['type' => 'final'],
        ],
    ]);

    $state = $definition->getInitialState();

    // region_b is already final. Complete only ONE inner sub-region.
    $state = $definition->transition(['type' => 'FINISH_SUB_1'], $state);

    // Inner parallel NOT done yet → region_a NOT final → outer @done must NOT fire
    expect($state->matches('outer_parallel.region_a.inner_parallel.sub_region_1.finished'))->toBeTrue();
    expect($state->matches('outer_parallel.region_a.inner_parallel.sub_region_2.active'))->toBeTrue();
    expect($state->matches('outer_parallel.region_a.region_a_done'))->toBeFalse(
        'Inner @done should NOT fire when only one of two sub-regions is final'
    );
    expect($state->matches('all_complete'))->toBeFalse(
        'Outer @done should NOT fire when inner parallel is still incomplete'
    );
});
